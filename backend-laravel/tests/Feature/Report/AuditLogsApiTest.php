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

    /**
     * @return array<string, AuditLog>
     */
    private function seedSearchableLogs(): array
    {
        $employeeActor = User::factory()->create(['name' => 'Rina Kasir', 'email' => 'rina@example.test']);

        return [
            'user' => AuditLog::create([
                'actor_id' => $employeeActor->id,
                'action' => 'attendance.check_in',
                'auditable_type' => 'App\\Models\\AttendanceSession',
                'auditable_id' => 9001,
                'ip_address' => '203.0.113.50',
                'user_agent' => 'Mozilla/5.0 (Linux; Android 14) Chrome/140.0',
                'metadata' => ['session_id' => 9001],
            ]),
            'outsource' => AuditLog::create([
                'actor_id' => null,
                'action' => 'outsource.session.init',
                'auditable_type' => 'App\\Models\\Outsource',
                'auditable_id' => 42,
                'ip_address' => '198.51.100.20',
                'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)',
                'metadata' => [
                    'actor_kind' => 'outsource',
                    'outsource_id' => 42,
                    'outsource_name' => 'Ayu Outsource',
                    'outsource_code' => 'DM20260042',
                ],
            ]),
            'system' => AuditLog::create([
                'actor_id' => null,
                'action' => 'recap.generated',
                'auditable_type' => null,
                'auditable_id' => null,
                'ip_address' => null,
                'user_agent' => null,
                'metadata' => null,
            ]),
        ];
    }

    /**
     * @return list<int>
     */
    private function searchIds(User $admin, string $term): array
    {
        return collect(
            $this->actingAs($admin, 'sanctum')
                ->getJson('/api/v1/audit-logs?per_page=100&search='.urlencode($term))
                ->assertOk()
                ->json('data')
        )->pluck('id')->all();
    }

    public function test_search_covers_every_visible_table_field(): void
    {
        $logs = $this->seedSearchableLogs();
        $admin = $this->superAdmin();

        $cases = [
            'ATTENDANCE.CHECK' => 'user',
            'rina kasir' => 'user',
            'rina@example' => 'user',
            'AttendanceSession' => 'user',
            '203.0.113.50' => 'user',
            'Android' => 'user',
            'ayu outsource' => 'outsource',
            'dm20260042' => 'outsource',
            '198.51.100' => 'outsource',
            'iPhone' => 'outsource',
            'recap.generated' => 'system',
        ];

        foreach ($cases as $term => $expected) {
            $ids = $this->searchIds($admin, $term);

            $this->assertContains($logs[$expected]->id, $ids, "Search '{$term}' should find the {$expected} log.");
            foreach ($logs as $key => $log) {
                if ($key !== $expected) {
                    $this->assertNotContains($log->id, $ids, "Search '{$term}' should not find the {$key} log.");
                }
            }
        }
    }

    public function test_search_by_numeric_id_matches_audit_id_and_resource_id(): void
    {
        $logs = $this->seedSearchableLogs();
        $admin = $this->superAdmin();

        $this->assertContains($logs['system']->id, $this->searchIds($admin, (string) $logs['system']->id));
        $this->assertContains($logs['user']->id, $this->searchIds($admin, '9001'));
    }

    public function test_date_range_uses_attendance_timezone_calendar_days(): void
    {
        // 2026-09-26 17:30 UTC = 2026-09-27 00:30 WIB; 2026-09-27 16:59 UTC = 23:59 WIB.
        $earlyWib = AuditLog::forceCreate(['action' => 'tz.early', 'created_at' => '2026-09-26 17:30:00']);
        $lateWib = AuditLog::forceCreate(['action' => 'tz.late', 'created_at' => '2026-09-27 16:59:00']);
        $nextWibDay = AuditLog::forceCreate(['action' => 'tz.next', 'created_at' => '2026-09-27 17:00:00']);
        $admin = $this->superAdmin();

        $ids = collect(
            $this->actingAs($admin, 'sanctum')
                ->getJson('/api/v1/audit-logs?from=2026-09-27&to=2026-09-27&per_page=100')
                ->assertOk()
                ->json('data')
        )->pluck('id')->all();

        $this->assertContains($earlyWib->id, $ids);
        $this->assertContains($lateWib->id, $ids);
        $this->assertNotContains($nextWibDay->id, $ids);

        $previousDay = collect(
            $this->actingAs($admin, 'sanctum')
                ->getJson('/api/v1/audit-logs?from=2026-09-26&to=2026-09-26&per_page=100')
                ->assertOk()
                ->json('data')
        )->pluck('id')->all();

        $this->assertNotContains($earlyWib->id, $previousDay);
    }

    public function test_filters_by_actor_kind(): void
    {
        $logs = $this->seedSearchableLogs();
        $admin = $this->superAdmin();

        foreach (['user', 'outsource', 'system'] as $kind) {
            $ids = collect(
                $this->actingAs($admin, 'sanctum')
                    ->getJson("/api/v1/audit-logs?per_page=100&actor_kind={$kind}")
                    ->assertOk()
                    ->json('data')
            )->pluck('id')->all();

            foreach ($logs as $key => $log) {
                $key === $kind
                    ? $this->assertContains($log->id, $ids, "actor_kind={$kind} should include the {$key} log.")
                    : $this->assertNotContains($log->id, $ids, "actor_kind={$kind} should exclude the {$key} log.");
            }
        }
    }

    public function test_rejects_unknown_actor_kind(): void
    {
        $this->actingAs($this->superAdmin(), 'sanctum')
            ->getJson('/api/v1/audit-logs?actor_kind=robot')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('actor_kind');
    }

    public function test_filters_by_exact_action(): void
    {
        $logs = $this->seedSearchableLogs();

        $ids = collect(
            $this->actingAs($this->superAdmin(), 'sanctum')
                ->getJson('/api/v1/audit-logs?per_page=100&action=outsource.session.init')
                ->assertOk()
                ->json('data')
        )->pluck('id')->all();

        $this->assertSame([$logs['outsource']->id], $ids);
    }

    public function test_actions_endpoint_lists_distinct_sorted_actions(): void
    {
        $this->seedSearchableLogs();
        AuditLog::create(['action' => 'outsource.session.init']);

        $actions = $this->actingAs($this->superAdmin(), 'sanctum')
            ->getJson('/api/v1/audit-logs/actions')
            ->assertOk()
            ->assertJson(['success' => true])
            ->json('data');

        $this->assertContains('attendance.check_in', $actions);
        $this->assertContains('outsource.session.init', $actions);
        $this->assertContains('recap.generated', $actions);
        $this->assertSame(array_values(array_unique($actions)), $actions);

        $sorted = $actions;
        sort($sorted);
        $this->assertSame($sorted, $actions);
    }

    public function test_actions_endpoint_requires_audit_view_permission(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/audit-logs/actions')
            ->assertForbidden();
    }

    public function test_search_system_matches_actorless_non_outsource_logs(): void
    {
        $logs = $this->seedSearchableLogs();

        $ids = $this->searchIds($this->superAdmin(), 'system');

        $this->assertContains($logs['system']->id, $ids);
        $this->assertNotContains($logs['outsource']->id, $ids);
        $this->assertNotContains($logs['user']->id, $ids);
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
