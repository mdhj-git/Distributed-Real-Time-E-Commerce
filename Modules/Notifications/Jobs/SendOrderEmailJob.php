<?php

namespace Modules\Notifications\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Notifications\Services\Email\EmailSender;
use Modules\Users\Contracts\UserDirectory;
use Throwable;

class SendOrderEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(
        public readonly int $userId,
        public readonly string $subject,
        public readonly string $body,
    ) {
        $this->onQueue('orders');
    }

    public function handle(EmailSender $sender, UserDirectory $users): void
    {
        // The address is looked up now, when the worker sends, not when the order was placed.
        $address = $users->emailFor($this->userId);

        if ($address === null) {
            // Retrying cannot help: there is nobody to write to. The job ends here and is acknowledged.
            Log::warning('Order email skipped: the user does not exist or has no email address.', [
                'user_id' => $this->userId,
                'subject' => $this->subject,
            ]);

            return;
        }

        $sender->send($address, $this->subject, $this->body);

        // The address is deliberately not logged.
        Log::info('Email sent.', [
            'user_id' => $this->userId,
            'subject' => $this->subject,
        ]);
    }

    /** Runs once, after the last attempt failed. */
    public function failed(Throwable $e): void
    {
        Log::error('Order email failed after all attempts.', [
            'user_id' => $this->userId,
            'subject' => $this->subject,
            'error' => $e->getMessage(),
        ]);
    }
}
