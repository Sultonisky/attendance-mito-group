<?php

namespace Tests\Unit\Outsource;

use App\Models\Outsource;
use App\Models\OutsourceStoreAssignment;
use App\Models\WorkLocation;
use App\Models\WorkLocationPin;
use App\Services\Outsource\ResolveOutsourceAllowedPins;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveOutsourceAllowedPinsTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_subset_returns_all_active_pins(): void
    {
        $store = WorkLocation::factory()->create(['status' => 'active']);
        $pinA = WorkLocationPin::factory()->forLocation($store)->create(['name' => 'A', 'status' => 'active']);
        $pinB = WorkLocationPin::factory()->forLocation($store)->create(['name' => 'B', 'status' => 'active']);
        WorkLocationPin::factory()->forLocation($store)->inactive()->create(['name' => 'X']);

        $outsource = Outsource::factory()->create(['status' => 'active']);
        OutsourceStoreAssignment::factory()
            ->forOutsource($outsource)
            ->forStore($store)
            ->create(['status' => 'active']);

        $pins = app(ResolveOutsourceAllowedPins::class)->execute($outsource)['pins'];

        $this->assertSame([$pinA->id, $pinB->id], $pins->pluck('id')->all());
    }

    public function test_subset_limits_allowlist_per_person(): void
    {
        $store = WorkLocation::factory()->create(['status' => 'active']);
        $pinA = WorkLocationPin::factory()->forLocation($store)->create(['name' => 'A', 'status' => 'active']);
        $pinB = WorkLocationPin::factory()->forLocation($store)->create(['name' => 'B', 'status' => 'active']);

        $outsource = Outsource::factory()->create(['status' => 'active']);
        $assignment = OutsourceStoreAssignment::factory()
            ->forOutsource($outsource)
            ->forStore($store)
            ->create(['status' => 'active']);
        $assignment->pins()->attach([$pinA->id]);

        $resolver = app(ResolveOutsourceAllowedPins::class);
        $pins = $resolver->execute($outsource)['pins'];

        $this->assertSame([$pinA->id], $pins->pluck('id')->all());
        $this->expectException(\InvalidArgumentException::class);
        $resolver->assertPinAllowed($outsource, $pinB->id);
    }

    public function test_multiple_active_assignments_return_pins_from_all_cabangs(): void
    {
        $storeA = WorkLocation::factory()->create(['status' => 'active', 'name' => 'Cabang A']);
        $storeB = WorkLocation::factory()->create(['status' => 'active', 'name' => 'Cabang B']);
        $pinA = WorkLocationPin::factory()->forLocation($storeA)->create(['name' => 'Pin A', 'status' => 'active']);
        $pinB = WorkLocationPin::factory()->forLocation($storeB)->create(['name' => 'Pin B', 'status' => 'active']);

        $outsource = Outsource::factory()->create(['status' => 'active']);
        OutsourceStoreAssignment::factory()->forOutsource($outsource)->forStore($storeA)->create(['status' => 'active']);
        OutsourceStoreAssignment::factory()->forOutsource($outsource)->forStore($storeB)->create(['status' => 'active']);

        $resolver = app(ResolveOutsourceAllowedPins::class);
        $resolved = $resolver->execute($outsource);

        $this->assertCount(2, $resolved['assignments']);
        $this->assertEqualsCanonicalizing([$pinA->id, $pinB->id], $resolved['pins']->pluck('id')->all());
        $this->assertSame($pinA->id, $resolver->assertPinAllowed($outsource, $pinA->id)->id);
        $this->assertSame($pinB->id, $resolver->assertPinAllowed($outsource, $pinB->id)->id);
    }
}
