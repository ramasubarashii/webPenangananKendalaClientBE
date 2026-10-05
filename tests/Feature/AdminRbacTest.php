<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminRbacTest extends TestCase
{
    use RefreshDatabase;

    private $admin;
    private $nonAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'System Admin',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->nonAdmin = User::create([
            'name' => 'Service Desk',
            'email' => 'sd@test.com',
            'password' => bcrypt('password'),
            'role' => 'service_desk',
            'is_active' => true,
        ]);
    }

    public function test_non_admin_cannot_access_admin_routes()
    {
        $response = $this->actingAs($this->nonAdmin)->getJson('/api/admin/users');
        $response->assertStatus(403);
    }

    public function test_admin_can_fetch_users_list()
    {
        $response = $this->actingAs($this->admin)->getJson('/api/admin/users');

        $response->assertStatus(200)
            ->assertJsonCount(2);
    }

    public function test_admin_can_create_new_user()
    {
        $response = $this->actingAs($this->admin)->postJson('/api/admin/users', [
            'name' => 'New Programmer',
            'email' => 'newprog@test.com',
            'password' => 'secret123',
            'role' => 'programmer',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => 'newprog@test.com',
            'role' => 'programmer',
        ]);

        $this->assertDatabaseHas('admin_activity_logs', [
            'admin_id' => $this->admin->id,
            'action' => 'create_user',
        ]);
    }

    public function test_admin_can_toggle_user_active_status()
    {
        $targetUser = User::create([
            'name' => 'Target User',
            'email' => 'target@test.com',
            'password' => bcrypt('password'),
            'role' => 'client',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->patchJson("/api/admin/users/{$targetUser->id}/toggle-active");

        $response->assertStatus(200);
        $this->assertFalse((bool) $targetUser->fresh()->is_active);

        $this->assertDatabaseHas('admin_activity_logs', [
            'admin_id' => $this->admin->id,
            'action' => 'deactivate_user',
        ]);
    }

    public function test_admin_can_reset_user_password()
    {
        $targetUser = User::create([
            'name' => 'Reset Target',
            'email' => 'resetme@test.com',
            'password' => bcrypt('oldpass'),
            'role' => 'programmer',
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$targetUser->id}/reset-password", [
                'new_password' => 'newadminsetpassword',
            ]);

        $response->assertStatus(200);
        $this->assertTrue(Hash::check('newadminsetpassword', $targetUser->fresh()->password));
    }
}
