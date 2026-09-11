<?php

namespace Tests\Unit\Domain\Attendance;

use App\Domain\Attendance\Exceptions\OutsideGeofenceException;
use App\Domain\Attendance\Rules\GeofenceRule;
use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeofenceRuleTest extends TestCase
{
    use RefreshDatabase;

    private GeofenceRule $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rule = new GeofenceRule;
    }

    public function test_point_inside_scalar_geofence_passes(): void
    {
        $location = WorkLocation::factory()->create([
            'latitude' => -6.2,
            'longitude' => 106.8,
            'radius_meters' => 1000,
        ]);

        $this->rule->validate($location, -6.2001, 106.8001);
        $this->assertTrue(true);
    }

    public function test_point_outside_scalar_geofence_throws_exception(): void
    {
        $location = WorkLocation::factory()->create([
            'latitude' => -6.2,
            'longitude' => 106.8,
            'radius_meters' => 100,
        ]);

        $this->expectException(OutsideGeofenceException::class);
        $this->rule->validate($location, -6.3, 106.9);
    }
}
