<?php

namespace Modules\Users\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\Users\Contracts\UserRole;
use Modules\Users\Models\User;

class UsersDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'email'    => 'admin@gmail.com',
                'name'     => 'Admin',
                'password' => 'password',
                'role'     => UserRole::Admin,
            ],
            [
                'email'    => 'customer.a@gmail.com',
                'name'     => 'Customer A',
                'password' => 'password',
                'role'     => UserRole::Customer,
            ],
            [
                'email'    => 'customer.b@gmail.com',
                'name'     => 'Customer B',
                'password' => 'password',
                'role'     => UserRole::Customer,
            ],
        ];

        foreach ($users as $data) {
            User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'name'     => $data['name'],
                    'password' => Hash::make($data['password']),
                    'role'     => $data['role'],
                ]
            );
        }
    }
}
