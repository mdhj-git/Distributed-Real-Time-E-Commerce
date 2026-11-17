<?php

namespace Modules\Notifications\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Notifications\Services\Email\EmailSender;
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

    public function handle(EmailSender $sender): void
    {
        $sender->send($this->userId, $this->subject, $this->body);
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
