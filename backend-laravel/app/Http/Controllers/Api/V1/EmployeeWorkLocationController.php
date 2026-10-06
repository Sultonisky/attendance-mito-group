<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Employee\CreateEmployeeWorkLocation;
use App\Actions\Employee\CreateEmployeeWorkLocationOption;
use App\Actions\Employee\DeleteEmployeeWorkLocation;
use App\Actions\Employee\UpdateEmployeeWorkLocation;
use App\Exceptions\Integration\HrisEmployeeApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreEmployeeWorkLocationRequest;
use App\Http\Requests\Employee\UpdateEmployeeWorkLocationRequest;
use App\Http\Resources\Employee\EmployeeWorkLocationResource;
use App\Models\Employee;
use App\Models\EmployeeWorkLocation;
use App\Models\EmployeeWorkLocationOption;
use App\Services\Integration\HrisEmployeeApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeeWorkLocationController extends Controller
{
    public function options(HrisEmployeeApiService $hrisEmployees): JsonResponse
    {
        try {
            $hrisOptions = $hrisEmployees->listWorkLocationOptions();
        } catch (HrisEmployeeApiException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], $exception->httpStatus);
        }

        $customOptions = EmployeeWorkLocationOption::query()
            ->orderBy('name')
            ->get(['type', 'name'])
            ->groupBy('type');

        return response()->json([
            'success' => true,
            'data' => [
                'work_locations' => $this->mergeOptions(
                    $hrisOptions['work_locations'],
                    $customOptions->get('work_location', collect())->pluck('name')->all(),
                ),
                'work_areas' => $this->mergeOptions(
                    $hrisOptions['work_areas'],
                    $customOptions->get('work_area', collect())->pluck('name')->all(),
                ),
            ],
        ]);
    }

    public function storeOption(Request $request, CreateEmployeeWorkLocationOption $action): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(['work_location', 'work_area'])],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $option = $action->execute($validated, $request->user(), $request);

        return response()->json([
            'success' => true,
            'data' => [
                'type' => $option->type,
                'name' => $option->name,
            ],
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'area_type' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:active,inactive,all'],
            'sort' => ['nullable', 'string', 'in:name,city,area_type,address,pins,status,created_at'],
            'direction' => ['nullable', 'string', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = EmployeeWorkLocation::query()->withCount(['activeEmployees as employee_count']);

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($term): void {
                $q->whereRaw('LOWER(name) LIKE LOWER(?)', [$term])
                    ->orWhereRaw('LOWER(city) LIKE LOWER(?)', [$term])
                    ->orWhereRaw('LOWER(COALESCE(address, \'\')) LIKE LOWER(?)', [$term]);
            });
        }

        if (! empty($filters['city'])) {
            $query->where('city', $filters['city']);
        }

        if (! empty($filters['area_type'])) {
            $query->where('area_type', $filters['area_type']);
        }

        $status = $filters['status'] ?? 'all';
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $sort = $filters['sort'] ?? 'city';
        $sortColumn = $sort === 'pins' ? 'name' : $sort;
        $query->orderBy($sortColumn, $direction)->orderBy('name')->orderBy('id');

        $paginated = $query->paginate((int) ($filters['per_page'] ?? 25));

        return response()->json([
            'success' => true,
            'data' => EmployeeWorkLocationResource::collection($paginated->items()),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'from' => $paginated->firstItem(),
                'to' => $paginated->lastItem(),
            ],
        ]);
    }

    /**
     * Distinct locally configured cities for filters.
     */
    public function cities(): JsonResponse
    {
        $cities = EmployeeWorkLocation::query()
            ->select('city')
            ->distinct()
            ->orderBy('city')
            ->pluck('city');

        return response()->json(['success' => true, 'data' => $cities]);
    }

    public function employees(EmployeeWorkLocation $employeeWorkLocation): JsonResponse
    {
        $employees = $employeeWorkLocation->activeEmployees()
            ->orderBy('full_name')
            ->get([
                'employees.id',
                'employees.employee_code',
                'employees.nik',
                'employees.full_name',
            ])
            ->map(fn (Employee $employee): array => [
                'id' => $employee->id,
                'employee_code' => $employee->employee_code,
                'nik' => $employee->nik,
                'full_name' => $employee->full_name,
            ]);

        return response()->json([
            'success' => true,
            'data' => $employees,
        ]);
    }

    /**
     * @param  list<string>  $hrisOptions
     * @param  list<string>  $customOptions
     * @return list<string>
     */
    private function mergeOptions(array $hrisOptions, array $customOptions): array
    {
        $options = [];
        foreach (array_merge($hrisOptions, $customOptions) as $option) {
            $name = trim($option);
            if ($name !== '') {
                $options[mb_strtolower($name)] ??= $name;
            }
        }

        $options = array_values($options);
        sort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }

    public function show(EmployeeWorkLocation $employeeWorkLocation): JsonResponse
    {
        return (new EmployeeWorkLocationResource($employeeWorkLocation))
            ->additional(['success' => true])
            ->response();
    }

    public function store(StoreEmployeeWorkLocationRequest $request, CreateEmployeeWorkLocation $action): JsonResponse
    {
        $location = $action->execute($request->validated(), $request->user(), $request);

        return (new EmployeeWorkLocationResource($location))
            ->additional(['success' => true])
            ->response()
            ->setStatusCode(201);
    }

    public function update(
        UpdateEmployeeWorkLocationRequest $request,
        EmployeeWorkLocation $employeeWorkLocation,
        UpdateEmployeeWorkLocation $action,
    ): JsonResponse {
        $location = $action->execute($employeeWorkLocation, $request->validated(), $request->user(), $request);

        return (new EmployeeWorkLocationResource($location))
            ->additional(['success' => true])
            ->response();
    }

    public function destroy(
        Request $request,
        EmployeeWorkLocation $employeeWorkLocation,
        DeleteEmployeeWorkLocation $action,
    ): JsonResponse {
        $action->execute($employeeWorkLocation, $request->user(), $request);

        return response()->json(['success' => true]);
    }
}
