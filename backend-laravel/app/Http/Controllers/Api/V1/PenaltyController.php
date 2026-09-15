<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Penalty\AdjustPenalty;
use App\Actions\Penalty\CreateManualPenalty;
use App\Actions\Penalty\VoidPenalty;
use App\Domain\Penalty\DTOs\CreateManualPenaltyData;
use App\Enums\PenaltyViolationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Penalty\AdjustPenaltyRequest;
use App\Http\Requests\Penalty\CreateManualPenaltyRequest;
use App\Http\Requests\Penalty\VoidPenaltyRequest;
use App\Http\Resources\PenaltyResource;
use App\Models\PenaltyRecord;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PenaltyController extends Controller
{
    public function __construct(
        private CreateManualPenalty $createManualPenalty,
        private AdjustPenalty $adjustPenalty,
        private VoidPenalty $voidPenalty,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = PenaltyRecord::query()->with(['employee', 'penaltyRule']);

        if ($user->hasRole('USER')) {
            $query->where('employee_id', $user->employee->id);
        }

        $perPage = (int) $request->query('per_page', 50);
        $records = $query->orderByDesc('occurred_at')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => PenaltyResource::collection($records),
            'meta' => [
                'current_page' => $records->currentPage(),
                'per_page' => $records->perPage(),
                'total' => $records->total(),
                'last_page' => $records->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, PenaltyRecord $penalty): JsonResponse
    {
        $user = $request->user();

        if ($user->hasRole('USER') && $penalty->employee_id !== $user->employee->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => new PenaltyResource($penalty),
        ]);
    }

    public function store(CreateManualPenaltyRequest $request): JsonResponse
    {
        $user = $request->user();

        $data = new CreateManualPenaltyData(
            employeeId: (int) $request->input('employee_id'),
            penaltyRuleId: (int) $request->input('penalty_rule_id'),
            attendanceId: $request->has('attendance_id') ? (int) $request->input('attendance_id') : null,
            violationType: PenaltyViolationType::from($request->input('violation_type')),
            violationCustom: $request->input('violation_custom'),
            points: (float) $request->input('points'),
            reason: (string) $request->input('reason', ''),
            occurredAt: CarbonImmutable::parse($request->input('occurred_at')),
        );

        $record = $this->createManualPenalty->execute($user, $data);

        return response()->json([
            'success' => true,
            'message' => 'Manual penalty created successfully.',
            'data' => new PenaltyResource($record),
        ], 201);
    }

    public function adjust(AdjustPenaltyRequest $request, PenaltyRecord $penalty): JsonResponse
    {
        $user = $request->user();

        $updated = $this->adjustPenalty->execute(
            $user,
            $penalty,
            (float) $request->input('points'),
            (string) $request->input('reason'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Penalty adjusted successfully.',
            'data' => new PenaltyResource($updated),
        ]);
    }

    public function void(VoidPenaltyRequest $request, PenaltyRecord $penalty): JsonResponse
    {
        $user = $request->user();

        $updated = $this->voidPenalty->execute(
            $user,
            $penalty,
            (string) $request->input('reason'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Penalty voided successfully.',
            'data' => new PenaltyResource($updated),
        ]);
    }
}
