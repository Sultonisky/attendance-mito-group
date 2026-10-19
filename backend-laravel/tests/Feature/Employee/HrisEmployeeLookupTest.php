<?php

namespace Tests\Feature\Employee;

use App\Models\Employee;
use App\Models\EmployeePhotoReference;
use App\Models\EmployeeWorkLocation;
use App\Models\EmployeeWorkLocationOption;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HrisEmployeeLookupTest extends TestCase
{
    use RefreshDatabase;

    private const NIK = '1234567890123456';

    private const API_TOKEN = 'test-hris-employee-api-token';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        config([
            'services.hris.base_url' => 'https://hris.example.test',
            'services.hris.employee_api_token' => self::API_TOKEN,
            'services.hris.timeout' => 8,
        ]);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('ADMIN');

        return $user;
    }

    public function test_authorized_user_can_fetch_employee_from_hris_by_nik(): void
    {
        Http::fake([
            'https://hris.example.test/api/v1/employees/by-nik/*' => Http::response([
                'success' => true,
                'data' => [
                    'employee_id' => '2020041501',
                    'nik' => self::NIK,
                    'full_name' => 'Employee One',
                    'status_employee' => 'Permanent (PKWTT)',
                    'job_position' => 'Treasury Staff',
                    'division' => 'Finance',
                    'department' => 'Treasury',
                    'branch_name' => 'MSI',
                    'job_position_location' => null,
                    'area_kerja' => null,
                    'lokasi_kerja' => null,
                ],
            ]),
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/employees/hris/by-nik/'.self::NIK)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.employee_id', '2020041501')
            ->assertJsonPath('data.nik', self::NIK)
            ->assertJsonPath('data.full_name', 'Employee One')
            ->assertJsonMissingPath('data.bank_account');

        Http::assertSent(fn ($request) => $request->url() === 'https://hris.example.test/api/v1/employees/by-nik/'.self::NIK
            && $request->hasHeader('Authorization', 'Bearer '.self::API_TOKEN));

        $this->assertDatabaseCount('employees', 0);
    }

    public function test_hris_employee_lookup_requires_authentication_and_view_permission(): void
    {
        $this->getJson('/api/v1/employees/hris/by-nik/'.self::NIK)
            ->assertUnauthorized();
        $this->get('/api/v1/employees/hris/2020041501/photo')
            ->assertUnauthorized();

        $user = User::factory()->create();
        $role = Role::findByName('USER');
        $role->syncPermissions(['dashboard.view']);
        $user->assignRole('USER');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/employees/hris/by-nik/'.self::NIK)
            ->assertForbidden();
        $this->get('/api/v1/employees/hris/2020041501/photo')
            ->assertForbidden();
    }

    public function test_invalid_nik_is_rejected_before_calling_hris(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/employees/hris/by-nik/123')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('nik');

        Http::assertNothingSent();
    }

    public function test_missing_hris_configuration_returns_service_unavailable(): void
    {
        config([
            'services.hris.base_url' => '',
            'services.hris.employee_api_token' => '',
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/employees/hris/by-nik/'.self::NIK)
            ->assertStatus(502)
            ->assertJsonPath('success', false);

        Http::assertNothingSent();
    }

    public function test_unknown_nik_returns_not_found(): void
    {
        Http::fake([
            'https://hris.example.test/api/v1/employees/by-nik/*' => Http::response([
                'success' => false,
                'message' => 'Employee not found.',
            ], 404),
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/employees/hris/by-nik/'.self::NIK)
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_unavailable_hris_returns_a_service_error_without_exposing_upstream_details(): void
    {
        Http::fake([
            'https://hris.example.test/api/v1/employees/by-nik/*' => Http::response([
                'message' => 'internal upstream detail',
            ], 500),
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/employees/hris/by-nik/'.self::NIK)
            ->assertStatus(502)
            ->assertJsonPath('success', false)
            ->assertJsonMissing(['message' => 'internal upstream detail']);
    }

    public function test_hris_connection_failure_returns_service_unavailable(): void
    {
        Http::fake([
            'https://hris.example.test/api/v1/employees/by-nik/*' => Http::failedConnection(),
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/employees/hris/by-nik/'.self::NIK)
            ->assertStatus(502)
            ->assertJsonPath('success', false);
    }

    public function test_authorized_user_can_fetch_paginated_and_searched_hris_employee_list(): void
    {
        Http::fake([
            'https://hris.example.test/api/v1/employees*' => Http::response([
                'success' => true,
                'data' => [[
                    'employee_id' => '2020041501',
                    'nik' => self::NIK,
                    'full_name' => 'Employee One',
                    'status_employee' => 'Permanent (PKWTT)',
                    'job_position' => 'Treasury Staff',
                    'division' => 'Finance',
                    'department' => 'Treasury',
                    'branch_name' => 'MSI',
                    'job_position_location' => null,
                    'area_kerja' => null,
                    'lokasi_kerja' => null,
                    'personal_email' => 'private@example.test',
                ]],
                'links' => [
                    'first' => 'https://hris.example.test/api/v1/employees?page=1',
                    'last' => 'https://hris.example.test/api/v1/employees?page=1',
                    'prev' => null,
                    'next' => null,
                ],
                'filters' => [
                    'work_locations' => ['Jakarta'],
                    'work_areas' => ['Head Office'],
                ],
                'meta' => [
                    'current_page' => 1,
                    'per_page' => 25,
                    'total' => 1,
                    'last_page' => 1,
                ],
            ]),
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/employees/hris?page=1&per_page=25&search=Treasury&work_location=Jakarta&work_area=Head%20Office')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.employee_id', '2020041501')
            ->assertJsonPath('data.0.nik', self::NIK)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('filters.work_locations', ['Jakarta'])
            ->assertJsonPath('filters.work_areas', ['Head Office'])
            ->assertJsonMissingPath('data.0.personal_email');

        Http::assertSent(function ($request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return parse_url($request->url(), PHP_URL_PATH) === '/api/v1/employees'
                && $query['page'] === '1'
                && $query['per_page'] === '25'
                && $query['search'] === 'Treasury'
                && $query['work_location'] === 'Jakarta'
                && $query['work_area'] === 'Head Office'
                && $request->hasHeader('Authorization', 'Bearer '.self::API_TOKEN);
        });

        $this->assertDatabaseCount('employees', 0);
    }

    public function test_hris_person_list_reports_photo_reference_from_hris_identity(): void
    {
        EmployeePhotoReference::create([
            'hris_employee_id' => '2020041501',
            'hris_nik' => self::NIK,
            'photo_path' => 'employee-photos/2020041501.jpg',
        ]);
        EmployeePhotoReference::create([
            'hris_employee_id' => 'LOCAL-ONLY',
            'hris_nik' => null,
            'photo_path' => 'employee-photos/local-only.jpg',
        ]);
        EmployeePhotoReference::create([
            'hris_employee_id' => '2020041503',
            'hris_nik' => '1234567890123457',
            'photo_path' => 'employee-photos/nik-match.jpg',
        ]);
        EmployeePhotoReference::create([
            'hris_employee_id' => 'CONFLICTING-HRIS-ID',
            'hris_nik' => null,
            'photo_path' => 'employee-photos/conflict-by-code.jpg',
        ]);
        EmployeePhotoReference::create([
            'hris_employee_id' => 'CONFLICTING-NIK',
            'hris_nik' => '1234567890123458',
            'photo_path' => 'employee-photos/conflict-by-nik.jpg',
        ]);

        Http::fake([
            'https://hris.example.test/api/v1/employees*' => Http::response([
                'success' => true,
                'data' => [
                    [
                        'employee_id' => '2020041501',
                        'nik' => self::NIK,
                        'full_name' => 'Employee One',
                    ],
                    [
                        'employee_id' => '2020041502',
                        'nik' => null,
                        'full_name' => 'Employee Two',
                    ],
                    [
                        'employee_id' => '2020041503',
                        'nik' => '1234567890123457',
                        'full_name' => 'Employee Three',
                    ],
                    [
                        'employee_id' => 'CONFLICTING-HRIS-ID',
                        'nik' => '1234567890123458',
                        'full_name' => 'Conflicting Identity',
                    ],
                ],
                'links' => [],
                'filters' => [
                    'work_locations' => [],
                    'work_areas' => [],
                ],
                'meta' => [
                    'current_page' => 1,
                    'per_page' => 25,
                    'total' => 4,
                    'last_page' => 1,
                ],
            ]),
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/employees/hris')
            ->assertOk()
            ->assertJsonPath('data.0.photo_available', true)
            ->assertJsonPath('data.1.photo_available', false)
            ->assertJsonPath('data.2.photo_available', true)
            ->assertJsonPath('data.3.photo_available', false)
            ->assertJsonMissingPath('data.0.photo_path');
    }

    public function test_authorized_user_can_upload_and_replace_a_private_employee_photo(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://hris.example.test/api/v1/employees/by-nik/*' => Http::response([
                'success' => true,
                'data' => [
                    'employee_id' => '2020041501',
                    'nik' => self::NIK,
                ],
            ]),
        ]);
        $this->actingAs($this->admin(), 'sanctum')
            ->post('/api/v1/employees/hris/2020041501/photo', [
                'employee_id' => '2020041501',
                'nik' => self::NIK,
                'photo' => UploadedFile::fake()->image('employee.jpg', 64, 64),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.photo_available', true)
            ->assertJsonMissingPath('data.photo_path');

        $this->assertDatabaseCount('employees', 0);
        $this->assertTrue(Schema::hasTable('employee_photo_references'));
        $this->assertFalse(Schema::hasColumn('employees', 'photo_path'));
        $photoReference = EmployeePhotoReference::query()->where('hris_employee_id', '2020041501')->firstOrFail();
        $firstPhotoPath = $photoReference->photo_path;
        $this->assertSame(self::NIK, $photoReference->hris_nik);
        $this->assertNotNull($firstPhotoPath);
        Storage::disk('local')->assertExists($firstPhotoPath);

        $photoResponse = $this->get('/api/v1/employees/hris/2020041501/photo?nik='.self::NIK);
        $photoResponse
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertStreamedContent(Storage::disk('local')->get($firstPhotoPath));
        $this->assertStringContainsString('no-store', $photoResponse->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $photoResponse->headers->get('Cache-Control'));

        $this->actingAs($this->admin(), 'sanctum')
            ->post('/api/v1/employees/hris/2020041501/photo', [
                'employee_id' => '2020041501',
                'nik' => self::NIK,
                'photo' => UploadedFile::fake()->image('replacement.png', 64, 64),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.photo_available', true);

        $photoReference->refresh();
        $this->assertNotSame($firstPhotoPath, $photoReference->photo_path);
        Storage::disk('local')->assertMissing($firstPhotoPath);
        Storage::disk('local')->assertExists($photoReference->photo_path);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'https://hris.example.test/api/v1/employees/by-nik/'.self::NIK
            && $request->body() === ''
            && $request->hasHeader('Authorization', 'Bearer '.self::API_TOKEN));
    }

    public function test_employee_photo_view_returns_not_found_without_a_local_photo_reference(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->get('/api/v1/employees/hris/2020041501/photo')
            ->assertNotFound()
            ->assertJsonPath('message', 'Employee photo not found.');
    }

    public function test_employee_photo_upload_rejects_invalid_images_and_unmatched_identity(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://hris.example.test/api/v1/employees*' => Http::response([
                'success' => true,
                'data' => [],
                'links' => [],
                'filters' => [
                    'work_locations' => [],
                    'work_areas' => [],
                ],
                'meta' => [
                    'current_page' => 1,
                    'per_page' => 100,
                    'total' => 0,
                    'last_page' => 1,
                ],
            ]),
        ]);
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->post('/api/v1/employees/hris/2020041501/photo', [
                'employee_id' => '2020041501',
                'nik' => self::NIK,
                'photo' => UploadedFile::fake()->create('not-an-image.jpg', 10, 'text/plain'),
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('photo');

        Http::fake([
            'https://hris.example.test/api/v1/employees*' => Http::response([
                'success' => true,
                'data' => [[
                    'employee_id' => 'UNKNOWN',
                    'nik' => null,
                ]],
                'links' => [],
                'filters' => [
                    'work_locations' => [],
                    'work_areas' => [],
                ],
                'meta' => [
                    'current_page' => 1,
                    'per_page' => 100,
                    'total' => 1,
                    'last_page' => 1,
                ],
            ]),
        ]);
        $this->actingAs($admin, 'sanctum')
            ->post('/api/v1/employees/hris/UNKNOWN/photo', [
                'employee_id' => 'UNKNOWN',
                'photo' => UploadedFile::fake()->image('employee.png', 64, 64),
            ], ['Accept' => 'application/json'])
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('employees', 0);
        $this->assertDatabaseCount('employee_photo_references', 0);
    }

    public function test_employee_photo_upload_requires_employee_update_permission(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $role = Role::findByName('USER');
        $role->syncPermissions(['employees.view']);
        $user->assignRole('USER');

        $this->actingAs($user, 'sanctum')
            ->post('/api/v1/employees/hris/2020041501/photo', [
                'employee_id' => '2020041501',
                'photo' => UploadedFile::fake()->image('employee.jpg', 64, 64),
            ], ['Accept' => 'application/json'])
            ->assertForbidden();

        Storage::disk('local')->assertMissing('employee-photos');
        $this->assertDatabaseCount('employee_photo_references', 0);
    }

    public function test_hris_and_custom_work_location_options_are_available_to_work_location_viewers(): void
    {
        Http::fake([
            'https://hris.example.test/api/v1/employees*' => Http::response([
                'success' => true,
                'data' => [],
                'links' => [],
                'filters' => [
                    'work_locations' => ['Jakarta', 'Bandung'],
                    'work_areas' => ['Head Office', 'Cabang', 'Pabrik', 'Remote'],
                ],
                'meta' => [
                    'current_page' => 1,
                    'per_page' => 1,
                    'total' => 4,
                    'last_page' => 4,
                ],
            ]),
        ]);

        $user = User::factory()->create();
        $role = Role::findByName('USER');
        $role->syncPermissions(['employee_work_location.view']);
        $user->assignRole('USER');
        EmployeeWorkLocationOption::create([
            'type' => 'work_location',
            'name' => 'Surabaya',
            'normalized_name' => 'surabaya',
        ]);
        EmployeeWorkLocationOption::create([
            'type' => 'work_area',
            'name' => 'Warehouse',
            'normalized_name' => 'warehouse',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/employee-work-locations/options')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'data' => [
                    'work_locations' => ['Bandung', 'Jakarta', 'Surabaya'],
                    'work_areas' => ['Cabang', 'Head Office', 'Pabrik', 'Remote', 'Warehouse'],
                ],
            ]);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'per_page=1')
        );
        $this->assertDatabaseCount('employees', 0);
        $this->assertDatabaseCount('employee_work_locations', 0);
    }

    public function test_work_location_write_accepts_custom_hris_work_area_values(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/employee-work-locations', [
                'name' => 'Jakarta - Remote Operations',
                'city' => 'Jakarta',
                'area_type' => 'Remote Operations',
                'address' => 'Jakarta',
                'latitude' => -6.2,
                'longitude' => 106.8,
            ])
            ->assertCreated()
            ->assertJsonPath('data.area_type', 'Remote Operations')
            ->assertJsonPath('data.area_type_label', 'Remote Operations');
    }

    public function test_work_location_options_can_be_added_and_duplicate_names_are_rejected_case_insensitively(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/employee-work-locations/options', [
                'type' => 'work_location',
                'name' => '  Jakarta   Barat ',
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'work_location')
            ->assertJsonPath('data.name', 'Jakarta Barat');

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/employee-work-locations/options', [
                'type' => 'work_location',
                'name' => 'JAKARTA BARAT',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->assertDatabaseCount('employee_work_location_options', 1);
    }

    public function test_only_work_location_creators_can_add_custom_options(): void
    {
        $user = User::factory()->create();
        $role = Role::findByName('USER');
        $role->syncPermissions(['employee_work_location.view']);
        $user->assignRole('USER');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/employee-work-locations/options', [
                'type' => 'work_area',
                'name' => 'Warehouse',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('employee_work_location_options', 0);
    }

    public function test_work_location_options_reject_invalid_hris_employee_response(): void
    {
        Http::fake([
            'https://hris.example.test/api/v1/employees*' => Http::response([
                'success' => true,
                'data' => [],
                'links' => [],
                'filters' => [
                    'work_locations' => 'invalid',
                    'work_areas' => [],
                ],
                'meta' => [
                    'current_page' => 1,
                    'per_page' => 1,
                    'total' => 0,
                    'last_page' => 1,
                ],
            ]),
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/employee-work-locations/options')
            ->assertStatus(502)
            ->assertJsonPath('success', false);
    }

    public function test_hris_employee_locations_are_assigned_by_nik_without_copying_profile_data(): void
    {
        $employee = Employee::factory()->create([
            'employee_code' => '2020041501',
            'nik' => null,
            'full_name' => 'Existing Attendance Name',
        ]);
        $location = EmployeeWorkLocation::factory()->create(['name' => 'Head Office']);

        Http::fake([
            'https://hris.example.test/api/v1/employees/by-nik/*' => Http::response([
                'success' => true,
                'data' => [
                    'employee_id' => '2020041501',
                    'nik' => self::NIK,
                    'full_name' => 'Name from HRIS',
                ],
            ]),
        ]);

        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/employees/hris/'.self::NIK.'/work-locations', [
                'work_location_ids' => [$location->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.employee_nik', self::NIK)
            ->assertJsonPath('data.work_location_ids.0', $location->id);

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'employee_code' => '2020041501',
            'nik' => self::NIK,
            'full_name' => 'Existing Attendance Name',
        ]);
        $this->assertDatabaseHas('employee_work_location_assignments', [
            'employee_nik' => self::NIK,
            'employee_work_location_id' => $location->id,
            'status' => 'active',
        ]);
        $this->assertTrue($employee->fresh()->activeWorkLocations->contains('id', $location->id));

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/employees/hris/'.self::NIK.'/work-locations')
            ->assertOk()
            ->assertJsonPath('data.0.id', $location->id);

        $this->assertDatabaseCount('employees', 1);
    }

    public function test_hris_employee_location_assignment_requires_a_matching_attendance_employee(): void
    {
        Http::fake([
            'https://hris.example.test/api/v1/employees/by-nik/*' => Http::response([
                'success' => true,
                'data' => [
                    'employee_id' => '2020041501',
                    'nik' => self::NIK,
                    'full_name' => 'Name from HRIS',
                ],
            ]),
        ]);

        $location = EmployeeWorkLocation::factory()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/employees/hris/'.self::NIK.'/work-locations', [
                'work_location_ids' => [$location->id],
            ])
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('employees', 0);
        $this->assertDatabaseCount('employee_work_location_assignments', 0);
    }

    public function test_invalid_work_location_filter_is_rejected_before_calling_hris(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/employees/hris?work_location[]=Jakarta')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('work_location');

        Http::assertNothingSent();
    }

    public function test_invalid_hris_employee_list_response_is_rejected(): void
    {
        Http::fake([
            'https://hris.example.test/api/v1/employees*' => Http::response([
                'success' => true,
                'data' => [['employee_id' => '2020041501']],
                'links' => [],
                'filters' => [
                    'work_locations' => [],
                    'work_areas' => [],
                ],
                'meta' => [
                    'current_page' => '1',
                    'per_page' => 25,
                    'total' => 1,
                    'last_page' => 1,
                ],
            ]),
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/employees/hris')
            ->assertStatus(502)
            ->assertJsonPath('success', false);
    }

    public function test_missing_hris_employee_list_endpoint_returns_an_actionable_error(): void
    {
        Http::fake([
            'https://hris.example.test/api/v1/employees*' => Http::response([
                'message' => 'Not Found',
            ], 404),
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/employees/hris')
            ->assertStatus(502)
            ->assertJsonPath(
                'message',
                'HRIS employee list API is not available. Deploy the employee integration API to HRIS.'
            );
    }

    public function test_missing_hris_employee_lookup_endpoint_is_not_reported_as_employee_not_found(): void
    {
        Http::fake([
            'https://hris.example.test/api/v1/employees/by-nik/*' => Http::response([
                'message' => 'Not Found',
            ], 404),
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/employees/hris/by-nik/'.self::NIK)
            ->assertStatus(502)
            ->assertJsonPath(
                'message',
                'HRIS employee lookup API is not available. Deploy the employee integration API to HRIS.'
            );
    }
}
