<?php

namespace App\Http\Resources\Report;

use App\Support\AttendanceDateTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OutsourceAttendanceReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $checkInPin = $this->pinPayload(
            $this->pin_id,
            $this->pin_name,
            $this->pin_address,
            $this->pin_latitude,
            $this->pin_longitude,
        );

        $checkOutPin = $this->pinPayload(
            $this->check_out_pin_id,
            $this->check_out_pin_name,
            $this->check_out_pin_address,
            $this->check_out_pin_latitude,
            $this->check_out_pin_longitude,
        );

        $checkInGps = $this->gpsPayload(
            $this->check_in_gps_latitude,
            $this->check_in_gps_longitude,
            $this->check_in_gps_accuracy_meters,
        );

        $checkOutGps = $this->gpsPayload(
            $this->check_out_gps_latitude,
            $this->check_out_gps_longitude,
            $this->check_out_gps_accuracy_meters,
        );

        return [
            'attendance_id' => $this->id,
            'outsource' => [
                'id' => $this->outsource_id,
                'name' => $this->outsource_name,
                'code' => $this->outsource_code,
            ],
            'city' => $this->city_name ? [
                'id' => null,
                'name' => $this->city_name,
            ] : null,
            'store' => $this->store_id ? [
                'id' => (int) $this->store_id,
                'name' => $this->store_name,
            ] : null,
            'pin' => $checkInPin,
            'check_in_location' => ($checkInPin || $checkInGps) ? [
                'pin' => $checkInPin,
                'gps' => $checkInGps,
            ] : null,
            'check_out_location' => ($checkOutPin || $checkOutGps) ? [
                'pin' => $checkOutPin,
                'gps' => $checkOutGps,
            ] : null,
            'attendance_date' => $this->attendance_date?->toDateString(),
            'status' => $this->status,
            'check_in_at' => AttendanceDateTime::toApi($this->first_check_in),
            'check_out_at' => AttendanceDateTime::toApi($this->last_check_out),
            'duration_minutes' => $this->total_duration ? (int) $this->total_duration : null,
            'session_count' => isset($this->session_count) ? (int) $this->session_count : null,
        ];
    }

    private function pinPayload(
        mixed $id,
        mixed $name,
        mixed $address,
        mixed $latitude,
        mixed $longitude,
    ): ?array {
        if ($id === null && $name === null && $address === null && $latitude === null && $longitude === null) {
            return null;
        }

        return [
            'id' => $id !== null ? (int) $id : null,
            'name' => $name,
            'address' => $address,
            'latitude' => $latitude !== null ? (float) $latitude : null,
            'longitude' => $longitude !== null ? (float) $longitude : null,
        ];
    }

    private function gpsPayload(mixed $latitude, mixed $longitude, mixed $accuracyMeters): ?array
    {
        if ($latitude === null && $longitude === null && $accuracyMeters === null) {
            return null;
        }

        return [
            'latitude' => $latitude !== null ? (float) $latitude : null,
            'longitude' => $longitude !== null ? (float) $longitude : null,
            'accuracy_meters' => $accuracyMeters !== null ? (float) $accuracyMeters : null,
        ];
    }
}
