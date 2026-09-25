<?php

namespace Tests\Feature\Outsource;

use App\Models\User;
use App\Models\WorkLocation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutsourcePersonMultiStoreValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_duplicate_store_ids_are_rejected_on_create(): void
    {
        $user = User::factory()->create();
        $user->assignRole('USER');
        $user->givePermissionTo('outsource_person.create');

        $store = WorkLocation::factory()->create(['status' => 'active']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/outsource-persons', [
                'name' => 'Worker Multi',
                'password' => '123456',
                'store_ids' => [$store->id, $store->id],
                'pin_ids' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['store_ids.0']);
    }

    public function test_empty_store_ids_are_rejected_on_create(): void
    {
        $user = User::factory()->create();
        $user->assignRole('USER');
        $user->givePermissionTo('outsource_person.create');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/outsource-persons', [
                'name' => 'Worker No Cabang',
                'password' => '123456',
                'store_ids' => [],
                'pin_ids' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['store_ids']);
    }

    public function test_missing_store_ids_are_rejected_on_create(): void
    {
        $user = User::factory()->create();
        $user->assignRole('USER');
        $user->givePermissionTo('outsource_person.create');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/outsource-persons', [
                'name' => 'Worker No Cabang',
                'password' => '123456',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['store_ids']);
    }

    public function test_legacy_store_id_is_accepted_on_create(): void
    {
        $user = User::factory()->create();
        $user->assignRole('USER');
        $user->givePermissionTo('outsource_person.create');

        $store = WorkLocation::factory()->create(['status' => 'active']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/outsource-persons', [
                'name' => 'Worker Legacy',
                'password' => '123456',
                'store_id' => $store->id,
            ])
            ->assertCreated()
            ->assertJsonPath('success', true);
    }

    public function test_unique_store_ids_are_accepted_on_create(): void
    {
        $user = User::factory()->create();
        $user->assignRole('USER');
        $user->givePermissionTo('outsource_person.create');

        $storeA = WorkLocation::factory()->create(['status' => 'active']);
        $storeB = WorkLocation::factory()->create(['status' => 'active']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/outsource-persons', [
                'name' => 'Worker Multi',
                'password' => '123456',
                'store_ids' => [$storeA->id, $storeB->id],
                'pin_ids' => [],
            ])
            ->assertCreated()
            ->assertJsonPath('success', true);
    }
}
