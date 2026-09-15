<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MonthlyRecapResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'period' => $this->period,
            'status' => $this->status,
            'summary' => $this->summary,
            'details' => MonthlyRecapDetailResource::collection($this->whenLoaded('details', $this->details)),
            'finalized_at' => $this->finalized_at?->toISOString(),
            'exported_at' => $this->exported_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
