<?php

namespace App\Modules\Marketing\Notifications;

use App\Modules\Marketing\Enums\SupportTopic;
use App\Modules\Marketing\Models\SupportRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Emails a new support page message to the team inbox. */
class SupportRequestReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly SupportRequest $request) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $r = $this->request;
        $mail = (new MailMessage)
            ->subject("Support: {$r->topic->label()} — {$r->name}")
            ->line("From: {$r->name}")
            ->line('Email: '.($r->email ?? '—'))
            ->line('Phone: '.($r->phone ?? '—'))
            ->line('Matching account: '.($r->user ? "@{$r->user->handle}" : 'none found'))
            ->line('Message:')
            ->line($r->message);

        if ($r->topic === SupportTopic::AccountDeletion) {
            $mail->line('Confirm through the phone number or email on the account before deleting it.');
        }

        if ($r->email) {
            $mail->replyTo($r->email, $r->name);
        }

        return $mail->action('Open in admin', url('/admin/support-requests'));
    }
}
