<?php

namespace App\Http\Resources\Outsource;

use App\Models\OutsourceAttendanceSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OutsourceAttendanceSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var OutsourceAttendanceSession $this */
        return [
            'session_token' => $this->when($this->resource->relationLoaded('raw_token'), $this->resource->raw_token),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'outsource' => $this->outsource ? [
                'id' => $this->outsource->id,
                'name' => $this->outsource->name,
                'outsource_code' => $this->outsource->outsource_code,
            ] : null,
            'store' => $this->workLocation ? [
                'id' => $this->workLocation->id,
                'name' => $this->workLocation->name,
            ] : null,
        ];
    }
}
