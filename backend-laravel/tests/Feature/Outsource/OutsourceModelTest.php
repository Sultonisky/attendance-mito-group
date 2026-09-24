<?php

namespace Tests\Feature\Outsource;

use App\Models\City;
use App\Models\Outsource;
use App\Models\OutsourceStoreAssignment;
use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutsourceModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_outsource_can_be_created(): void
    {
        $outsource = Outsource::factory()->create([
            'outsource_code' => 'OUT-001',
            'name' => 'John Doe',
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('outsources', [
            'outsource_code' => 'OUT-001',
            'name' => 'John Doe',
            'status' => 'active',
        ]);
    }

    public function test_outsource_code_is_unique(): void
    {
        Outsource::factory()->create(['outsource_code' => 'OUT-001']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Outsource::factory()->create(['outsource_code' => 'OUT-001']);
    }

    public function test_outsource_has_stores_relationship(): void
    {
        $outsource = Outsource::factory()->create();
        $store = WorkLocation::factory()->create();

        OutsourceStoreAssignment::factory()
            ->forOutsource($outsource)
            ->forStore($store)
            ->create();

        $this->assertCount(1, $outsource->stores);
    }

    public function test_outsource_active_stores_scope(): void
    {
        $outsource = Outsource::factory()->create();
        $store = WorkLocation::factory()->create();
        $inactiveStore = WorkLocation::factory()->create();

        OutsourceStoreAssignment::factory()
            ->forOutsource($outsource)
            ->forStore($store)
            ->create(['status' => 'active']);

        OutsourceStoreAssignment::factory()
            ->forOutsource($outsource)
            ->forStore($inactiveStore)
            ->create(['status' => 'inactive']);

        $this->assertCount(1, $outsource->activeStores);
    }

    public function test_generate_next_code_starts_dm2026_sequence(): void
    {
        $this->assertSame('DM20260001', Outsource::generateNextCode());
    }

    public function test_generate_next_code_continues_after_imported_codes(): void
    {
        Outsource::factory()->create(['outsource_code' => 'DM20260123']);
        Outsource::factory()->create(['outsource_code' => '901']); // demo / legacy — ignored

        $this->assertSame('DM20260124', Outsource::generateNextCode());
    }

    public function test_generate_next_code_skips_existing_and_trashed(): void
    {
        Outsource::factory()->create(['outsource_code' => 'DM20260001']);
        $trashed = Outsource::factory()->create(['outsource_code' => 'DM20260002']);
        $trashed->delete();

        $this->assertSame('DM20260003', Outsource::generateNextCode());
    }

    public function test_manual_create_uses_dm2026_sequence_and_default_pin(): void
    {
        Outsource::factory()->create(['outsource_code' => 'DM20260123']);

        $person = app(\App\Actions\Outsource\CreateOutsourcePerson::class)->execute(
            ['name' => 'Manual Worker'],
            null,
            null,
        );

        $this->assertSame('DM20260124', $person->outsource_code);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check(Outsource::DEFAULT_LOGIN_PIN, $person->password));
    }
}
