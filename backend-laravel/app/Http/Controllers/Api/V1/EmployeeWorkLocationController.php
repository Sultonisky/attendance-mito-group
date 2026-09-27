<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Employee\CreateEmployeeWorkLocation;
use App\Actions\Employee\DeleteEmployeeWorkLocation;
use App\Actions\Employee\UpdateEmployeeWorkLocation;
use App\Enums\WorkAreaType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreEmployeeWorkLocationRequest;
use App\Http\Requests\Employee\UpdateEmployeeWorkLocationRequest;
use App\Http\Resources\Employee\EmployeeWorkLocationResource;
use App\Models\EmployeeWorkLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeeWorkLocationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'area_type' => ['nullable', Rule::enum(WorkAreaType::class)],
            'status' => ['nullable', 'string', 'in:active,inactive,all'],
            'sort' => ['nullable', 'string', 'in:name,city,area_type,status,created_at'],
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
        $query->orderBy($filters['sort'] ?? 'city', $direction)->orderBy('name')->orderBy('id');

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
     * Distinct cities for filter / form suggestions.
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
