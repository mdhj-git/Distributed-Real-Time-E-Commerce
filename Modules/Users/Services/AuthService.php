<?php

namespace Modules\Users\Services;

use Illuminate\Support\Facades\Hash;
use Modules\Users\Models\User;

class AuthService
{
    public function register(array $data): User
    {
        $user = new User();
        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->password = Hash::make($data['password']);

        // لا نأخذ الدور من مدخلات المستخدم.
        $user->role = 'customer';

        $user->save();

        return $user;
    }

    public function login(array $credentials): string|false
    {
        return auth('api')->attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
        ]);
    }

    public function logout(): void
    {
        auth('api')->logout();
    }

    public function refresh(): string
    {
        return auth('api')->refresh();
    }
}
