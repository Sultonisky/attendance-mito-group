<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Employee\SyncEmployeeWorkLocations;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Http\Requests\Employee\UpdateEmployeeRequest;
use App\Http\Resources\Employee\EmployeeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use App\Models\Employee;

class EmployeeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'work_location_id' => ['nullable', 'integer'],
            'sort' => ['nullable', 'string', 'in:id,employee_code,full_name,email,employment_status,join_date,created_at'],
            'direction' => ['nullable', 'string', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = Employee::query()->with('activeWorkLocations');

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('employee_code', 'like', "%{$search}%")
                  ->orWhere('full_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if (!empty($filters['work_location_id'])) {
            $locationId = (int) $filters['work_location_id'];
            $query->whereHas('activeWorkLocations', fn ($q) => $q->where('employee_work_locations.id', $locationId));
        }

        if (!empty($filters['sort'])) {
            $direction = $filters['direction'] ?? 'asc';
            $query->orderBy($filters['sort'], $direction);
        } else {
            $query->orderBy('employee_code', 'asc');
        }

        $perPage = (int) ($filters['per_page'] ?? 25);

        $employees = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => EmployeeResource::collection($employees),
            'meta' => [
                'current_page' => $employees->currentPage(),
                'last_page'    => $employees->lastPage(),
                'per_page'     => $employees->perPage(),
                'total'        => $employees->total(),
                'from'         => $employees->firstItem(),
                'to'           => $employees->lastItem(),
            ],
        ]);
    }

    public function store(StoreEmployeeRequest $request, SyncEmployeeWorkLocations $syncWorkLocations): JsonResponse
    {
        $validated = $request->validated();

        $employee = DB::transaction(function () use ($validated, $request, $syncWorkLocations): Employee {
            $employee = Employee::create(Arr::except($validated, ['work_location_ids']));

            if (!empty($validated['work_location_ids'])) {
                $syncWorkLocations->execute($employee, $validated['work_location_ids'], $request->user(), $request);
            }

            return $employee;
        });

        return (new EmployeeResource($employee->load('activeWorkLocations')))
            ->additional(['success' => true])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Employee $employee): JsonResponse
    {
        return (new EmployeeResource($employee->load('activeWorkLocations')))
            ->additional(['success' => true])
            ->response();
    }

    public function update(
        UpdateEmployeeRequest $request,
        Employee $employee,
        SyncEmployeeWorkLocations $syncWorkLocations,
    ): JsonResponse {
        $validated = $request->validated();

        DB::transaction(function () use ($validated, $employee, $request, $syncWorkLocations): void {
            $employee->update(Arr::except($validated, ['work_location_ids']));

            if (array_key_exists('work_location_ids', $validated)) {
                $syncWorkLocations->execute($employee, $validated['work_location_ids'] ?? [], $request->user(), $request);
            }
        });

        return (new EmployeeResource($employee->load('activeWorkLocations')))
            ->additional(['success' => true])
            ->response();
    }

    public function destroy(Employee $employee): JsonResponse
    {
        $employee->delete();

        return response()->json(['success' => true]);
    }
}
