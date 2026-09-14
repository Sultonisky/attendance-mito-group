<?php

namespace App\Http\Resources\Leave;

use App\Models\LeaveType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LeaveType
 */
class LeaveTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'category' => $this->category,
            'deducts_annual_balance' => (bool) $this->deducts_annual_balance,
            'description' => $this->description,
            'status' => $this->status,
        ];
    }
}
