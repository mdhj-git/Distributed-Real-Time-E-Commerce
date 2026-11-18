<?php

namespace Modules\Users\Services;

use Modules\Users\Contracts\UserDirectory;
use Modules\Users\Models\User;

class EloquentUserDirectory implements UserDirectory
{
    public function emailFor(int $userId): ?string
    {
        $email = User::query()->whereKey($userId)->value('email');

        if (! is_string($email)) {
            return null;
        }

        $email = trim($email);

        return $email === '' ? null : $email;
    }
}
