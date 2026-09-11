<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Attendance\CheckInEmployee;
use App\Actions\Attendance\CheckOutEmployee;
use App\Domain\Attendance\DTOs\AttendanceOperationData;
use App\Domain\Attendance\Exceptions\AttendanceAlreadyCheckedInException;
use App\Domain\Attendance\Exceptions\DuplicateAttendanceRequestException;
use App\Domain\Attendance\Exceptions\NoOpenAttendanceSessionException;
use App\Enums\AttendanceEventType;
use App\Exceptions\Domain\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CheckInRequest;
use App\Http\Requests\CheckOutRequest;
use App\Http\Resources\AttendanceResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class AttendanceController extends Controller
{
    public function __construct(
        private CheckInEmployee $checkInEmployee,
        private CheckOutEmployee $checkOutEmployee,
    ) {}

    public function checkIn(CheckInRequest $request): JsonResponse
    {
        try {
            $data = $this->buildOperationData($request, AttendanceEventType::CheckIn);

            $result = $this->checkInEmployee->execute($request->user(), $data);

            return response()->json([
                'success' => true,
                'message' => $result->message,
                'data' => new AttendanceResource($result->attendanceRecord),
            ]);
        } catch (AttendanceAlreadyCheckedInException|DuplicateAttendanceRequestException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 409);
        } catch (DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function checkOut(CheckOutRequest $request): JsonResponse
    {
        try {
            $data = $this->buildOperationData($request, AttendanceEventType::CheckOut);

            $result = $this->checkOutEmployee->execute($request->user(), $data);

            return response()->json([
                'success' => true,
                'message' => $result->message,
                'data' => new AttendanceResource($result->attendanceRecord),
            ]);
        } catch (NoOpenAttendanceSessionException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } catch (DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    private function buildOperationData(CheckInRequest|CheckOutRequest $request, AttendanceEventType $eventType): AttendanceOperationData
    {
        return new AttendanceOperationData(
            employeeId: $request->user()->employee->id,
            latitude: (float) $request->input('latitude'),
            longitude: (float) $request->input('longitude'),
            accuracy: $request->has('accuracy') ? (float) $request->input('accuracy') : null,
            deviceIdentifier: $request->input('device_identifier'),
            source: $request->input('source'),
            workLocationId: $request->has('work_location_id') ? (int) $request->input('work_location_id') : null,
            occurredAt: CarbonImmutable::now(),
            eventType: $eventType,
        );
    }
}
