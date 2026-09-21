<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\User\CreateUser;
use App\Actions\User\DeleteUser;
use App\Actions\User\ToggleUserStatus;
use App\Actions\User\UpdateUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\User\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    // ── READ ──────────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search'    => ['nullable', 'string', 'max:100'],
            'role'      => ['nullable', 'string', 'in:SUPER_ADMIN,ADMIN,USER,all'],
            'status'    => ['nullable', 'string', 'in:active,inactive,all'],
            'per_page'  => ['nullable', 'integer', 'in:10,25,50,100'],
            'sort'      => ['nullable', 'string', 'in:name,email,status,created_at'],
            'direction' => ['nullable', 'string', 'in:asc,desc'],
        ]);

        $perPage   = (int) ($validated['per_page']  ?? 25);
        $sortCol   = $validated['sort']      ?? 'name';
        $sortDir   = ($validated['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $status    = $validated['status']    ?? 'all';
        $roleFilter = $validated['role']     ?? 'all';

        $query = User::with('roles')
            ->orderBy($sortCol, $sortDir);

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($roleFilter !== 'all') {
            $query->role($roleFilter); // Spatie helper
        }

        if (!empty($validated['search'])) {
            $term = '%' . $validated['search'] . '%';
            $query->where(function ($q) use ($term): void {
                $q->whereRaw('LOWER(name) LIKE LOWER(?)', [$term])
                    ->orWhereRaw('LOWER(email) LIKE LOWER(?)', [$term]);
            });
        }

        $paginated = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => UserResource::collection($paginated),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'from'         => $paginated->firstItem(),
                'to'           => $paginated->lastItem(),
            ],
        ]);
    }

    public function show(User $user): JsonResponse
    {
        $user->load('roles', 'employee');

        return (new UserResource($user))
            ->additional(['success' => true])
            ->response();
    }

    // ── CREATE ────────────────────────────────────────────────────────────────

    public function store(
        StoreUserRequest $request,
        CreateUser $action,
    ): JsonResponse {
        $user = $action->execute($request->validated(), $request->user(), $request);

        return (new UserResource($user))
            ->additional(['success' => true])
            ->response()
            ->setStatusCode(201);
    }

    // ── UPDATE ────────────────────────────────────────────────────────────────

    public function update(
        UpdateUserRequest $request,
        User $user,
        UpdateUser $action,
    ): JsonResponse {
        $updated = $action->execute($user, $request->validated(), $request->user(), $request);

        return (new UserResource($updated))
            ->additional(['success' => true])
            ->response();
    }

    // ── TOGGLE STATUS ─────────────────────────────────────────────────────────

    public function toggleStatus(
        User $user,
        ToggleUserStatus $action,
        Request $request,
    ): JsonResponse {
        // Prevent deactivating yourself
        if ($request->user()?->getKey() === $user->getKey()) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot change your own account status.',
            ], 422);
        }

        $updated = $action->execute($user, $request->user(), $request);

        return response()->json([
            'success' => true,
            'data'    => ['id' => $updated->id, 'status' => $updated->status],
        ]);
    }

    // ── DELETE ────────────────────────────────────────────────────────────────

    public function destroy(
        User $user,
        DeleteUser $action,
        Request $request,
    ): JsonResponse {
        try {
            $action->execute($user, $request->user(), $request);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json(['success' => true]);
    }

    public function listPermissions(Request $request, User $user): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $user->getDirectPermissions()->pluck('name')->values()->all(),
        ]);
    }

    public function permissions(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $user->syncPermissions($request->input('permissions', []));

        return response()->json([
            'success' => true,
            'data' => $user->getDirectPermissions()->pluck('name')->values()->all(),
        ]);
    }
}
