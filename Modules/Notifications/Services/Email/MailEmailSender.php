<?php

namespace Modules\Notifications\Services\Email;

use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Sends the email through Laravel's mailer, so the transport comes only from the standard MAIL_* variables
 * (MAIL_MAILER=log writes the message to storage/logs/laravel.log; MAIL_MAILER=smtp sends it for real).
 * Credentials live in .env, never in code.
 *
 * With NOTIFICATIONS_EMAIL_FAIL=true it fails on purpose, before anything is sent, which is how retries and the
 * dead letter queue are shown. The worker reads the setting once at start-up, so restart it after changing it:
 *   docker compose restart worker
 */
class MailEmailSender implements EmailSender
{
    public function send(string $to, string $subject, string $body): void
    {
        if ((bool) config('notifications.email_fail')) {
            throw new RuntimeException('Email service unavailable (simulated).');
        }

        Mail::raw($body, function (Message $message) use ($to, $subject): void {
            $message->to($to)->subject($subject);
        });
    }
}
