<?php

namespace Tests\Feature\Outsource;

use App\Models\Outsource;
use App\Models\User;
use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OutsourcePersonHrisSyncTest extends TestCase
{
    use RefreshDatabase;

    private const HRIS_TOKEN = 'test-outsource-directory-sync-token';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        config([
            'services.hris.base_url' => 'https://hris.example.test',
            'services.hris.outsource_sync_api_token' => self::HRIS_TOKEN,
            'services.hris.timeout' => 8,
        ]);
    }

    public function test_person_list_can_no_longer_create_outsource_persons(): void
    {
        $user = User::factory()->create();
        $user->assignRole('USER');
        $user->givePermissionTo('outsource_person.create');
        $store = WorkLocation::factory()->create(['status' => 'active']);
        Http::fake();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/outsource-persons', [
                'name' => 'Person Baru',
                'password' => '123456',
                'store_ids' => [$store->id],
                'pin_ids' => [],
            ])
            ->assertMethodNotAllowed();

        $this->assertDatabaseCount('outsources', 0);
        Http::assertNothingSent();
    }

    public function test_sync_command_defaults_to_dry_run_and_can_execute_explicitly(): void
    {
        Outsource::factory()->create(['outsource_code' => 'DM20260001', 'name' => 'Existing Person']);
        Http::fake([
            'https://hris.example.test/api/v1/outsource/persons/sync' => Http::response([
                'success' => true,
                'data' => [['outsource_id' => 'DM20260001', 'status' => 'would_create']],
            ], 200),
        ]);

        $this->artisan('hris:sync-outsource-persons')
            ->expectsOutputToContain('Preview only')
            ->expectsOutputToContain('WOULD_CREATE: DM20260001')
            ->assertExitCode(0);

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer '.self::HRIS_TOKEN)
            && $request['people'] === [['outsource_id' => 'DM20260001', 'full_name' => 'Existing Person']]
            && $request['dry_run'] === true);

        Http::fake([
            'https://hris.example.test/api/v1/outsource/persons/sync' => Http::response([
                'success' => true,
                'data' => [['outsource_id' => 'DM20260001', 'status' => 'created']],
            ], 200),
        ]);

        $this->assertSame(0, Artisan::call('hris:sync-outsource-persons', ['--execute' => true]));
        $this->assertStringContainsString('completed', Artisan::output());
        Http::assertSent(fn (Request $request): bool => $request['dry_run'] === false);
    }
}
