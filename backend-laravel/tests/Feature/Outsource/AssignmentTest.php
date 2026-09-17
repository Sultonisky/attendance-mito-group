<?php

namespace Tests\Feature\Outsource;

use App\Models\City;
use App\Models\OutsourceStoreAssignment;
use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_assignment_links_outsource_to_store(): void
    {
        $outsource = \App\Models\Outsource::factory()->create();
        $store = WorkLocation::factory()->create();

        $assignment = OutsourceStoreAssignment::factory()
            ->forOutsource($outsource)
            ->forStore($store)
            ->create();

        $this->assertTrue($outsource->stores()->where('work_locations.id', $store->id)->exists());
        $this->assertTrue($store->outsources()->where('outsources.id', $outsource->id)->exists());
    }

    public function test_unique_assignment_per_outsource_store_pair(): void
    {
        $outsource = \App\Models\Outsource::factory()->create();
        $store = WorkLocation::factory()->create();

        OutsourceStoreAssignment::factory()
            ->forOutsource($outsource)
            ->forStore($store)
            ->create();

        $this->expectException(\Illuminate\Database\QueryException::class);

        OutsourceStoreAssignment::factory()
            ->forOutsource($outsource)
            ->forStore($store)
            ->create();
    }
}
