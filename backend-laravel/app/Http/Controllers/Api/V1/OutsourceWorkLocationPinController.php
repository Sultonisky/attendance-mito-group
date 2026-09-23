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

    private function assertPinBelongsToLocation(WorkLocation $location, WorkLocationPin $pin): void
    {
        abort_unless(
            (int) $pin->work_location_id === (int) $location->id,
            404,
        );
    }
}
