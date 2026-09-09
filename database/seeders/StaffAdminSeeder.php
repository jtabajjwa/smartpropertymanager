<?php

namespace Database\Seeders;

use App\Models\StaffUser;
use Illuminate\Database\Seeder;

class StaffAdminSeeder extends Seeder
{
    public function run(): void
    {
        StaffUser::updateOrCreate(
            ['email' => 'joseph.tabajjwa@gmail.com'],
            [
                'name' => 'Joseph Tabajjwa',
                'password' => '12345678',
                'role' => 'admin',
                'phone' => '+256700000000',
                'is_active' => true,
                'must_change_password' => true,
            ]
        );
    }
}
