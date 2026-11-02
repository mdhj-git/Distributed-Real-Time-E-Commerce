<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        foreach (['Users', 'Products', 'Orders', 'Notifications'] as $module) {
            $seeder = "Modules\\{$module}\\Database\\Seeders\\{$module}DatabaseSeeder";

            if (class_exists($seeder)) {
                $this->call($seeder);
            }
        }
    }
}
