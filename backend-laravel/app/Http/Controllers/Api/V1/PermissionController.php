<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Permission\StorePermissionRequest;
use App\Http\Requests\Permission\UpdatePermissionRequest;
use App\Http\Resources\Permission\PermissionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', 'string', 'in:id,name,description,created_at'],
            'direction' => ['nullable', 'string', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = Permission::query();

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where('name', 'like', "%{$search}%");
        }

        if (!empty($filters['sort'])) {
            $direction = $filters['direction'] ?? 'asc';
            $query->orderBy($filters['sort'], $direction);
        } else {
            $query->orderBy('name', 'asc');
        }

        $perPage = (int) ($filters['per_page'] ?? 25);

        $permissions = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => PermissionResource::collection($permissions),
            'meta' => [
                'current_page' => $permissions->currentPage(),
                'last_page'    => $permissions->lastPage(),
                'per_page'     => $permissions->perPage(),
                'total'        => $permissions->total(),
                'from'         => $permissions->firstItem(),
                'to'           => $permissions->lastItem(),
            ],
        ]);
    }

    public function store(StorePermissionRequest $request): JsonResponse
    {
        $permission = Permission::create([
            'name' => $request->validated('name'),
            'guard_name' => 'web',
        ]);

        return (new PermissionResource($permission))
            ->additional(['success' => true])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Permission $permission): JsonResponse
    {
        return (new PermissionResource($permission))
            ->additional(['success' => true])
            ->response();
    }

    public function update(UpdatePermissionRequest $request, Permission $permission): JsonResponse
    {
        $permission->update([
            'name' => $request->validated('name'),
        ]);

        return (new PermissionResource($permission))
            ->additional(['success' => true])
            ->response();
    }

    public function destroy(Permission $permission): JsonResponse
    {
        $permission->delete();

        return response()->json(['success' => true]);
    }
}
