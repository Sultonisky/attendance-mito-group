<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Outsource\CreateOutsourcePerson;
use App\Actions\Outsource\DeleteOutsourcePerson;
use App\Actions\Outsource\ToggleOutsourcePersonStatus;
use App\Actions\Outsource\UpdateOutsourcePerson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Outsource\StoreOutsourcePersonRequest;
use App\Http\Requests\Outsource\UpdateOutsourcePersonRequest;
use App\Http\Resources\Outsource\OutsourcePersonResource;
use App\Models\Outsource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OutsourcePersonController extends Controller
{
    // ── READ ──────────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search'    => ['nullable', 'string', 'max:100'],
            'city_id'   => ['nullable', 'integer', 'min:1'],
            'store_id'  => ['nullable', 'integer', 'min:1'],
            'status'    => ['nullable', 'string', 'in:active,inactive,all'],
            'per_page'  => ['nullable', 'integer', 'in:10,25,50,100'],
            'sort'      => ['nullable', 'string', 'in:name,outsource_code,status,created_at'],
            'direction' => ['nullable', 'string', 'in:asc,desc'],
        ]);

        $allowedSorts = [
            'name'           => 'o.name',
            'outsource_code' => 'o.outsource_code',
            'status'         => 'o.status',
            'created_at'     => 'o.created_at',
        ];

        $sortCol  = $allowedSorts[$validated['sort'] ?? 'name'] ?? 'o.name';
        $sortDir  = ($validated['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $perPage  = (int) ($validated['per_page'] ?? 25);
        $status   = $validated['status'] ?? 'all';

        $eligibleIds = null;

        if (!empty($validated['store_id'])) {
            $eligibleIds = DB::table('outsource_store_assignments')
                ->where('store_id', (int) $validated['store_id'])
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->pluck('outsource_id')
                ->map(fn ($id) => (int) $id)->unique()->values()->all();
        } elseif (!empty($validated['city_id'])) {
            $eligibleIds = DB::table('outsource_store_assignments as osa')
                ->join('work_locations as wl', 'wl.id', '=', 'osa.store_id')
                ->where('wl.city_id', (int) $validated['city_id'])
                ->where('osa.status', 'active')->whereNull('osa.deleted_at')
                ->where('wl.status', 'active')->whereNull('wl.deleted_at')
                ->pluck('osa.outsource_id')
                ->map(fn ($id) => (int) $id)->unique()->values()->all();
        }

        $query = DB::table('outsources as o')
            ->whereNull('o.deleted_at')
            ->select([
                'o.id',
                'o.outsource_code',
                'o.name',
                'o.status',
                'o.created_at',
                DB::raw('CASE WHEN o.password IS NOT NULL AND o.password <> \'\' THEN true ELSE false END as has_password'),
            ]);

        if ($eligibleIds !== null) {
            $query->whereIn('o.id', $eligibleIds ?: [0]);
        }

        if ($status !== 'all') {
            $query->where('o.status', $status);
        }

        if (!empty($validated['search'])) {
            $term = '%' . $validated['search'] . '%';
            $query->where(function ($q) use ($term): void {
                $q->whereRaw('LOWER(o.name) LIKE LOWER(?)', [$term])
                    ->orWhereRaw('LOWER(o.outsource_code) LIKE LOWER(?)', [$term]);
            });
        }

        $query->orderBy($sortCol, $sortDir);
        $paginated = $query->paginate($perPage);

        $outsourceIds = collect($paginated->items())->pluck('id')->all();
        $assignments  = DB::table('outsource_store_assignments as osa')
            ->join('work_locations as wl', 'wl.id', '=', 'osa.store_id')
            ->leftJoin('cities as c', 'c.id', '=', 'wl.city_id')
            ->whereIn('osa.outsource_id', $outsourceIds)
            ->where('osa.status', 'active')->whereNull('osa.deleted_at')
            ->select([
                'osa.id as assignment_id',
                'osa.outsource_id',
                'wl.id as store_id',
                'wl.name as store_name',
                'c.id as city_id',
                'c.name as city_name',
            ])
            ->get()->keyBy('outsource_id');

        $assignmentIds = $assignments->pluck('assignment_id')->filter()->all();
        $pinsByAssignment = $assignmentIds === []
            ? collect()
            : DB::table('outsource_assignment_pins')
                ->whereIn('assignment_id', $assignmentIds)
                ->get(['assignment_id', 'pin_id'])
                ->groupBy('assignment_id');

        $enriched = collect($paginated->items())->map(function ($row) use ($assignments, $pinsByAssignment) {
            $asgn = $assignments->get($row->id);
            $row->store_id   = $asgn?->store_id;
            $row->store_name = $asgn?->store_name;
            $row->city_id    = $asgn?->city_id;
            $row->city_name  = $asgn?->city_name;
            $row->has_password = (bool) ($row->has_password ?? false);
            $row->pin_ids = $asgn
                ? $pinsByAssignment->get($asgn->assignment_id, collect())->pluck('pin_id')->map(fn ($id) => (int) $id)->values()->all()
                : [];

            return $row;
        });

        return response()->json([
            'success' => true,
            'data' => OutsourcePersonResource::collection($enriched),
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

    public function show(Outsource $outsourcePerson): JsonResponse
    {
        $this->hydratePersonAssignment($outsourcePerson);

        return (new OutsourcePersonResource($outsourcePerson))
            ->additional(['success' => true])
            ->response();
    }

    // ── CREATE ────────────────────────────────────────────────────────────────

    public function store(
        StoreOutsourcePersonRequest $request,
        CreateOutsourcePerson $action,
    ): JsonResponse {
        $person = $action->execute($request->validated(), $request->user(), $request);
        $this->hydratePersonAssignment($person);

        return (new OutsourcePersonResource($person))
            ->additional(['success' => true])
            ->response()
            ->setStatusCode(201);
    }

    // ── UPDATE ────────────────────────────────────────────────────────────────

    public function update(
        UpdateOutsourcePersonRequest $request,
        Outsource $outsourcePerson,
        UpdateOutsourcePerson $action,
    ): JsonResponse {
        $person = $action->execute($outsourcePerson, $request->validated(), $request->user(), $request);
        $this->hydratePersonAssignment($person);

        return (new OutsourcePersonResource($person))
            ->additional(['success' => true])
            ->response();
    }

    // ── TOGGLE STATUS ─────────────────────────────────────────────────────────

    public function toggleStatus(
        Outsource $outsourcePerson,
        ToggleOutsourcePersonStatus $action,
        Request $request,
    ): JsonResponse {
        $person = $action->execute($outsourcePerson, $request->user(), $request);

        return response()->json([
            'success' => true,
            'data'    => ['id' => $person->id, 'status' => $person->status],
        ]);
    }

    // ── DELETE ────────────────────────────────────────────────────────────────

    public function destroy(
        Outsource $outsourcePerson,
        DeleteOutsourcePerson $action,
        Request $request,
    ): JsonResponse {
        $action->execute($outsourcePerson, $request->user(), $request);

        return response()->json(['success' => true], 200);
    }

    private function hydratePersonAssignment(Outsource $person): void
    {
        $asgn = DB::table('outsource_store_assignments as osa')
            ->join('work_locations as wl', 'wl.id', '=', 'osa.store_id')
            ->leftJoin('cities as c', 'c.id', '=', 'wl.city_id')
            ->where('osa.outsource_id', $person->id)
            ->where('osa.status', 'active')->whereNull('osa.deleted_at')
            ->select([
                'osa.id as assignment_id',
                'wl.id as store_id',
                'wl.name as store_name',
                'c.id as city_id',
                'c.name as city_name',
            ])
            ->first();

        $person->store_id   = $asgn?->store_id;
        $person->store_name = $asgn?->store_name;
        $person->city_id    = $asgn?->city_id;
        $person->city_name  = $asgn?->city_name;
        $person->has_password = filled($person->password);
        $person->pin_ids = $asgn
            ? DB::table('outsource_assignment_pins')
                ->where('assignment_id', $asgn->assignment_id)
                ->pluck('pin_id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all()
            : [];
    }
}
