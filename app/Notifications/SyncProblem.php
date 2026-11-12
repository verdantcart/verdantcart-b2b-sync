<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** One e-mail about one problem that needs a person: what happened, the facts, and where to fix it. */
class SyncProblem extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param array<string, string> $details label => value */
    public function __construct(
        public readonly string $subject,
        public readonly string $summary,
        public readonly array $details,
        public readonly string $actionText,
        public readonly string $actionUrl,
        public readonly string $nextStep,
    ) {
        $this->onQueue('default');
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('[B2B Sync] '.$this->subject)
            ->greeting($this->subject)
            ->line($this->summary);

        foreach ($this->details as $label => $value) {
            $mail->line("**{$label}:** {$value}");
        }

        return $mail
            ->action($this->actionText, $this->actionUrl)
            ->line($this->nextStep)
            ->salutation('VerdantCart B2B Sync');
    }
}
