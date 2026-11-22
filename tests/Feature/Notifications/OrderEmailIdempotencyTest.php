<?php

namespace Tests\Feature\Notifications;

use Illuminate\Support\Facades\Cache;
use Modules\Notifications\Jobs\SendOrderEmailJob;
use Modules\Notifications\Services\Email\EmailSender;
use Modules\Users\Contracts\UserDirectory;
use RuntimeException;
use Tests\TestCase;

class OrderEmailIdempotencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_a_duplicate_delivery_sends_the_email_only_once(): void
    {
        $sender = $this->mock(EmailSender::class);
        $sender->shouldReceive('send')->once()
            ->with('buyer@example.test', 'Order #7 received', 'We received your order #7.');

        $users = $this->mock(UserDirectory::class);
        $users->shouldReceive('emailFor')->once()->with(3)->andReturn('buyer@example.test');

        $job = new SendOrderEmailJob(3, 'Order #7 received', 'We received your order #7.', 'order.7.placed');

        // r5: two workers can receive the same message (at-least-once delivery).
        // The second one must short-circuit before even looking up the address.
        $job->handle($sender, $users);
        $job->handle($sender, $users);
    }

    public function test_a_failed_send_releases_the_lock_so_the_retry_can_send(): void
    {
        $calls = 0;
        $sender = $this->mock(EmailSender::class);
        $sender->shouldReceive('send')->twice()->andReturnUsing(function () use (&$calls): void {
            $calls++;

            if ($calls === 1) {
                throw new RuntimeException('SMTP is down');
            }
        });

        $users = $this->mock(UserDirectory::class);
        $users->shouldReceive('emailFor')->twice()->with(3)->andReturn('buyer@example.test');

        $job = new SendOrderEmailJob(3, 'Order #7 received', 'We received your order #7.', 'order.7.placed');

        try {
            $job->handle($sender, $users);
            $this->fail('The first attempt should have thrown.');
        } catch (RuntimeException) {
            // expected: the lock must have been released
        }

        $job->handle($sender, $users);

        $this->assertSame(2, $calls);
    }
}
