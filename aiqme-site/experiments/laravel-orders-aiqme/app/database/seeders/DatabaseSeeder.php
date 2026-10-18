<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['buyer', 'admin'] as $name) {
            $user = User::firstOrCreate(['email' => $name.'@example.test'], ['name' => ucfirst($name), 'password' => 'local-probe-only']);
            $user->forceFill(['is_admin' => $name === 'admin'])->save();
        }
    }
}
