<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Permission\UpdateRolePermissions;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $roles = Role::query()
            ->with('permissions:id,name')
            ->orderBy('id')
            ->get();

        $counts = $this->userCounts();

        return response()->json([
            'success' => true,
            'data' => $roles->map(fn (Role $role) => $this->payload($role, $counts[$role->id] ?? 0))->values()->all(),
        ]);
    }

    public function updatePermissions(Request $request, Role $role, UpdateRolePermissions $update): JsonResponse
    {
        $validated = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', 'exists:permissions,name'],
        ]);

        $role = $update->execute($role, $validated['permissions'], $request->user(), $request);

        return response()->json([
            'success' => true,
            'data' => $this->payload($role, $this->userCounts()[$role->id] ?? 0),
        ]);
    }

    /**
     * withCount('users') resolves Role::users() on a blank model, falling back
     * to the default auth guard (sanctum in API requests, which has no user
     * model) — count the pivot directly instead.
     *
     * @return array<int, int>
     */
    private function userCounts(): array
    {
        return DB::table(config('permission.table_names.model_has_roles'))
            ->where('model_type', (new User)->getMorphClass())
            ->groupBy('role_id')
            ->selectRaw('role_id, count(*) as users_count')
            ->pluck('users_count', 'role_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * @return array{id: int, name: string, users_count: int, editable: bool, permissions: list<string>}
     */
    private function payload(Role $role, int $usersCount): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'users_count' => $usersCount,
            'editable' => $role->name !== 'SUPER_ADMIN',
            'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
        ];
    }
}
