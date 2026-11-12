<?php

namespace App\Console\Commands;

use App\Services\Alerts\Alerter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('alerts:test')]
#[Description('Send a test alert e-mail to B2B_ALERT_EMAIL')]
class AlertsTest extends Command
{
    public function handle(Alerter $alerter): int
    {
        $to = Alerter::recipients();
        if ($to === []) {
            $this->error('No recipient. Set B2B_ALERT_EMAIL in .env (comma-separate several addresses).');

            return self::FAILURE;
        }

        if (! $alerter->test()) {
            $this->error('Sending failed. Check the MAIL_* settings; the error is in the log.');

            return self::FAILURE;
        }

        $this->info('Test alert queued for '.implode(', ', $to).'. It is sent by the queue worker (Horizon).');
        if (config('mail.default') === 'log') {
            $this->warn('MAIL_MAILER=log: the e-mail is written to storage/logs/laravel.log instead of being sent.');
        }

        return self::SUCCESS;
    }
}
