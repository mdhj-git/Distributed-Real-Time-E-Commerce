<?php

namespace Modules\Users\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\Users\Contracts\UserRole;
use Modules\Users\Models\User;

class AuthService
{
    public function register(array $data): User
    {
        $user           = new User();
        $user->name     = $data['name'];
        $user->email    = $data['email'];
        $user->password = Hash::make($data['password']);
        $user->role     = UserRole::Customer;

        try {
            $user->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'email' => ['The email has already been taken.'],
            ]);
        }

        return $user;
    }

    public function login(array $credentials): string|false
    {
        return auth('api')->attempt([
            'email'    => $credentials['email'],
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