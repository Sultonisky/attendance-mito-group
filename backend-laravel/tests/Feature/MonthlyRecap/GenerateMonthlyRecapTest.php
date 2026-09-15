<?php

namespace Tests\Feature\MonthlyRecap;

use App\Actions\MonthlyRecap\ExportMonthlyRecap;
use App\Actions\MonthlyRecap\FinalizeMonthlyRecap;
use App\Actions\MonthlyRecap\GenerateMonthlyRecap;
use App\Actions\MonthlyRecap\ReopenMonthlyRecap;
use App\Actions\MonthlyRecap\ReviewMonthlyRecap;
use App\Domain\MonthlyRecap\Exceptions\MonthlyRecapException;
use App\Models\Employee;
use App\Models\MonthlyRecap;
use App\Models\Policy;
use App\Models\PolicyAssignment;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GenerateMonthlyRecapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function makeUser(string $role = 'USER', array $overrides = []): User
    {
        $user = User::factory()->create($overrides);
        $user->assignRole($role);

        return $user;
    }

    private function makeEmployee(array $overrides = []): Employee
    {
        return Employee::factory()->create($overrides);
    }

    private function makeActiveUserAndEmployee(string $role = 'USER'): array
    {
        $user = $this->makeUser($role);
        $employee = $this->makeEmployee();
        $employee->update(['user_id' => $user->id]);

        return [$user, $employee];
    }

    private function makeScheduleAndPolicy(Employee $employee): void
    {
        $schedule = WorkSchedule::factory()->create();
        Shift::factory()->forSchedule($schedule)->create([
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
        ]);
        ScheduleAssignment::factory()->forEmployee($employee)->forSchedule($schedule)->create([
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);

        $policy = Policy::factory()->create();
        PolicyAssignment::factory()->forEmployee($employee)->forPolicy($policy)->create([
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
    }

    public function test_authenticated_user_can_generate_own_recap(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $user->givePermissionTo('monthly_recap.generate');
        $this->makeScheduleAndPolicy($employee);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/monthly-recaps/generate', [
            'employee_id' => $employee->id,
            'year' => 2026,
            'month' => 9,
        ])->assertOk()->assertJsonPath('data.status', 'draft');

        $this->assertSame(1, MonthlyRecap::where('employee_id', $employee->id)->where('period', '2026-09')->count());
    }

    public function test_admin_can_generate_for_any_employee(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $this->makeScheduleAndPolicy($employee);

        $admin = $this->makeUser('ADMIN');
        $admin->givePermissionTo('monthly_recap.generate');

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/monthly-recaps/generate', [
            'employee_id' => $employee->id,
            'year' => 2026,
            'month' => 9,
        ])->assertOk()->assertJsonPath('data.status', 'draft');
    }

    public function test_unauthorized_user_cannot_generate_for_others(): void
    {
        [$userA, $employeeA] = $this->makeActiveUserAndEmployee();
        [$userB, $employeeB] = $this->makeActiveUserAndEmployee();
        $this->makeScheduleAndPolicy($employeeB);

        $this->actingAs($userA, 'sanctum')->postJson('/api/v1/monthly-recaps/generate', [
            'employee_id' => $employeeB->id,
            'year' => 2026,
            'month' => 9,
        ])->assertStatus(403);
    }

    public function test_invalid_month_validation(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $user->givePermissionTo('monthly_recap.generate');

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/monthly-recaps/generate', [
            'employee_id' => $employee->id,
            'year' => 2026,
            'month' => 13,
        ])->assertStatus(422);
    }

    public function test_idempotency_updates_existing_recap(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $user->givePermissionTo('monthly_recap.generate');
        $this->makeScheduleAndPolicy($employee);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/monthly-recaps/generate', [
            'employee_id' => $employee->id,
            'year' => 2026,
            'month' => 9,
        ])->assertOk();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/monthly-recaps/generate', [
            'employee_id' => $employee->id,
            'year' => 2026,
            'month' => 9,
        ])->assertOk();

        $this->assertSame(1, MonthlyRecap::where('employee_id', $employee->id)->where('period', '2026-09')->count());
    }

    public function test_lifecycle_transitions(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $this->makeScheduleAndPolicy($employee);

        $admin = $this->makeUser('ADMIN');
        $admin->givePermissionTo(['monthly_recap.generate', 'monthly_recap.review', 'monthly_recap.finalize', 'monthly_recap.export']);

        $recap = app(GenerateMonthlyRecap::class)->execute($employee, CarbonImmutable::create(2026, 9, 1), CarbonImmutable::create(2026, 9, 30), $admin);
        $this->assertSame('draft', $recap->status);

        $reviewed = app(ReviewMonthlyRecap::class)->execute($recap->fresh(), $admin);
        $this->assertSame('review', $reviewed->status);

        $finalized = app(FinalizeMonthlyRecap::class)->execute($reviewed->fresh(), $admin);
        $this->assertSame('finalized', $finalized->status);
        $this->assertNotNull($finalized->finalized_at);

        $exported = app(ExportMonthlyRecap::class)->execute($finalized->fresh(), $admin);
        $this->assertSame('exported', $exported->status);
        $this->assertNotNull($exported->exported_at);
    }

    public function test_reopen_finalized_recap(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $this->makeScheduleAndPolicy($employee);

        $admin = $this->makeUser('ADMIN');
        $admin->givePermissionTo(['monthly_recap.generate', 'monthly_recap.review', 'monthly_recap.finalize']);

        $recap = app(GenerateMonthlyRecap::class)->execute($employee, CarbonImmutable::create(2026, 9, 1), CarbonImmutable::create(2026, 9, 30), $admin);
        app(ReviewMonthlyRecap::class)->execute($recap->fresh(), $admin);
        $finalized = app(FinalizeMonthlyRecap::class)->execute($recap->fresh()->fresh(), $admin);
        $reopened = app(ReopenMonthlyRecap::class)->execute($finalized->fresh(), $admin);

        $this->assertSame('review', $reopened->status);
        $this->assertNull($reopened->finalized_at);
    }

    public function test_cannot_regenerate_finalized_without_reopen(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $this->makeScheduleAndPolicy($employee);

        $admin = $this->makeUser('ADMIN');
        $admin->givePermissionTo(['monthly_recap.generate', 'monthly_recap.review', 'monthly_recap.finalize']);

        $recap = app(GenerateMonthlyRecap::class)->execute($employee, CarbonImmutable::create(2026, 9, 1), CarbonImmutable::create(2026, 9, 30), $admin);
        app(ReviewMonthlyRecap::class)->execute($recap->fresh(), $admin);
        app(FinalizeMonthlyRecap::class)->execute($recap->fresh()->fresh(), $admin);

        $this->expectException(MonthlyRecapException::class);
        app(GenerateMonthlyRecap::class)->execute($employee, CarbonImmutable::create(2026, 9, 1), CarbonImmutable::create(2026, 9, 30), $admin);
    }

    public function test_generation_rolls_back_when_audit_fails(): void
    {
        [$user, $employee] = $this->makeActiveUserAndEmployee();
        $user->givePermissionTo('monthly_recap.generate');
        $this->makeScheduleAndPolicy($employee);

        DB::listen(function ($query) {
            if (str_contains($query->sql, 'insert into "audit_logs"')) {
                throw new \Exception('Intentional audit failure for rollback test');
            }
        });

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Intentional audit failure for rollback test');

        app(GenerateMonthlyRecap::class)->execute($employee, CarbonImmutable::create(2026, 9, 1), CarbonImmutable::create(2026, 9, 30), $user);

        $this->assertSame(0, MonthlyRecap::where('employee_id', $employee->id)->where('period', '2026-09')->count());
    }
}
