<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Outsource\CreateCity;
use App\Actions\Outsource\CreateWorkLocation;
use App\Actions\Outsource\DeleteWorkLocation;
use App\Actions\Outsource\ToggleWorkLocationStatus;
use App\Actions\Outsource\UpdateWorkLocation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Outsource\StoreCityRequest;
use App\Http\Requests\Outsource\StoreWorkLocationRequest;
use App\Http\Requests\Outsource\UpdateWorkLocationRequest;
use App\Http\Resources\Outsource\OutsourceWorkLocationPinListResource;
use App\Http\Resources\Outsource\OutsourceWorkLocationResource;
use App\Models\City;
use App\Models\WorkLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OutsourceWorkLocationController extends Controller
{
    // ── READ ──────────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search'    => ['nullable', 'string', 'max:255'],
            'city_id'   => ['nullable', 'integer', 'min:1'],
            'status'    => ['nullable', 'string', 'in:active,inactive,all'],
            'per_page'  => ['nullable', 'integer', 'in:10,25,50,100'],
            'sort'      => ['nullable', 'string', 'in:cabang,address,outsource_count,status,pin_name,created_at'],
            'direction' => ['nullable', 'string', 'in:asc,desc'],
        ], [
            'search.max' => 'Search may not exceed 255 characters. Shorten your query and try again.',
        ]);

        $sortDir = ($validated['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $perPage = (int) ($validated['per_page'] ?? 25);
        $status  = $validated['status'] ?? 'active';
        $sortRaw = $validated['sort']   ?? 'cabang';

        // One row per pin/address (not one row per cabang).
        // outsource_count = people who may use this pin (explicit allowlist OR empty = all cabang pins).
        $pinOutsourceCountSql = <<<'SQL'
            (
                SELECT COUNT(DISTINCT a.outsource_id)
                FROM outsource_store_assignments a
                INNER JOIN outsources o ON o.id = a.outsource_id AND o.deleted_at IS NULL
                WHERE a.store_id = wl.id
                  AND a.status = 'active'
                  AND a.deleted_at IS NULL
                  AND (
                    NOT EXISTS (
                        SELECT 1 FROM outsource_assignment_pins ap0 WHERE ap0.assignment_id = a.id
                    )
                    OR EXISTS (
                        SELECT 1 FROM outsource_assignment_pins ap1
                        WHERE ap1.assignment_id = a.id AND ap1.pin_id = p.id
                    )
                  )
            )
            SQL;

        $query = DB::table('work_location_pins as p')
            ->join('work_locations as wl', 'wl.id', '=', 'p.work_location_id')
            ->leftJoin('cities as c', 'c.id', '=', 'wl.city_id')
            ->whereNull('p.deleted_at')
            ->whereNull('wl.deleted_at')
            ->select([
                'p.id',
                'p.work_location_id',
                'p.name as pin_name',
                'p.address',
                'p.latitude',
                'p.longitude',
                'p.radius_meters',
                'p.status',
                'p.created_at',
                'wl.name as cabang_name',
                'wl.code as cabang_code',
                'c.id as city_id',
                'c.name as city_name',
                'c.code as city_code',
                DB::raw("{$pinOutsourceCountSql} as outsource_count"),
            ]);

        if ($status !== 'all') {
            $query->where('p.status', $status);
        }

        if (! empty($validated['city_id'])) {
            $query->where('wl.city_id', (int) $validated['city_id']);
        }

        if (! empty($validated['search'])) {
            $term = '%'.$validated['search'].'%';
            $query->where(function ($q) use ($term): void {
                $q->whereRaw('LOWER(wl.name) LIKE LOWER(?)', [$term])
                    ->orWhereRaw('LOWER(p.name) LIKE LOWER(?)', [$term])
                    ->orWhereRaw('LOWER(COALESCE(p.address, \'\')) LIKE LOWER(?)', [$term])
                    ->orWhereRaw('LOWER(COALESCE(c.name, \'\')) LIKE LOWER(?)', [$term])
                    ->orWhereRaw('LOWER(wl.code) LIKE LOWER(?)', [$term]);
            });
        }

        $sortCol = match ($sortRaw) {
            'address'         => 'p.address',
            'outsource_count' => 'outsource_count',
            'status'          => 'p.status',
            'pin_name'        => 'p.name',
            'created_at'      => 'p.created_at',
            default           => 'wl.name',
        };
        $query->orderBy($sortCol, $sortDir)->orderBy('p.id');

        $paginated = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => OutsourceWorkLocationPinListResource::collection(collect($paginated->items())),
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

    public function show(WorkLocation $outsourceWorkLocation): JsonResponse
    {
        $row = DB::table('work_locations as wl')
            ->leftJoin('cities as c', 'c.id', '=', 'wl.city_id')
            ->leftJoin(
                DB::raw('(SELECT store_id, COUNT(DISTINCT outsource_id) as cnt FROM outsource_store_assignments WHERE status = \'active\' AND deleted_at IS NULL GROUP BY store_id) as osa_counts'),
                'osa_counts.store_id', '=', 'wl.id'
            )
            ->where('wl.id', $outsourceWorkLocation->id)
            ->whereNull('wl.deleted_at')
            ->select([
                'wl.id', 'wl.code', 'wl.name', 'wl.status',
                'wl.latitude', 'wl.longitude', 'wl.radius_meters', 'wl.created_at',
                'c.id as city_id', 'c.name as city_name', 'c.code as city_code',
                DB::raw('COALESCE(osa_counts.cnt, 0) as outsource_count'),
            ])->first();

        if (!$row) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        return (new OutsourceWorkLocationResource($row))
            ->additional(['success' => true])
            ->response();
    }

    // ── CREATE ────────────────────────────────────────────────────────────────

    public function store(
        StoreWorkLocationRequest $request,
        CreateWorkLocation $action,
    ): JsonResponse {
        $location = $action->execute($request->validated(), $request->user(), $request);

        $row = $this->enrichRow($location->id);

        return (new OutsourceWorkLocationResource($row))
            ->additional(['success' => true])
            ->response()->setStatusCode(201);
    }

    // ── UPDATE ────────────────────────────────────────────────────────────────

    public function update(
        UpdateWorkLocationRequest $request,
        WorkLocation $outsourceWorkLocation,
        UpdateWorkLocation $action,
    ): JsonResponse {
        $location = $action->execute($outsourceWorkLocation, $request->validated(), $request->user(), $request);

        $row = $this->enrichRow($location->id);

        return (new OutsourceWorkLocationResource($row))
            ->additional(['success' => true])
            ->response();
    }

    // ── TOGGLE STATUS ─────────────────────────────────────────────────────────

    public function toggleStatus(
        WorkLocation $outsourceWorkLocation,
        ToggleWorkLocationStatus $action,
        Request $request,
    ): JsonResponse {
        $location = $action->execute($outsourceWorkLocation, $request->user(), $request);

        return response()->json([
            'success' => true,
            'data'    => ['id' => $location->id, 'status' => $location->status],
        ]);
    }

    // ── DELETE ────────────────────────────────────────────────────────────────

    public function destroy(
        WorkLocation $outsourceWorkLocation,
        DeleteWorkLocation $action,
        Request $request,
    ): JsonResponse {
        $action->execute($outsourceWorkLocation, $request->user(), $request);

        return response()->json(['success' => true], 200);
    }

    // ── Cities list (for dropdowns) ───────────────────────────────────────────

    public function cities(): JsonResponse
    {
        $cities = City::where('status', 'active')
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        return response()->json(['success' => true, 'data' => $cities]);
    }

    public function storeCity(StoreCityRequest $request, CreateCity $action): JsonResponse
    {
        $city = $action->execute($request->validated(), $request->user(), $request);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $city->id,
                'name' => $city->name,
                'code' => $city->code,
            ],
        ], 201);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function enrichRow(int $id): object
    {
        return DB::table('work_locations as wl')
            ->leftJoin('cities as c', 'c.id', '=', 'wl.city_id')
            ->leftJoin(
                DB::raw('(SELECT store_id, COUNT(DISTINCT outsource_id) as cnt FROM outsource_store_assignments WHERE status = \'active\' AND deleted_at IS NULL GROUP BY store_id) as osa_counts'),
                'osa_counts.store_id', '=', 'wl.id'
            )
            ->where('wl.id', $id)
            ->whereNull('wl.deleted_at')
            ->select([
                'wl.id', 'wl.code', 'wl.name', 'wl.status',
                'wl.latitude', 'wl.longitude', 'wl.radius_meters', 'wl.created_at',
                'c.id as city_id', 'c.name as city_name', 'c.code as city_code',
                DB::raw('COALESCE(osa_counts.cnt, 0) as outsource_count'),
            ])->first();
    }
}
