<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['username' => 'rhoda'],
            [
                'username' => 'rhoda',
                'name' => 'Nansubuga Rhoda',
                'password' => '12345678',
                'role' => 'admin',
                'email' => 'rhodanansubuga@gmail.com',
                'phone' => '+256757389014',
                'is_active' => true,
                'must_change_password' => true,
            ]
        );
    }
}
