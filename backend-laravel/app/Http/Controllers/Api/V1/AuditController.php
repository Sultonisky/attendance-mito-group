<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Report\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'action' => ['nullable', 'string', 'max:255'],
            'actor_kind' => ['nullable', 'string', 'in:user,outsource,system'],
            'actor_id' => ['nullable', 'integer', 'exists:users,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', 'string', 'in:id,action,actor_id,auditable_type,auditable_id,ip_address,created_at'],
            'direction' => ['nullable', 'string', 'in:asc,desc'],
        ]);

        $query = AuditLog::query()->with('actor:id,name,email');

        if (!empty($filters['search'])) {
            $query->search($filters['search']);
        }

        $query->createdBetweenDates($filters['from'] ?? null, $filters['to'] ?? null);

        if (!empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }
        if (!empty($filters['actor_kind'])) {
            $query->actorKind($filters['actor_kind']);
        }
        if (!empty($filters['actor_id'])) {
            $query->where('actor_id', $filters['actor_id']);
        }

        $sort = $filters['sort'] ?? 'created_at';
        $direction = strtolower($filters['direction'] ?? 'desc') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sort, $direction);

        $perPage = (int) ($filters['per_page'] ?? 25);
        $auditLogs = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => AuditLogResource::collection($auditLogs),
            'meta' => [
                'current_page' => $auditLogs->currentPage(),
                'last_page' => $auditLogs->lastPage(),
                'per_page' => $auditLogs->perPage(),
                'total' => $auditLogs->total(),
                'from' => $auditLogs->firstItem(),
                'to' => $auditLogs->lastItem(),
            ],
        ]);
    }

    /**
     * Distinct recorded action names for the audit log action filter.
     */
    public function actions(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => AuditLog::query()
                ->distinct()
                ->orderBy('action')
                ->pluck('action')
                ->values(),
        ]);
    }
}
