<?php

namespace Tests\Feature\Penalty;

use App\Enums\EmploymentStatus;
use App\Enums\PenaltyStatus;
use App\Models\Employee;
use App\Models\PenaltyRecord;
use App\Models\PenaltyRule;
use App\Models\User;
use Database\Factories\EmployeeFactory;
use Database\Factories\PenaltyRuleFactory;
use Database\Factories\UserFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PenaltyValidationTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $user = UserFactory::new()->create();
        $user->assignRole('ADMIN');

        return $user;
    }

    public function test_other_violation_without_custom_text_returns_422(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = $this->makeAdmin();
        $employee = EmployeeFactory::new()->create();
        $rule = PenaltyRuleFactory::new()->create();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/penalties', [
            'employee_id' => $employee->id,
            'penalty_rule_id' => $rule->id,
            'occurred_at' => '2026-09-10 09:00:00',
            'violation_type' => 'OTHER',
            'points' => 3.5,
            'reason' => 'Manual penalty.',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('violation_custom');
    }

    public function test_invalid_points_returns_422(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = $this->makeAdmin();
        $employee = EmployeeFactory::new()->create();
        $rule = PenaltyRuleFactory::new()->create();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/penalties', [
            'employee_id' => $employee->id,
            'penalty_rule_id' => $rule->id,
            'occurred_at' => '2026-09-10 09:00:00',
            'violation_type' => 'ABSENCE',
            'points' => -1,
            'reason' => 'Manual penalty.',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('points');
    }

    public function test_cannot_adjust_voided_penalty(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = $this->makeAdmin();
        $employee = EmployeeFactory::new()->create();
        $rule = PenaltyRuleFactory::new()->create();
        $penalty = PenaltyRecord::create([
            'employee_id' => $employee->id,
            'penalty_rule_id' => $rule->id,
            'source' => 'SYSTEM',
            'violation_type' => 'ABSENCE',
            'original_points' => 5,
            'adjusted_points' => null,
            'final_points' => 5,
            'status' => PenaltyStatus::Voided->value,
            'occurred_at' => '2026-09-10 00:00:00',
        ]);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/penalties/'.$penalty->id.'/adjust', [
            'points' => 3,
            'reason' => 'Adjusted.',
        ]);

        $response->assertStatus(422);
    }

    public function test_cannot_void_already_voided_penalty(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = $this->makeAdmin();
        $employee = EmployeeFactory::new()->create();
        $rule = PenaltyRuleFactory::new()->create();
        $penalty = PenaltyRecord::create([
            'employee_id' => $employee->id,
            'penalty_rule_id' => $rule->id,
            'source' => 'SYSTEM',
            'violation_type' => 'ABSENCE',
            'original_points' => 5,
            'adjusted_points' => null,
            'final_points' => 5,
            'status' => PenaltyStatus::Voided->value,
            'occurred_at' => '2026-09-10 00:00:00',
        ]);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/penalties/'.$penalty->id.'/void', [
            'reason' => 'Voided again.',
        ]);

        $response->assertStatus(422);
    }
}
