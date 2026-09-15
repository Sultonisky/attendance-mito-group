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

class PenaltyAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $user = UserFactory::new()->create();
        $user->assignRole('ADMIN');

        return $user;
    }

    public function test_user_can_view_own_penalty(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = UserFactory::new()->create();
        $user->assignRole('USER');
        $employee = EmployeeFactory::new()->create(['user_id' => $user->id]);
        $rule = PenaltyRuleFactory::new()->create();
        $penalty = PenaltyRecord::create([
            'employee_id' => $employee->id,
            'penalty_rule_id' => $rule->id,
            'source' => 'MANUAL',
            'violation_type' => 'ABSENCE',
            'original_points' => 5,
            'adjusted_points' => null,
            'final_points' => 5,
            'status' => PenaltyStatus::Applied->value,
            'occurred_at' => '2026-09-10 00:00:00',
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/penalties/'.$penalty->id);

        $response->assertOk();
    }

    public function test_user_cannot_view_another_employee_penalty(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = UserFactory::new()->create();
        $user->assignRole('USER');
        $employee = EmployeeFactory::new()->create(['user_id' => $user->id]);
        $otherEmployee = EmployeeFactory::new()->create();
        $rule = PenaltyRuleFactory::new()->create();
        $penalty = PenaltyRecord::create([
            'employee_id' => $otherEmployee->id,
            'penalty_rule_id' => $rule->id,
            'source' => 'MANUAL',
            'violation_type' => 'ABSENCE',
            'original_points' => 5,
            'adjusted_points' => null,
            'final_points' => 5,
            'status' => PenaltyStatus::Applied->value,
            'occurred_at' => '2026-09-10 00:00:00',
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/penalties/'.$penalty->id);

        $response->assertStatus(403);
    }

    public function test_admin_can_view_all_penalties(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = $this->makeAdmin();
        $employee = EmployeeFactory::new()->create();
        $rule = PenaltyRuleFactory::new()->create();
        $penalty = PenaltyRecord::create([
            'employee_id' => $employee->id,
            'penalty_rule_id' => $rule->id,
            'source' => 'MANUAL',
            'violation_type' => 'ABSENCE',
            'original_points' => 5,
            'adjusted_points' => null,
            'final_points' => 5,
            'status' => PenaltyStatus::Applied->value,
            'occurred_at' => '2026-09-10 00:00:00',
        ]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/penalties/'.$penalty->id);

        $response->assertOk();
    }

    public function test_user_cannot_create_penalty(): void
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
            'violation_type' => 'ABSENCE',
            'points' => 3.5,
            'reason' => 'Manual penalty.',
        ]);

        $response->assertForbidden();
    }

    public function test_user_cannot_adjust_penalty(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = UserFactory::new()->create();
        $user->assignRole('USER');
        $employee = EmployeeFactory::new()->create(['user_id' => $user->id]);
        $rule = PenaltyRuleFactory::new()->create();
        $penalty = PenaltyRecord::create([
            'employee_id' => $employee->id,
            'penalty_rule_id' => $rule->id,
            'source' => 'MANUAL',
            'violation_type' => 'ABSENCE',
            'original_points' => 5,
            'adjusted_points' => null,
            'final_points' => 5,
            'status' => PenaltyStatus::Applied->value,
            'occurred_at' => '2026-09-10 00:00:00',
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/penalties/'.$penalty->id.'/adjust', [
            'points' => 3,
            'reason' => 'Adjusted.',
        ]);

        $response->assertForbidden();
    }

    public function test_user_cannot_void_penalty(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = UserFactory::new()->create();
        $user->assignRole('USER');
        $employee = EmployeeFactory::new()->create(['user_id' => $user->id]);
        $rule = PenaltyRuleFactory::new()->create();
        $penalty = PenaltyRecord::create([
            'employee_id' => $employee->id,
            'penalty_rule_id' => $rule->id,
            'source' => 'MANUAL',
            'violation_type' => 'ABSENCE',
            'original_points' => 5,
            'adjusted_points' => null,
            'final_points' => 5,
            'status' => PenaltyStatus::Applied->value,
            'occurred_at' => '2026-09-10 00:00:00',
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/penalties/'.$penalty->id.'/void', [
            'reason' => 'Voided.',
        ]);

        $response->assertForbidden();
    }
}
