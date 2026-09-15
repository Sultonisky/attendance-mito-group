<?php

namespace App\Http\Resources;

use App\Models\PenaltyRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PenaltyResource extends JsonResource
{
    /**
     * @param  PenaltyRecord  $resource
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'penalty_rule_id' => $this->penalty_rule_id,
            'attendance_id' => $this->attendance_id,
            'source' => $this->source,
            'violation_type' => $this->violation_type,
            'violation_custom' => $this->violation_custom,
            'original_points' => $this->original_points,
            'adjusted_points' => $this->adjusted_points,
            'final_points' => $this->final_points,
            'reason' => $this->reason,
            'status' => $this->status,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
