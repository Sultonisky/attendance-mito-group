<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Employee\SyncEmployeeWorkLocations;
use App\Exceptions\Integration\HrisEmployeeApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Employee\EmployeeWorkLocationResource;
use App\Models\Employee;
use App\Models\EmployeeWorkLocation;
use App\Services\Integration\HrisEmployeeApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class HrisEmployeeLookupController extends Controller
{
    public function __construct(
        private readonly HrisEmployeeApiService $hrisEmployees
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'work_location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'work_area' => ['sometimes', 'nullable', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $employees = $this->hrisEmployees->listEmployees($filters);
        } catch (HrisEmployeeApiException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], $exception->httpStatus);
        }

        return response()->json($employees);
    }

    public function show(Request $request, string $nik): JsonResponse
    {
        $validated = validator(['nik' => $nik], [
            'nik' => ['required', 'digits:16'],
        ])->validate();

        try {
            $employee = $this->hrisEmployees->findByNik($validated['nik']);
        } catch (HrisEmployeeApiException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], $exception->httpStatus);
        }

        if ($employee === null) {
            return response()->json([
                'success' => false,
                'message' => 'Employee not found in HRIS.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $employee,
        ]);
    }

    public function workLocations(string $nik): JsonResponse
    {
        validator(['nik' => $nik], ['nik' => ['required', 'digits:16']])->validate();

        try {
            $hrisEmployee = $this->hrisEmployees->findByNik($nik);
        } catch (HrisEmployeeApiException $exception) {
            return $this->integrationError($exception);
        }

        if ($hrisEmployee === null) {
            return response()->json([
                'success' => false,
                'message' => 'Employee not found in HRIS.',
            ], 404);
        }

        $locations = EmployeeWorkLocation::query()
            ->whereIn('id', DB::table('employee_work_location_assignments')
                ->where('employee_nik', $nik)
                ->where('status', 'active')
                ->select('employee_work_location_id'))
            ->where('status', 'active')
            ->orderBy('city')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => EmployeeWorkLocationResource::collection($locations),
        ]);
    }

    public function syncWorkLocations(
        Request $request,
        string $nik,
        SyncEmployeeWorkLocations $syncWorkLocations,
    ): JsonResponse {
        validator(['nik' => $nik], ['nik' => ['required', 'digits:16']])->validate();

        $validated = $request->validate([
            'work_location_ids' => ['required', 'array', 'max:100'],
            'work_location_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('employee_work_locations', 'id')
                    ->where('status', 'active')
                    ->whereNull('deleted_at'),
            ],
        ]);

        try {
            $hrisEmployee = $this->hrisEmployees->findByNik($nik);
        } catch (HrisEmployeeApiException $exception) {
            return $this->integrationError($exception);
        }

        if ($hrisEmployee === null) {
            return response()->json([
                'success' => false,
                'message' => 'Employee not found in HRIS.',
            ], 404);
        }

        $result = DB::transaction(function () use ($hrisEmployee, $nik, $validated, $syncWorkLocations, $request): array {
            $employeeByNik = Employee::query()
                ->where('nik', $nik)
                ->lockForUpdate()
                ->first();
            $employeeByCode = Employee::query()
                ->where('employee_code', $hrisEmployee['employee_id'])
                ->lockForUpdate()
                ->first();

            if ($employeeByNik !== null && $employeeByCode !== null && $employeeByNik->isNot($employeeByCode)) {
                return ['error' => 'The HRIS NIK and employee ID point to different Attendance employee records.'];
            }

            $employee = $employeeByNik ?? $employeeByCode;

            if ($employee === null) {
                return ['error' => 'No existing Attendance employee matches this HRIS employee ID. Link the Attendance employee record before assigning locations.'];
            }

            if ($employee->nik !== null && $employee->nik !== $nik) {
                return ['error' => 'This Attendance employee record is already linked to a different NIK.'];
            }

            $nikInUse = Employee::query()
                ->where('nik', $nik)
                ->whereKeyNot($employee->getKey())
                ->exists();

            if ($nikInUse) {
                return ['error' => 'This NIK is already linked to another Attendance employee record.'];
            }

            if ($employee->nik === null) {
                $employee->forceFill(['nik' => $nik])->save();
            }

            $ids = $syncWorkLocations->execute(
                $employee,
                $validated['work_location_ids'],
                $request->user(),
                $request,
            );

            return ['error' => null, 'work_location_ids' => $ids];
        });

        if ($result['error'] !== null) {
            return response()->json([
                'success' => false,
                'message' => $result['error'],
            ], 409);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'employee_nik' => $nik,
                'work_location_ids' => $result['work_location_ids'],
            ],
        ]);
    }

    private function integrationError(HrisEmployeeApiException $exception): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $exception->getMessage(),
        ], $exception->httpStatus);
    }
}
