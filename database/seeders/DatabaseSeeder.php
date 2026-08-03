<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Service Desk
        User::create([
            'name' => 'Service Desk Admin',
            'email' => 'servicedesk@example.com',
            'password' => Hash::make('password'),
            'role' => 'service_desk',
        ]);

        // Project Manager
        User::create([
            'name' => 'Project Manager Alpha',
            'email' => 'pm@example.com',
            'password' => Hash::make('password'),
            'role' => 'project_manager',
        ]);

        // Programmer
        User::create([
            'name' => 'Programmer Dev One',
            'email' => 'programmer@example.com',
            'password' => Hash::make('password'),
            'role' => 'programmer',
        ]);

        // Owner
        User::create([
            'name' => 'Company Owner',
            'email' => 'owner@example.com',
            'password' => Hash::make('password'),
            'role' => 'owner',
        ]);
    }
}
