<?php

namespace App\Console\Commands;

use App\Models\StaffUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Creates a staff admin on a new server, asking for the details (the password
 * is typed hidden). Used instead of the seeders, which hold dev accounts.
 */
class CreateStaffAdmin extends Command
{
    protected $signature = 'staff:create-admin {--name=} {--email=} {--phone=}';

    protected $description = 'Create a Primora staff admin (asks for the password)';

    public function handle(): int
    {
        $data = [
            'name' => $this->option('name') ?: $this->ask('Full name'),
            'email' => strtolower(trim((string) ($this->option('email') ?: $this->ask('Email')))),
            'phone' => $this->option('phone') ?: $this->ask('Phone (optional)'),
            'password' => $this->secret('Password (at least 10 characters)'),
        ];
        $data['password_confirmation'] = $this->secret('Password again');

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:staff_users,email'],
            'phone' => ['nullable', 'string', 'max:30', 'unique:staff_users,phone'],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        StaffUser::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?: null,
            'password' => $data['password'],
            'role' => 'admin',
            'is_active' => true,
            'must_change_password' => false,
        ]);

        $this->info("Staff admin {$data['email']} created. Sign in at ".url('/staff/login'));

        return self::SUCCESS;
    }
}
