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
        User::firstOrCreate(
            ['email' => 'servicedesk@example.com'],
            [
                'name' => 'Service Desk Admin',
                'password' => Hash::make('password'),
                'role' => 'service_desk',
            ]
        );

        // Project Manager
        User::firstOrCreate(
            ['email' => 'pm@example.com'],
            [
                'name' => 'Project Manager Alpha',
                'password' => Hash::make('password'),
                'role' => 'project_manager',
            ]
        );

        // Programmer One
        User::firstOrCreate(
            ['email' => 'programmer@example.com'],
            [
                'name' => 'Programmer Dev One',
                'password' => Hash::make('password'),
                'role' => 'programmer',
            ]
        );

        // Programmer Two
        User::firstOrCreate(
            ['email' => 'programmer2@gmail.com'],
            [
                'name' => 'Programmer Dev Two',
                'password' => Hash::make('password'),
                'role' => 'programmer',
            ]
        );

        // Owner
        User::firstOrCreate(
            ['email' => 'owner@example.com'],
            [
                'name' => 'Company Owner',
                'password' => Hash::make('password'),
                'role' => 'owner',
            ]
        );

        // Client
        User::firstOrCreate(
            ['email' => 'client@example.com'],
            [
                'name' => 'Corporate Client',
                'password' => Hash::make('password'),
                'role' => 'client',
            ]
        );
    }
}
