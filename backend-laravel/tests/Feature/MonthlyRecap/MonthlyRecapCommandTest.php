<?php

namespace Tests\Feature\MonthlyRecap;

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
use Tests\TestCase;

class MonthlyRecapCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function makeEmployee(array $overrides = []): Employee
    {
        return Employee::factory()->create($overrides);
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

    public function test_command_with_valid_month(): void
    {
        $employee = $this->makeEmployee();
        $this->makeScheduleAndPolicy($employee);

        User::factory()->create();

        $this->artisan('recap:generate', ['month' => '2026-09'])
            ->expectsOutput("Generated recap for employee {$employee->id} (2026-09).")
            ->assertExitCode(0);

        $this->assertSame(1, MonthlyRecap::where('employee_id', $employee->id)->where('period', '2026-09')->count());
    }

    public function test_command_with_employee_filter(): void
    {
        $employeeA = $this->makeEmployee();
        $employeeB = $this->makeEmployee();
        $this->makeScheduleAndPolicy($employeeA);
        $this->makeScheduleAndPolicy($employeeB);

        User::factory()->create();

        $this->artisan('recap:generate', ['month' => '2026-09', '--employee' => $employeeA->id])
            ->assertExitCode(0);

        $this->assertSame(1, MonthlyRecap::where('period', '2026-09')->count());
    }

    public function test_command_invalid_month_format(): void
    {
        User::factory()->create();

        $this->artisan('recap:generate', ['month' => 'invalid'])
            ->expectsOutput('Invalid month format. Expected YYYY-MM, got: invalid')
            ->assertExitCode(1);
    }

    public function test_command_defaults_to_previous_month(): void
    {
        $employee = $this->makeEmployee();
        $this->makeScheduleAndPolicy($employee);

        User::factory()->create();

        $expectedMonth = CarbonImmutable::now()->subMonth()->format('Y-m');

        $this->artisan('recap:generate')
            ->assertExitCode(0);

        $this->assertSame(1, MonthlyRecap::where('employee_id', $employee->id)->where('period', $expectedMonth)->count());
    }
}
