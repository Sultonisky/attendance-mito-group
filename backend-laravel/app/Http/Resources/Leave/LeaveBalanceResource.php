<?php

namespace App\Http\Resources\Leave;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Single-employee balance summary. Batches expose FIFO order only.
 */
class LeaveBalanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = is_array($this->resource) ? $this->resource : [];

        return [
            'employee_id' => $data['employee_id'] ?? null,
            'leave_type_id' => $data['leave_type_id'] ?? null,
            'leave_type_code' => $data['leave_type_code'] ?? null,
            'eligible' => $data['eligible'] ?? false,
            'eligibility_date' => $data['eligibility_date'] ?? null,
            'available' => $data['available'] ?? 0,
            'batches' => $data['batches'] ?? [],
        ];
    }
}
