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
            'actor_id' => ['nullable', 'integer', 'exists:users,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', 'string', 'in:id,action,actor_id,created_at'],
            'direction' => ['nullable', 'string', 'in:asc,desc'],
        ]);

        $query = AuditLog::query()->with('actor:id,name,email');

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('action', 'like', "%{$search}%")
                    ->orWhere('auditable_type', 'like', "%{$search}%")
                    ->orWhereHas('actor', function ($q2) use ($search) {
                        $q2->whereRaw('LOWER(name) LIKE LOWER(?)', ["%{$search}%"])
                            ->orWhereRaw('LOWER(email) LIKE LOWER(?)', ["%{$search}%"]);
                    });
            });
        }

        if (!empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }
        if (!empty($filters['action'])) {
            $query->where('action', $filters['action']);
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
}
