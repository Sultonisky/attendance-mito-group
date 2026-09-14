<?php

namespace App\Http\Resources\Overtime;

use App\Models\OvertimeRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Standardized JSON response for overtime requests.
 */
class OvertimeRequestResource extends JsonResource
{
    /**
     * @param  array<string, mixed>|OvertimeRequest  $payload
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = $this->resource instanceof OvertimeRequest
            ? $this->resource->toArray()
            : $this->resource;

        return [
            'success' => true,
            'data' => [
                'id' => $data['id'] ?? null,
                'employee_id' => $data['employee_id'] ?? null,
                'attendance_id' => $data['attendance_id'] ?? null,
                'overtime_record_id' => $data['overtime_record_id'] ?? null,
                'date' => $data['date'] ?? null,
                'requested_minutes' => $data['requested_minutes'] ?? 0,
                'approved_minutes' => $data['approved_minutes'] ?? null,
                'status' => $data['status'] ?? null,
                'reason' => $data['reason'] ?? null,
                'approved_by' => $data['approved_by'] ?? null,
                'approved_at' => $data['approved_at'] ?? null,
                'created_at' => $data['created_at'] ?? null,
                'updated_at' => $data['updated_at'] ?? null,
            ],
        ];
    }
}
