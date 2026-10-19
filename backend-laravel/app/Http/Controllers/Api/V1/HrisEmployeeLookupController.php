<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Employee\SyncEmployeeWorkLocations;
use App\Exceptions\Integration\HrisEmployeeApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreEmployeePhotoRequest;
use App\Http\Resources\Employee\EmployeeWorkLocationResource;
use App\Models\Employee;
use App\Models\EmployeePhotoReference;
use App\Models\EmployeeWorkLocation;
use App\Services\Integration\HrisEmployeeApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

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

        $employeeCodes = array_column($employees['data'], 'employee_id');
        $niks = array_values(array_filter(
            array_column($employees['data'], 'nik'),
            static fn ($nik): bool => is_string($nik) && $nik !== '',
        ));
        $photoReferences = EmployeePhotoReference::query()
            ->where(function ($query) use ($employeeCodes, $niks): void {
                $query->whereIn('hris_employee_id', $employeeCodes);
                if ($niks !== []) {
                    $query->orWhereIn('hris_nik', $niks);
                }
            })
            ->get(['id', 'hris_employee_id', 'hris_nik', 'photo_path']);
        $photosByCode = $photoReferences->keyBy('hris_employee_id');
        $photosByNik = $photoReferences->filter(fn (EmployeePhotoReference $photo): bool => $photo->hris_nik !== null)
            ->keyBy('hris_nik');

        $employees['data'] = array_map(function (array $employee) use ($photosByCode, $photosByNik): array {
            $byCode = $photosByCode->get($employee['employee_id']);
            $byNik = is_string($employee['nik'] ?? null)
                ? $photosByNik->get($employee['nik'])
                : null;
            $identityConflict = $byCode !== null && $byNik !== null && $byCode->isNot($byNik);
            $photoPath = $identityConflict ? null : ($byCode ?? $byNik)?->photo_path;

            return [
                ...$employee,
                'photo_available' => is_string($photoPath) && trim($photoPath) !== '',
            ];
        }, $employees['data']);

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

    public function storePhoto(StoreEmployeePhotoRequest $request, string $employeeId): JsonResponse
    {
        $validated = $request->validated();
        if ($validated['employee_id'] !== $employeeId) {
            return response()->json([
                'success' => false,
                'message' => 'The submitted employee ID does not match the requested employee.',
            ], 422);
        }

        try {
            $hrisEmployee = isset($validated['nik'])
                ? $this->hrisEmployees->findByNik($validated['nik'])
                : $this->findHrisEmployeeById($employeeId);
        } catch (HrisEmployeeApiException $exception) {
            return $this->integrationError($exception);
        }

        if ($hrisEmployee === null || $hrisEmployee['employee_id'] !== $employeeId) {
            return response()->json([
                'success' => false,
                'message' => 'The employee could not be verified against HRIS.',
            ], 409);
        }
        $verifiedNik = $hrisEmployee['nik'] ?? null;

        $photoByCode = EmployeePhotoReference::query()
            ->where('hris_employee_id', $employeeId)
            ->first();
        $photoByNik = is_string($verifiedNik)
            ? EmployeePhotoReference::query()->where('hris_nik', $verifiedNik)->first()
            : null;

        if ($photoByCode !== null && $photoByNik !== null && $photoByCode->isNot($photoByNik)) {
            return $this->employeePhotoIdentityConflict();
        }

        $photoReference = $photoByCode ?? $photoByNik;
        $photoNik = $verifiedNik ?? $photoReference?->hris_nik;

        if (
            $photoByCode !== null
            && is_string($verifiedNik)
            && $photoReference->hris_nik !== null
            && $photoReference->hris_nik !== $verifiedNik
        ) {
            return $this->employeePhotoIdentityConflict();
        }

        $photo = $request->file('photo');
        $extension = strtolower($photo->extension());
        $newPath = $photo->storeAs('employee-photos', Str::uuid().'.'.$extension, 'local');

        if (! is_string($newPath) || $newPath === '') {
            Log::error('Failed to store employee photo.', ['hris_employee_id' => $employeeId]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to store the employee photo.',
            ], 500);
        }

        $oldPath = $photoReference?->photo_path;
        try {
            DB::transaction(function () use ($photoReference, $employeeId, $photoNik, $newPath): void {
                $photoReference ??= new EmployeePhotoReference;
                $photoReference->forceFill([
                    'hris_employee_id' => $employeeId,
                    'hris_nik' => $photoNik,
                    'photo_path' => $newPath,
                ])->save();
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($newPath);
            throw $exception;
        }

        if (is_string($oldPath) && str_starts_with($oldPath, 'employee-photos/')) {
            if (! Storage::disk('local')->delete($oldPath)) {
                Log::warning('Replaced employee photo could not be removed from private storage.', [
                    'hris_employee_id' => $employeeId,
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'photo_available' => true,
            ],
        ]);
    }

    public function showPhoto(Request $request, string $employeeId): JsonResponse|StreamedResponse
    {
        $validated = $request->validate([
            'nik' => ['sometimes', 'nullable', 'digits:16'],
        ]);

        $photoByCode = EmployeePhotoReference::query()
            ->where('hris_employee_id', $employeeId)
            ->first();
        $photoByNik = isset($validated['nik'])
            ? EmployeePhotoReference::query()->where('hris_nik', $validated['nik'])->first()
            : null;

        if ($photoByCode !== null && $photoByNik !== null && $photoByCode->isNot($photoByNik)) {
            return $this->employeePhotoIdentityConflict();
        }

        $photoReference = $photoByCode ?? $photoByNik;
        $photoPath = $photoReference?->photo_path;
        $disk = Storage::disk('local');

        if (
            ! is_string($photoPath)
            || ! str_starts_with($photoPath, 'employee-photos/')
            || ! $disk->exists($photoPath)
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Employee photo not found.',
            ], 404);
        }

        return $disk->response($photoPath, null, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
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

    private function employeePhotoIdentityConflict(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'The HRIS employee ID and NIK point to different Attendance employee records.',
        ], 409);
    }

    /**
     * @return array<string, string|null>|null
     */
    private function findHrisEmployeeById(string $employeeId): ?array
    {
        $response = $this->hrisEmployees->listEmployees([
            'search' => $employeeId,
            'page' => 1,
            'per_page' => 100,
        ]);

        foreach ($response['data'] as $employee) {
            if (($employee['employee_id'] ?? null) === $employeeId) {
                return $employee;
            }
        }

        return null;
    }
}
