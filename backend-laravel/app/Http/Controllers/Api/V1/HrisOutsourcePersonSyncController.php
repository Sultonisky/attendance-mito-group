<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Outsource\ImportOutsourcePersonsFromHris;
use App\Http\Controllers\Controller;
use App\Http\Requests\Integration\SyncHrisOutsourcePersonsRequest;
use Illuminate\Http\JsonResponse;

class HrisOutsourcePersonSyncController extends Controller
{
    public function store(
        SyncHrisOutsourcePersonsRequest $request,
        ImportOutsourcePersonsFromHris $action,
    ): JsonResponse {
        $result = $action->execute(
            $request->validated('people'),
            (bool) $request->validated('dry_run', false),
            $request,
        );

        return response()->json([
            'success' => true,
            ...$result,
        ]);
    }
}
