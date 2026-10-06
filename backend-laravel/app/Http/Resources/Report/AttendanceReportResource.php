<?php

namespace App\Http\Resources\Report;

use App\Support\AttendanceDateTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $checkInWorkLocation = $this->workLocationPayload('check_in');
        $checkOutWorkLocation = $this->workLocationPayload('check_out');
        $checkInGps = $this->gpsPayload('check_in');
        $checkOutGps = $this->gpsPayload('check_out');

        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee_code' => $this->employee_code,
            'employee_name' => $this->employee_name,
            'attendance_date' => $this->attendance_date?->toDateString(),
            'status' => $this->status,
            'check_in_at' => AttendanceDateTime::toApi($this->first_check_in ?? null),
            'check_in_location' => ($checkInWorkLocation || $checkInGps) ? [
                'work_location' => $checkInWorkLocation,
                'gps' => $checkInGps,
            ] : null,
            'check_out_at' => AttendanceDateTime::toApi($this->last_check_out ?? null),
            'check_out_location' => ($checkOutWorkLocation || $checkOutGps) ? [
                'work_location' => $checkOutWorkLocation,
                'gps' => $checkOutGps,
            ] : null,
            'duration_minutes' => $this->total_duration !== null ? (int) $this->total_duration : null,
            'created_at' => AttendanceDateTime::toApi($this->created_at),
        ];
    }

    private function workLocationPayload(string $prefix): ?array
    {
        $id = $this->{"{$prefix}_work_location_id"};
        $name = $this->{"{$prefix}_work_location_name"};
        $city = $this->{"{$prefix}_work_location_city"};

        if ($id === null && $name === null && $city === null) {
            return null;
        }

        return [
            'id' => $id !== null ? (int) $id : null,
            'name' => $name,
            'city' => $city,
        ];
    }

    private function gpsPayload(string $prefix): ?array
    {
        $latitude = $this->{"{$prefix}_gps_latitude"};
        $longitude = $this->{"{$prefix}_gps_longitude"};
        $accuracy = $this->{"{$prefix}_gps_accuracy_meters"};

        if ($latitude === null && $longitude === null && $accuracy === null) {
            return null;
        }

        return [
            'latitude' => $latitude !== null ? (float) $latitude : null,
            'longitude' => $longitude !== null ? (float) $longitude : null,
            'accuracy_meters' => $accuracy !== null ? (float) $accuracy : null,
        ];
    }
}
