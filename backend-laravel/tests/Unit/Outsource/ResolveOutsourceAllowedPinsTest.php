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
}
