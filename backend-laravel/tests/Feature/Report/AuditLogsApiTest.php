<?php

namespace Tests\Feature\Report;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('ADMIN');

        return $user;
    }

    public function test_requires_audit_view_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('USER');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/audit-logs')
            ->assertForbidden();
    }

    public function test_admin_can_list_audit_logs(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/audit-logs?per_page=10')
            ->assertOk()
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => ['id', 'action', 'actor', 'auditable_type', 'auditable_id', 'ip_address', 'metadata', 'created_at'],
                ],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    }

    public function test_filters_by_date_range(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/audit-logs?from=2026-01-01&to=2026-12-31&per_page=10')
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_filters_by_search(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/audit-logs?search=updated&per_page=10')
            ->assertOk()
            ->assertJson(['success' => true]);
    }
}
