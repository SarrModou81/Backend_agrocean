<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        // Vérifier si l'admin existe déjà
        if (!User::where('email', 'admin@agrocean.sn')->exists()) {
            User::create([
                'nom' => 'Admin',
                'prenom' => 'Système',
                'email' => 'admin@agrocean.sn',
                'password' => Hash::make('password'),
                'telephone' => '771234567',
                'role' => 'Administrateur',
                'is_active' => true
            ]);

            echo "✅ Utilisateur admin créé avec succès!\n";
        } else {
            echo "ℹ️ Utilisateur admin existe déjà.\n";
        }
    }
}
