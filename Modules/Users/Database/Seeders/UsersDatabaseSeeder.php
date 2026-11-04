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
                'email'    => 'demo.admin@shop.test',
                'name'     => 'Demo Admin',
                'password' => 'password',
                'role'     => UserRole::Admin,
            ],
            [
                'email'    => 'demo.customer.a@shop.test',
                'name'     => 'Demo Customer A',
                'password' => 'password',
                'role'     => UserRole::Customer,
            ],
            [
                'email'    => 'demo.customer.b@shop.test',
                'name'     => 'Demo Customer B',
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