<?php

namespace Tests\Feature\Penalty;

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

class PenaltyVoidTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $user = UserFactory::new()->create();
        $user->assignRole('ADMIN');

        return $user;
    }

    public function test_admin_can_void_applied_penalty(): void
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
            'status' => PenaltyStatus::Applied->value,
            'occurred_at' => '2026-09-10 00:00:00',
        ]);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/penalties/'.$penalty->id.'/void', [
            'reason' => 'Voided after managerial exception.',
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'data' => [
                'status' => PenaltyStatus::Voided->value,
            ],
        ]);

        $this->assertDatabaseHas('penalty_records', [
            'id' => $penalty->id,
            'status' => PenaltyStatus::Voided->value,
        ]);
    }

    public function test_void_creates_audit(): void
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
            'status' => PenaltyStatus::Applied->value,
            'occurred_at' => '2026-09-10 00:00:00',
        ]);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/penalties/'.$penalty->id.'/void', [
            'reason' => 'Voided after managerial exception.',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'penalty.voided',
            'auditable_type' => PenaltyRecord::class,
            'auditable_id' => $penalty->id,
        ]);
    }

    public function test_cannot_void_non_applied_penalty(): void
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
            'reason' => 'Voided after managerial exception.',
        ]);

        $response->assertStatus(422);
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
            'source' => 'SYSTEM',
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
