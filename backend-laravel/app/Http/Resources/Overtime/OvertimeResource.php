<?php

namespace App\Http\Resources\Overtime;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Standardized JSON response for overtime records.
 */
class OvertimeResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = $this->resource;

        return [
            'success' => true,
            'data' => [
                'id' => $data['id'] ?? null,
                'employee_id' => $data['employee_id'] ?? null,
                'attendance_id' => $data['attendance_id'] ?? null,
                'date' => $data['date'] ?? null,
                'potential_minutes' => $data['potential_minutes'] ?? 0,
                'requested_minutes' => $data['requested_minutes'] ?? 0,
                'approved_minutes' => $data['approved_minutes'] ?? null,
                'actual_minutes' => $data['actual_minutes'] ?? null,
                'status' => $data['status'] ?? null,
                'created_at' => $data['created_at'] ?? null,
                'updated_at' => $data['updated_at'] ?? null,
            ],
        ];
    }
}
