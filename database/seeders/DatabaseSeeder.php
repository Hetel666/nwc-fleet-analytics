<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (filled(env('ADMIN_EMAIL')) && filled(env('ADMIN_PASSWORD'))) {
            User::updateOrCreate(
                ['email' => env('ADMIN_EMAIL')],
                [
                    'name' => env('ADMIN_NAME', 'Administrator'),
                    'password' => Hash::make(env('ADMIN_PASSWORD')),
                    'role' => 'admin',
                ]
            );
        }

        if (config('fleet.demo.seed')) {
            $this->call(DemoSeeder::class);
        }
    }
}
