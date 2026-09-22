<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class TestUserSeeder extends Seeder
{
    public function run(): void
    {
        if (! DB::table('users')->where('username', 'admin.test')->exists()) {
            DB::table('users')->insert([
                'username' => 'admin.test',
                'agency_id' => null,
                'role_id' => null,
                'password_hash' => Hash::make('DigiAML@2026'),
                'created_at' => now(),
            ]);
        }

        $this->command->info('Utilisateur de test disponible.');
        $this->command->info('Username : admin.test');
        $this->command->info('Password : DigiAML@2026');
    }
}
