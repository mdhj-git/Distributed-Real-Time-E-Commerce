<?php

namespace Modules\Notifications\Services\Email;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Stand-in for the external email service: it writes a log line instead of sending a real email.
 *
 * With NOTIFICATIONS_EMAIL_FAIL=true it fails on purpose, which is how the dead letter queue is shown.
 * The worker reads the setting once at start-up, so restart it after changing the value:
 *   docker compose restart worker
 */
class LogEmailSender implements EmailSender
{
    public function send(int $userId, string $subject, string $body): void
    {
        if ((bool) config('notifications.email_fail')) {
            throw new RuntimeException('Email service unavailable (simulated).');
        }

        Log::info('Email sent.', [
            'user_id' => $userId,
            'subject' => $subject,
        ]);
    }
}
