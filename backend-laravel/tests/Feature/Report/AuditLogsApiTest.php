<?php

namespace Tests\Feature\Report;

use App\Models\AuditLog;
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

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('SUPER_ADMIN');

        return $user;
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

    public function test_admin_cannot_list_audit_logs(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/audit-logs?per_page=10')
            ->assertForbidden();
    }

    public function test_super_admin_can_list_audit_logs(): void
    {
        $this->actingAs($this->superAdmin(), 'sanctum')
            ->getJson('/api/v1/audit-logs?per_page=10')
            ->assertOk()
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => ['id', 'action', 'actor', 'auditable_type', 'auditable_id', 'old_values', 'new_values', 'ip_address', 'user_agent', 'metadata', 'created_at'],
                ],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    }

    public function test_filters_by_date_range(): void
    {
        $this->actingAs($this->superAdmin(), 'sanctum')
            ->getJson('/api/v1/audit-logs?from=2026-01-01&to=2026-12-31&per_page=10')
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_filters_by_search(): void
    {
        $this->actingAs($this->superAdmin(), 'sanctum')
            ->getJson('/api/v1/audit-logs?search=updated&per_page=10')
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_outsource_metadata_actor_is_exposed_in_list(): void
    {
        AuditLog::create([
            'actor_id' => null,
            'action' => 'outsource.session.init',
            'auditable_type' => null,
            'auditable_id' => null,
            'old_values' => null,
            'new_values' => ['store_id' => 1],
            'ip_address' => '198.51.100.20',
            'user_agent' => 'Mozilla/5.0',
            'metadata' => [
                'actor_kind' => 'outsource',
                'outsource_id' => 42,
                'outsource_name' => 'Ayu Outsource',
                'outsource_code' => 'DM20260042',
            ],
        ]);

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->getJson('/api/v1/audit-logs?per_page=10&action=outsource.session.init')
            ->assertOk()
            ->assertJsonPath('data.0.actor.kind', 'outsource')
            ->assertJsonPath('data.0.actor.name', 'Ayu Outsource')
            ->assertJsonPath('data.0.actor.email', 'DM20260042')
            ->assertJsonPath('data.0.actor.id', 42)
            ->assertJsonPath('data.0.ip_address', '198.51.100.20')
            ->assertJsonPath('data.0.user_agent', 'Mozilla/5.0')
            ->assertJsonPath('data.0.new_values.store_id', 1);
    }
}
