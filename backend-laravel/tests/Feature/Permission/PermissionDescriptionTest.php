<?php

namespace Tests\Feature\Permission;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionDescriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_permissions_have_descriptions(): void
    {
        $user = User::factory()->create();
        $user->assignRole('SUPER_ADMIN');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/permissions')
            ->assertOk();

        $permissions = $response->json('data');
        $this->assertNotEmpty($permissions);

        foreach ($permissions as $permission) {
            $this->assertIsString($permission['name']);
            $this->assertNotNull($permission['description'], "Missing description for permission: {$permission['name']}");
        }
    }
}
