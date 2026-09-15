<?php

namespace Tests\Feature\Penalty;

use App\Enums\EmploymentStatus;
use App\Enums\PenaltyStatus;
use App\Enums\PenaltyViolationType;
use App\Enums\RecordStatus;
use App\Models\Employee;
use App\Models\PenaltyRecord;
use App\Models\PenaltyRule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\EmployeeFactory;
use Database\Factories\PenaltyRuleFactory;
use Database\Factories\UserFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManualPenaltyTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $user = UserFactory::new()->create();
        $user->assignRole('ADMIN');

        return $user;
    }

    public function test_admin_can_create_manual_penalty(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = $this->makeAdmin();
        $employee = EmployeeFactory::new()->create();
        $rule = PenaltyRuleFactory::new()->create();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/penalties', [
            'employee_id' => $employee->id,
            'penalty_rule_id' => $rule->id,
            'occurred_at' => '2026-09-10 09:00:00',
            'violation_type' => PenaltyViolationType::Other->value,
            'violation_custom' => 'Custom violation description.',
            'points' => 3.5,
            'reason' => 'Manual penalty for testing.',
        ]);

        $response->assertCreated();
        $response->assertJson([
            'success' => true,
            'data' => [
                'source' => 'MANUAL',
                'violation_type' => PenaltyViolationType::Other->value,
                'violation_custom' => 'Custom violation description.',
                'original_points' => 3.5,
                'final_points' => 3.5,
                'status' => PenaltyStatus::Applied->value,
            ],
        ]);

        $this->assertDatabaseHas('penalty_records', [
            'employee_id' => $employee->id,
            'source' => 'MANUAL',
            'violation_type' => PenaltyViolationType::Other->value,
            'final_points' => 3.5,
            'status' => PenaltyStatus::Applied->value,
        ]);
    }

    public function test_other_violation_requires_custom_text(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = $this->makeAdmin();
        $employee = EmployeeFactory::new()->create();
        $rule = PenaltyRuleFactory::new()->create();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/penalties', [
            'employee_id' => $employee->id,
            'penalty_rule_id' => $rule->id,
            'occurred_at' => '2026-09-10 09:00:00',
            'violation_type' => PenaltyViolationType::Other->value,
            'points' => 3.5,
            'reason' => 'Manual penalty for testing.',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('violation_custom');
    }

    public function test_manual_penalty_does_not_require_custom_for_standard_types(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = $this->makeAdmin();
        $employee = EmployeeFactory::new()->create();
        $rule = PenaltyRuleFactory::new()->create();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/penalties', [
            'employee_id' => $employee->id,
            'penalty_rule_id' => $rule->id,
            'occurred_at' => '2026-09-10 09:00:00',
            'violation_type' => PenaltyViolationType::Absence->value,
            'points' => 3.5,
            'reason' => 'Manual penalty for testing.',
        ]);

        $response->assertCreated();
    }

    public function test_user_cannot_create_manual_penalty(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = UserFactory::new()->create();
        $user->assignRole('USER');
        $employee = EmployeeFactory::new()->create(['user_id' => $user->id]);
        $rule = PenaltyRuleFactory::new()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/penalties', [
            'employee_id' => $employee->id,
            'penalty_rule_id' => $rule->id,
            'occurred_at' => '2026-09-10 09:00:00',
            'violation_type' => PenaltyViolationType::Absence->value,
            'points' => 3.5,
            'reason' => 'Manual penalty for testing.',
        ]);

        $response->assertForbidden();
    }

    public function test_unauthenticated_cannot_create_manual_penalty(): void
    {
        $employee = EmployeeFactory::new()->create();
        $rule = PenaltyRuleFactory::new()->create();

        $response = $this->postJson('/api/v1/penalties', [
            'employee_id' => $employee->id,
            'penalty_rule_id' => $rule->id,
            'occurred_at' => '2026-09-10 09:00:00',
            'violation_type' => PenaltyViolationType::Absence->value,
            'points' => 3.5,
            'reason' => 'Manual penalty for testing.',
        ]);

        $response->assertUnauthorized();
    }
}
