<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UserProfileSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasColumn('users', 'first_name')) {
            $this->command?->warn('Migration du profil utilisateur non appliquée.');

            return;
        }

        $profiles = [
            'admin.test' => [
                'first_name' => 'Adama',
                'last_name' => 'Coulibaly',
                'email' => 'adama.coulibaly@sentinelle-cif.local',
                'job_title' => 'Administrateur de la plateforme',
            ],
            'analyste.demo' => [
                'first_name' => 'Hawa',
                'last_name' => 'Thiama',
                'email' => 'hawa.thiama@sentinelle-cif.local',
                'job_title' => 'Analyste conformité LBC-FT',
            ],
            'superviseur.demo' => [
                'first_name' => 'Boubacar',
                'last_name' => 'Traoré',
                'email' => 'boubacar.traore@sentinelle-cif.local',
                'job_title' => 'Superviseur conformité',
            ],
            'agent.bamako.01' => [
                'first_name' => 'Aminata',
                'last_name' => 'Diarra',
                'email' => 'aminata.diarra@sentinelle-cif.local',
                'job_title' => 'Agent opérationnel',
            ],
            'agent.bamako.02' => [
                'first_name' => 'Oumar',
                'last_name' => 'Keïta',
                'email' => 'oumar.keita@sentinelle-cif.local',
                'job_title' => 'Agent opérationnel',
            ],
            'agent.segou.01' => [
                'first_name' => 'Fatoumata',
                'last_name' => 'Coulibaly',
                'email' => 'fatoumata.coulibaly@sentinelle-cif.local',
                'job_title' => 'Agent opérationnel',
            ],
        ];

        foreach ($profiles as $username => $profile) {
            DB::table('users')
                ->where('username', $username)
                ->update($profile + ['profile_updated_at' => now()]);
        }
    }
}
