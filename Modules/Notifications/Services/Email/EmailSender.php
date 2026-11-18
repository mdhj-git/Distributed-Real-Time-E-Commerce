<?php

namespace Modules\Notifications\Services\Email;

/**
 * The external email service, behind an interface so the worker never depends on a vendor.
 *
 * It takes the address itself: the job resolves it from the user id at send time, through the Users module's
 * UserDirectory contract, so Notifications never imports the User model.
 */
interface EmailSender
{
    /**
     * @throws \Throwable when the email service rejects the message or cannot be reached
     */
    public function send(string $to, string $subject, string $body): void;
}
