<?php

namespace App\Http\Resources\Employee;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $resource
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'employee_code' => $this->resource->employee_code,
            'full_name' => $this->resource->full_name,
            'email' => $this->resource->email,
            'phone' => $this->resource->phone,
            'employment_status' => $this->resource->employment_status,
            'join_date' => $this->resource->join_date?->toDateString(),
            'end_date' => $this->resource->end_date?->toDateString(),
            'job_position' => $this->resource->job_position,
            'department' => $this->resource->department,
            'division' => $this->resource->division,
            'branch' => $this->resource->branch,
            'job_level' => $this->resource->job_level,
            'grade' => $this->resource->grade,
            'direct_superior_id' => $this->resource->direct_superior_id,
            'indirect_superior_id' => $this->resource->indirect_superior_id,
            'user_id' => $this->resource->user_id,
            'created_at' => $this->resource->created_at?->toDateString(),
            'updated_at' => $this->resource->updated_at?->toDateString(),
        ];
    }
}
