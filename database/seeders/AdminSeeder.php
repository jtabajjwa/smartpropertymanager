<?php

namespace Database\Seeders;

use App\Models\Landlord;
use App\Models\User;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $landlord = Landlord::first() ?? Landlord::create([
            'name' => config('app.name', 'Default Landlord'),
            'contact_email' => 'default@smartpropertymanager.local',
            'contact_phone' => '0000000000',
            'status' => 'active',
            'subscription_status' => 'active',
        ]);

        User::updateOrCreate(
            ['email' => 'rhodanansubuga@gmail.com'],
            [
                'landlord_id' => $landlord->id,
                'name' => 'Nansubuga Rhoda',
                'password' => '12345678',
                'role' => 'admin',
                'phone' => '+256757389014',
                'email_verified_at' => now(),
                'is_active' => true,
                'must_change_password' => true,
            ]
        );
    }
}
