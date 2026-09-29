<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Permission\GrantUserPermission;
use App\Actions\Permission\RevokeUserPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Permission\StorePermissionRequest;
use App\Http\Requests\Permission\UpdatePermissionRequest;
use App\Http\Resources\Permission\PermissionResource;
use App\Models\User;
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

    /**
     * Users holding this permission. Access is per user; SUPER_ADMIN always
     * has it (Gate::before) and is not `removable`.
     */
    public function users(Request $request, Permission $permission): JsonResponse
    {
        $users = User::query()
            ->select('users.id', 'users.name', 'users.email', 'users.status')
            ->with('roles:id,name')
            ->where(fn ($q) => $q
                ->whereHas('permissions', fn ($p) => $p->where('permissions.id', $permission->id))
                ->orWhereHas('roles', fn ($r) => $r->where('name', 'SUPER_ADMIN')))
            ->orderBy('users.name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $users->map(function (User $user) {
                $role = $user->roles->first()?->name;
                $superAdmin = $role === 'SUPER_ADMIN';

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'status' => $user->status,
                    'role' => $role,
                    'source' => $superAdmin ? 'super_admin' : 'direct',
                    'removable' => ! $superAdmin,
                ];
            })->values()->all(),
        ]);
    }

    public function assignUser(Request $request, Permission $permission, GrantUserPermission $grant): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $grant->execute($permission, User::findOrFail($validated['user_id']), $request->user(), $request);

        return response()->json(['success' => true]);
    }

    public function revokeUser(Request $request, Permission $permission, User $user, RevokeUserPermission $revoke): JsonResponse
    {
        $revoke->execute($permission, $user, $request->user(), $request);

        return response()->json(['success' => true]);
    }
}
