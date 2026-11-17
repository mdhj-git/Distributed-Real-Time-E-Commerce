<?php

namespace Modules\Notifications\Services\Email;

/**
 * The external email service, behind an interface so the worker never depends on a vendor.
 *
 * It addresses the user by id: Users exposes no Contract that returns an email address yet, and
 * Notifications must not import the User model. Resolving the address is the email service's job here.
 */
interface EmailSender
{
    /**
     * @throws \Throwable when the email service rejects the message or cannot be reached
     */
    public function send(int $userId, string $subject, string $body): void;
}
