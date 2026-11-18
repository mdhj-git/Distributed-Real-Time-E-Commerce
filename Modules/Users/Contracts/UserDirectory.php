<?php

namespace Modules\Users\Contracts;

/**
 * What other modules may ask Users about a person, without ever importing the User model.
 *
 * Notifications uses it to find the address of an order's owner at the moment an email is sent.
 */
interface UserDirectory
{
    /**
     * The email address of the user, or null when the user does not exist or has no address.
     */
    public function emailFor(int $userId): ?string;
}
