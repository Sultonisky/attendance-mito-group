<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Outsource\CreateWorkLocationPin;
use App\Actions\Outsource\DeleteWorkLocationPin;
use App\Actions\Outsource\UpdateWorkLocationPin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Outsource\StoreWorkLocationPinRequest;
use App\Http\Requests\Outsource\UpdateWorkLocationPinRequest;
use App\Http\Resources\Outsource\WorkLocationPinResource;
use App\Models\WorkLocation;
use App\Models\WorkLocationPin;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class OutsourceWorkLocationPinController extends Controller
{
    public function index(WorkLocation $outsourceWorkLocation): JsonResponse
    {
        $pins = $outsourceWorkLocation->pins()
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => WorkLocationPinResource::collection($pins),
        ]);
    }

    public function store(
        StoreWorkLocationPinRequest $request,
        WorkLocation $outsourceWorkLocation,
        CreateWorkLocationPin $action,
    ): JsonResponse {
        $pin = $action->execute(
            $outsourceWorkLocation,
            $request->validated(),
            $request->user(),
            $request,
        );

        return response()->json([
            'success' => true,
            'data' => new WorkLocationPinResource($pin),
        ], 201);
    }

    public function update(
        UpdateWorkLocationPinRequest $request,
        WorkLocation $outsourceWorkLocation,
        WorkLocationPin $pin,
        UpdateWorkLocationPin $action,
    ): JsonResponse {
        $this->assertPinBelongsToLocation($outsourceWorkLocation, $pin);

        $updated = $action->execute($pin, $request->validated(), $request->user(), $request);

        return response()->json([
            'success' => true,
            'data' => new WorkLocationPinResource($updated),
        ]);
    }

    public function destroy(
        WorkLocation $outsourceWorkLocation,
        WorkLocationPin $pin,
        DeleteWorkLocationPin $action,
    ): JsonResponse {
        $this->assertPinBelongsToLocation($outsourceWorkLocation, $pin);

        $action->execute($pin, request()->user(), request());

        return response()->json([
            'success' => true,
            'message' => 'Pin deleted.',
        ]);
    }

    /**
     * Outsource people who may attend at this pin
     * (explicit allowlist, or empty allowlist = all cabang pins).
     */
    public function outsources(
        WorkLocation $outsourceWorkLocation,
        WorkLocationPin $pin,
    ): JsonResponse {
        $this->assertPinBelongsToLocation($outsourceWorkLocation, $pin);

        $rows = DB::table('outsource_store_assignments as a')
            ->join('outsources as o', 'o.id', '=', 'a.outsource_id')
            ->where('a.store_id', $outsourceWorkLocation->id)
            ->where('a.status', 'active')
            ->whereNull('a.deleted_at')
            ->whereNull('o.deleted_at')
            ->where(function ($q) use ($pin): void {
                $q->whereNotExists(function ($sub) {
                    $sub->selectRaw('1')
                        ->from('outsource_assignment_pins as ap0')
                        ->whereColumn('ap0.assignment_id', 'a.id');
                })->orWhereExists(function ($sub) use ($pin) {
                    $sub->selectRaw('1')
                        ->from('outsource_assignment_pins as ap1')
                        ->whereColumn('ap1.assignment_id', 'a.id')
                        ->where('ap1.pin_id', $pin->id);
                });
            })
            ->orderBy('o.name')
            ->select([
                'o.id',
                'o.name',
                'o.outsource_code',
                'o.status',
                'a.id as assignment_id',
            ])
            ->get();

        $explicitPinAssignmentIds = DB::table('outsource_assignment_pins')
            ->where('pin_id', $pin->id)
            ->pluck('assignment_id')
            ->all();
        $explicitSet = array_fill_keys(array_map('intval', $explicitPinAssignmentIds), true);

        $data = $rows->map(static function ($row) use ($explicitSet): array {
            $assignmentId = (int) $row->assignment_id;

            return [
                'id' => (int) $row->id,
                'name' => $row->name,
                'outsource_code' => $row->outsource_code,
                'status' => $row->status,
                'assignment_scope' => isset($explicitSet[$assignmentId]) ? 'pin' : 'all_cabang_pins',
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    private function assertPinBelongsToLocation(WorkLocation $location, WorkLocationPin $pin): void
    {
        abort_unless(
            (int) $pin->work_location_id === (int) $location->id,
            404,
        );
    }
}
