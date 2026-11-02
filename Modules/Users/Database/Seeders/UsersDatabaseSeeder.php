<?php

namespace Modules\Users\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\Users\Models\User;

class UsersDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'email' => 'demo.admin@shop.test',
                'name' => 'Demo Admin',
                'password' => 'password',
                'role' => 'admin',
            ],
            [
                'email' => 'demo.customer.a@shop.test',
                'name' => 'Demo Customer A',
                'password' => 'password',
                'role' => 'customer',
            ],
            [
                'email' => 'demo.customer.b@shop.test',
                'name' => 'Demo Customer B',
                'password' => 'password',
                'role' => 'customer',
            ],
        ];

        foreach ($users as $data) {
            $user = User::firstOrNew(['email' => $data['email']]);

            $user->name = $data['name'];
            $user->password = Hash::make($data['password']);
            $user->role = $data['role'];
            $user->save();
        }
    }
}
