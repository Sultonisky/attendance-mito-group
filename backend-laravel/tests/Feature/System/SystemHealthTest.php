<?php

namespace Tests\Feature\System;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SystemHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_guest_cannot_view_systems_health(): void
    {
        $this->getJson('/api/v1/systems/health')
            ->assertUnauthorized();
    }

    public function test_admin_cannot_view_systems_health(): void
    {
        $admin = $this->makeUser('ADMIN');

        $this->actingAs($admin)
            ->getJson('/api/v1/systems/health')
            ->assertForbidden();
    }

    public function test_user_cannot_view_systems_health(): void
    {
        $user = $this->makeUser('USER');

        $this->actingAs($user)
            ->getJson('/api/v1/systems/health')
            ->assertForbidden();
    }

    public function test_super_admin_can_view_systems_health(): void
    {
        Http::fake(function () {
            return Http::response([
                'status' => 'ok',
                'service' => 'attendance-ai',
            ]);
        });

        $superAdmin = $this->makeUser('SUPER_ADMIN');

        $response = $this->actingAs($superAdmin)
            ->getJson('/api/v1/systems/health');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'overall_status',
                'refreshed_at',
                'services' => [
                    ['key', 'label', 'status', 'detail'],
                ],
                'runtime' => [
                    'environment',
                    'debug',
                    'laravel_version',
                    'php_version',
                    'timezone',
                    'maintenance',
                ],
            ],
        ]);

        $keys = collect($response->json('data.services'))->pluck('key')->all();
        $this->assertContains('laravel_api', $keys);
        $this->assertContains('database', $keys);
        $this->assertContains('ai_service', $keys);
    }
}
