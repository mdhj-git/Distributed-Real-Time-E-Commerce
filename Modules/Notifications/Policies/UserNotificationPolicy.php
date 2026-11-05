<?php

namespace Modules\Notifications\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Notifications\Models\UserNotification;

/**
 * Ownership only. The user is typed as Authenticatable so the Users module's model is never imported.
 * Not even the admin may touch another user's notification.
 */
class UserNotificationPolicy
{
    public function update(Authenticatable $user, UserNotification $notification): bool
    {
        return $notification->user_id === (int) $user->getAuthIdentifier();
    }
}
