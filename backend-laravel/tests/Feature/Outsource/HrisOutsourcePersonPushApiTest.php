<?php

namespace Tests\Feature\Outsource;

use App\Models\AuditLog;
use App\Models\Outsource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class HrisOutsourcePersonPushApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-attendance-outsource-push-token';
    private const URL = '/api/v1/integrations/hris/outsource-persons';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.hris.outsource_push_api_token' => self::TOKEN]);
    }

    public function test_new_hris_person_is_created_inactive_without_cabang_and_with_default_pin(): void
    {
        $this->withToken(self::TOKEN)
            ->postJson(self::URL, [
                'people' => [['outsource_id' => 'dm20260135', 'full_name' => ' Pekerja Baru ']],
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.outsource_id', 'DM20260135')
            ->assertJsonPath('data.0.status', 'created')
            ->assertJsonPath('meta.created', 1);

        $person = Outsource::query()->where('outsource_code', 'DM20260135')->firstOrFail();
        $this->assertSame('Pekerja Baru', $person->name);
        $this->assertSame('inactive', $person->status);
        $this->assertTrue(Hash::check(Outsource::DEFAULT_LOGIN_PIN, $person->password));
        $this->assertSame(0, $person->stores()->count());

        $audit = AuditLog::query()->where('action', 'outsource_person.created_from_hris')->firstOrFail();
        $this->assertNull($audit->actor_id);
        $this->assertSame($person->id, (int) $audit->auditable_id);
    }

    public function test_new_hris_person_without_assignments_is_available_in_search_and_pagination(): void
    {
        $this->withToken(self::TOKEN)
            ->postJson(self::URL, [
                'people' => [['outsource_id' => 'DM20260135', 'full_name' => 'Nur Aisyah']],
            ])
            ->assertOk();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('USER');
        $user->givePermissionTo('outsource_person.view');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/outsource-persons?search=Nur&status=inactive&per_page=10&page=1')
            ->assertOk()
            ->assertJsonPath('data.0.outsource_code', 'DM20260135')
            ->assertJsonPath('data.0.name', 'Nur Aisyah')
            ->assertJsonPath('data.0.stores', [])
            ->assertJsonPath('data.0.store_ids', [])
            ->assertJsonPath('data.0.pin_ids', [])
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.total', 1);

        $person = Outsource::query()->where('outsource_code', 'DM20260135')->firstOrFail();
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/outsource-persons/'.$person->id)
            ->assertOk()
            ->assertJsonPath('data.stores', [])
            ->assertJsonPath('data.pin_ids', []);
    }

    public function test_existing_persons_are_never_modified(): void
    {
        $active = Outsource::factory()->create(['outsource_code' => 'DM20260001', 'name' => 'Ainun Jariyah', 'status' => 'active']);
        $deleted = Outsource::factory()->create(['outsource_code' => 'DM20260002', 'name' => 'Lama']);
        $deleted->delete();

        $this->withToken(self::TOKEN)
            ->postJson(self::URL, [
                'people' => [
                    ['outsource_id' => 'DM20260001', 'full_name' => 'ainun  jariyah'],
                    ['outsource_id' => 'DM20260001X', 'full_name' => 'Baru'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.0.status', 'skipped')
            ->assertJsonPath('data.1.status', 'created');

        $this->withToken(self::TOKEN)
            ->postJson(self::URL, [
                'people' => [
                    ['outsource_id' => 'DM20260001', 'full_name' => 'Ainun Jariah'],
                    ['outsource_id' => 'DM20260002', 'full_name' => 'Lama'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.0.status', 'conflict')
            ->assertJsonPath('data.0.conflict_reason', 'name_mismatch')
            ->assertJsonPath('data.1.status', 'conflict')
            ->assertJsonPath('data.1.conflict_reason', 'deleted_record')
            ->assertJsonPath('meta.conflict', 2);

        $this->assertSame('Ainun Jariyah', $active->fresh()->name);
        $this->assertSame('active', $active->fresh()->status);
        $this->assertTrue($deleted->fresh()->trashed());
    }

    public function test_dry_run_does_not_write(): void
    {
        $this->withToken(self::TOKEN)
            ->postJson(self::URL, [
                'people' => [['outsource_id' => 'DM20260136', 'full_name' => 'Preview']],
                'dry_run' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.0.status', 'would_create');

        $this->assertDatabaseCount('outsources', 0);
    }

    public function test_requires_token_and_fails_closed_when_not_configured(): void
    {
        $payload = ['people' => [['outsource_id' => 'DM20260137', 'full_name' => 'Worker']]];

        $this->postJson(self::URL, $payload)->assertUnauthorized();
        $this->withToken('wrong-token')->postJson(self::URL, $payload)->assertUnauthorized();

        config(['services.hris.outsource_push_api_token' => '']);
        $this->withToken(self::TOKEN)->postJson(self::URL, $payload)
            ->assertServiceUnavailable()
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('outsources', 0);
    }

    public function test_rejects_invalid_payloads(): void
    {
        $this->withToken(self::TOKEN)
            ->postJson(self::URL, [
                'people' => [
                    ['outsource_id' => 'bad id', 'full_name' => 'Invalid'],
                    ['outsource_id' => 'DM20260138', 'full_name' => 'Extra', 'status' => 'active'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['people.0.outsource_id', 'people.1']);

        $this->assertDatabaseCount('outsources', 0);
    }
}
