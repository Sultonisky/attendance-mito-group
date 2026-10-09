<?php

namespace Tests\Feature\Outsource;

use App\Models\Outsource;
use App\Models\OutsourceStoreAssignment;
use App\Models\WorkLocation;
use App\Models\WorkLocationPin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OutsourcePayrollApiTest extends TestCase
{
    use RefreshDatabase;

    private const DEVICE_FINGERPRINT = 'testdevicefingerprint01';
    private const HRIS_TOKEN = 'test-outsource-payroll-token';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.hris.base_url' => 'https://hris.example.test',
            'services.hris.outsource_payroll_api_token' => self::HRIS_TOKEN,
            'services.hris.timeout' => 8,
        ]);
    }

    private function cookieName(): string
    {
        return (string) config('outsource_session.cookie.name', 'outsource_session');
    }

    private function createAssignedOutsource(string $code = 'DM20260001'): Outsource
    {
        $store = WorkLocation::factory()->create(['status' => 'active']);
        WorkLocationPin::factory()->forLocation($store)->create(['status' => 'active']);
        $outsource = Outsource::factory()->create([
            'outsource_code' => $code,
            'status' => 'active',
            'password' => '123456',
        ]);
        OutsourceStoreAssignment::factory()
            ->forOutsource($outsource)
            ->forStore($store)
            ->create(['status' => 'active']);

        return $outsource;
    }

    private function loginCookie(Outsource $outsource): string
    {
        $login = $this->postJson('/api/v1/outsource/login', [
            'outsource_code' => $outsource->outsource_code,
            'password' => '123456',
            'device_fingerprint' => self::DEVICE_FINGERPRINT,
        ]);
        $login->assertCreated();

        $cookie = $login->getCookie($this->cookieName(), false);
        $this->assertNotNull($cookie);

        return $cookie->getValue();
    }

    private function getAsOutsource(string $cookie, string $path)
    {
        return $this->withCredentials()
            ->withUnencryptedCookie($this->cookieName(), $cookie)
            ->getJson($path);
    }

    public function test_payslip_endpoint_uses_signed_in_outsource_code_and_returns_hris_data(): void
    {
        $outsource = $this->createAssignedOutsource();
        $cookie = $this->loginCookie($outsource);

        Http::fake([
            'https://hris.example.test/api/v1/outsource/DM20260001/payslips*' => Http::response([
                'success' => true,
                'outsource_id' => 'DM20260001',
                'data' => [[
                    'period' => '2026-09',
                    'outsource_id' => 'DM20260001',
                    'full_name' => 'Worker One',
                    'vendor' => 'Damarindo',
                    'hke' => 24.5,
                    'basic_salary' => 3200000,
                    'bpjs_kesehatan_deduction' => 32000,
                    'loan_deduction' => 0,
                    'take_home_pay' => 3168000,
                    'source_file' => 'private.xlsx',
                ]],
            ], 200),
        ]);

        $this->getAsOutsource($cookie, '/api/v1/outsource/payroll/payslips?period=2026-09')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.period', '2026-09')
            ->assertJsonPath('data.0.take_home_pay', 3168000)
            ->assertJsonMissingPath('data.0.source_file');

        Http::assertSent(fn (Request $request): bool =>
            $request->url() === 'https://hris.example.test/api/v1/outsource/DM20260001/payslips?period=2026-09'
            && $request->hasHeader('Authorization', 'Bearer '.self::HRIS_TOKEN)
        );
    }

    public function test_incentive_endpoint_uses_signed_in_outsource_code(): void
    {
        $outsource = $this->createAssignedOutsource();
        $cookie = $this->loginCookie($outsource);

        Http::fake([
            'https://hris.example.test/api/v1/outsource/DM20260001/incentives*' => Http::response([
                'success' => true,
                'outsource_id' => 'DM20260001',
                'data' => [[
                    'period' => '2026-09',
                    'outsource_id' => 'DM20260001',
                    'full_name' => 'Worker One',
                    'vendor' => 'Damarindo',
                    'umk_amount' => 0,
                    'incentive_amount' => 1250000,
                ]],
            ], 200),
        ]);

        $this->getAsOutsource($cookie, '/api/v1/outsource/payroll/incentives')
            ->assertOk()
            ->assertJsonPath('data.0.umk_amount', 0)
            ->assertJsonPath('data.0.incentive_amount', 1250000);

        Http::assertSent(fn (Request $request): bool =>
            str_contains($request->url(), '/api/v1/outsource/DM20260001/incentives')
            && $request->hasHeader('Authorization', 'Bearer '.self::HRIS_TOKEN)
        );
    }

    public function test_payroll_endpoints_require_the_active_outsource_session(): void
    {
        Http::fake();

        $this->getJson('/api/v1/outsource/payroll/payslips')->assertUnauthorized();
        $this->getJson('/api/v1/outsource/payroll/incentives')->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_invalid_period_is_rejected_before_calling_hris(): void
    {
        $outsource = $this->createAssignedOutsource();
        $cookie = $this->loginCookie($outsource);
        Http::fake();

        $this->getAsOutsource($cookie, '/api/v1/outsource/payroll/payslips?period=2026-13')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('period');

        Http::assertNothingSent();
    }

    public function test_payroll_integration_does_not_fall_back_to_the_employee_api_token(): void
    {
        $outsource = $this->createAssignedOutsource();
        $cookie = $this->loginCookie($outsource);
        config([
            'services.hris.employee_api_token' => 'employee-api-token',
            'services.hris.outsource_payroll_api_token' => '',
        ]);
        Http::fake();

        $this->getAsOutsource($cookie, '/api/v1/outsource/payroll/payslips')
            ->assertStatus(502)
            ->assertJsonPath('message', 'HRIS payroll service is not configured.');

        Http::assertNothingSent();
    }

    public function test_hris_failure_is_returned_as_a_gateway_error_without_exposing_upstream_details(): void
    {
        $outsource = $this->createAssignedOutsource();
        $cookie = $this->loginCookie($outsource);
        Http::fake([
            'https://hris.example.test/*' => Http::response(['message' => 'secret upstream detail'], 500),
        ]);

        $this->getAsOutsource($cookie, '/api/v1/outsource/payroll/payslips')
            ->assertStatus(502)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'HRIS payroll service returned an error.')
            ->assertDontSee('secret upstream detail');
    }
}
