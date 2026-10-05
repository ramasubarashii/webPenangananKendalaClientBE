<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_successful_returns_token_and_user_data()
    {
        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => Hash::make('secret123'),
            'role' => 'client',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'john@example.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'access_token',
                'token_type',
                'user' => ['id', 'name', 'email', 'role']
            ]);
    }

    public function test_login_fails_with_invalid_credentials()
    {
        User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => Hash::make('secret123'),
            'role' => 'client',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'john@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_inactive_user_cannot_login()
    {
        User::create([
            'name' => 'Blocked User',
            'email' => 'blocked@example.com',
            'password' => Hash::make('secret123'),
            'role' => 'client',
            'is_active' => false,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'blocked@example.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_authenticated_user_can_get_me_profile()
    {
        $user = User::create([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => Hash::make('password'),
            'role' => 'service_desk',
        ]);

        $response = $this->actingAs($user)->getJson('/api/me');

        $response->assertStatus(200)
            ->assertJsonPath('email', 'jane@example.com')
            ->assertJsonPath('role', 'service_desk');
    }

    public function test_authenticated_user_can_update_profile()
    {
        $user = User::create([
            'name' => 'Old Name',
            'email' => 'old@example.com',
            'password' => Hash::make('password'),
            'role' => 'programmer',
        ]);

        $response = $this->actingAs($user)->putJson('/api/profile', [
            'name' => 'New Name',
            'email' => 'new@example.com',
            'current_password' => 'password',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('user.name', 'New Name');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'New Name',
            'email' => 'new@example.com',
        ]);
    }

    public function test_authenticated_user_can_change_password()
    {
        $user = User::create([
            'name' => 'User Password',
            'email' => 'pass@example.com',
            'password' => Hash::make('oldpassword'),
            'role' => 'client',
        ]);

        $response = $this->actingAs($user)->putJson('/api/profile/password', [
            'current_password' => 'oldpassword',
            'new_password' => 'newsecret123',
            'new_password_confirmation' => 'newsecret123',
        ]);

        $response->assertStatus(200);
        $this->assertTrue(Hash::check('newsecret123', $user->fresh()->password));
    }
}
