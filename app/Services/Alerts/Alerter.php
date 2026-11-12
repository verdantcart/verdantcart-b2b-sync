<?php

namespace App\Services\Alerts;

use App\Models\OrderLog;
use App\Models\ProductSku;
use App\Models\SyncJob;
use App\Notifications\SyncProblem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * E-mails a person when the sync needs one: a job that gave up, or an order charged at a
 * different price than the B2B price.
 *
 * - Nobody configured (B2B_ALERT_EMAIL empty): nothing is sent.
 * - Each problem is mailed once, however many times the code reports it.
 * - At most `max_per_hour` mails, so a bad night cannot flood the inbox.
 * - Never throws: a broken mail setup must not stop orders from syncing.
 */
class Alerter
{
    public const RATE_KEY = 'b2b-alerts';

    public function syncGaveUp(SyncJob $job): bool
    {
        $job->loadMissing('syncable');
        $subject = $job->syncable;
        $dead = $job->status === SyncJob::STATUS_DEAD;

        $details = array_filter([
            'Problem' => (string) $job->last_error,
            'Attempts' => $dead ? "{$job->attempts}, no more automatic retries" : "{$job->attempts}, will not be retried automatically",
            'Customer' => $subject instanceof OrderLog ? ($subject->customer?->company_name ?? 'Guest checkout') : null,
            'Sync job' => "#{$job->id} ({$job->type})",
        ]);

        [$title, $what] = match (true) {
            $subject instanceof OrderLog => [
                "Order #{$subject->woo_order_id} could not be sent to Fortnox",
                'The order is safe in WooCommerce and in B2B Sync, but it is not in Fortnox yet.',
            ],
            $subject instanceof ProductSku => [
                "Stock for {$subject->sku} could not be updated in WooCommerce",
                'The shop may show the wrong stock for this product until it is fixed.',
            ],
            default => ["Sync job #{$job->id} gave up", 'A background job stopped after failing.'],
        };

        return $this->send("job:{$job->id}:{$job->attempts}:{$job->status}", new SyncProblem(
            subject: $title,
            summary: $what,
            details: $details,
            actionText: 'Open failed jobs',
            actionUrl: route('sync-jobs.index'),
            nextStep: 'Fix the cause, then click Retry on the failed-jobs page. Retrying never creates a second Fortnox order.',
        ));
    }

    public function priceDifference(OrderLog $order): bool
    {
        if (! $order->hasPriceDifference()) {
            return false;
        }

        $lines = collect($order->priced_lines ?? [])
            ->filter(fn (array $line) => ($line['difference'] ?? '0.00') !== '0.00')
            ->map(fn (array $line) => "{$line['sku']}: shop {$line['woo_line_total']}, B2B {$line['line_total']}")
            ->implode('; ');
        $amount = ($order->price_difference > 0 ? '+' : '').$order->price_difference.' '.$order->currency;

        return $this->send("price:{$order->id}", new SyncProblem(
            subject: "Order #{$order->woo_order_id} was charged {$amount} off the B2B price",
            summary: 'The shop charged a different amount than the agreed B2B price, for example because B2B Sync was unreachable at checkout. Fortnox got the B2B price.',
            details: [
                'Customer' => $order->customer?->company_name ?? 'Guest checkout',
                'Difference' => $amount.' (B2B minus what the shop charged)',
                'Lines' => $lines,
            ],
            actionText: 'Open the dashboard',
            actionUrl: url('/'),
            nextStep: 'Decide whether the customer should be refunded or invoiced for the difference.',
        ));
    }

    /** `php artisan alerts:test`: proves the mail setup works, skipping de-duplication. */
    public function test(): bool
    {
        return $this->send('test:'.now()->timestamp.':'.random_int(1000, 9999), new SyncProblem(
            subject: 'Test alert',
            summary: 'If you can read this, B2B Sync can e-mail you when an order needs attention.',
            details: ['Sent from' => (string) config('app.url'), 'Environment' => app()->environment()],
            actionText: 'Open the dashboard',
            actionUrl: url('/'),
            nextStep: 'No action needed.',
        ), countTowardsLimit: false);
    }

    /** @return list<string> */
    public static function recipients(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) config('b2bsync.alerts.mail_to')))));
    }

    private function send(string $key, SyncProblem $notification, bool $countTowardsLimit = true): bool
    {
        $to = self::recipients();
        if ($to === []) {
            return false;
        }

        try {
            if (! Cache::add('b2b-alert:'.$key, true, now()->addDay())) {
                return false; // already mailed about this one
            }

            if ($countTowardsLimit) {
                $max = max(1, (int) config('b2bsync.alerts.max_per_hour', 20));
                if (RateLimiter::tooManyAttempts(self::RATE_KEY, $max)) {
                    Log::warning('Alert e-mail suppressed: hourly limit reached', ['subject' => $notification->subject, 'limit' => $max]);

                    return false;
                }
                RateLimiter::hit(self::RATE_KEY, 3600);
            }

            Notification::route('mail', $to)->notify($notification);

            return true;
        } catch (Throwable $e) {
            Log::error('Alert e-mail could not be sent', ['subject' => $notification->subject, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
