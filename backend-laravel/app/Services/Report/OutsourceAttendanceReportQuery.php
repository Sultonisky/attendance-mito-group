<?php

namespace App\Services\Report;

use App\Models\AttendanceRecord;
use App\Models\City;
use App\Models\Outsource;
use App\Models\User;
use App\Models\WorkLocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class OutsourceAttendanceReportQuery
{
    private const ALLOWED_SORTS = [
        'attendance_date' => 'attendance_records.attendance_date',
        'outsource_name' => 'outsources.name',
        'status' => 'attendance_records.status',
        'check_in_at' => 'first_check_in',
        'check_out_at' => 'last_check_out',
        'duration_minutes' => 'total_duration',
        'created_at' => 'attendance_records.created_at',
    ];

    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        $query = AttendanceRecord::query()
            ->select([
                'attendance_records.id',
                'attendance_records.attendance_date',
                'attendance_records.status',
                'attendance_records.created_at',
                'outsources.id as outsource_id',
                'outsources.name as outsource_name',
                'outsources.outsource_code',
                DB::raw('(SELECT COALESCE(
                    (SELECT wl.id FROM attendance_events ae JOIN work_location_pins wlp ON wlp.id = ae.work_location_pin_id JOIN work_locations wl ON wl.id = wlp.work_location_id WHERE ae.attendance_id = attendance_records.id AND ae.event_type = \'check_in\' AND ae.work_location_pin_id IS NOT NULL ORDER BY ae.occurred_at ASC LIMIT 1),
                    (SELECT wl.id FROM outsource_store_assignments osa JOIN work_locations wl ON wl.id = osa.store_id WHERE osa.outsource_id = attendance_records.outsource_id AND osa.status = \'active\' AND osa.deleted_at IS NULL AND wl.status = \'active\' AND wl.deleted_at IS NULL ORDER BY osa.id ASC LIMIT 1)
                )) as store_id'),
                DB::raw('(SELECT COALESCE(
                    (SELECT wl.name FROM attendance_events ae JOIN work_location_pins wlp ON wlp.id = ae.work_location_pin_id JOIN work_locations wl ON wl.id = wlp.work_location_id WHERE ae.attendance_id = attendance_records.id AND ae.event_type = \'check_in\' AND ae.work_location_pin_id IS NOT NULL ORDER BY ae.occurred_at ASC LIMIT 1),
                    (SELECT wl.name FROM outsource_store_assignments osa JOIN work_locations wl ON wl.id = osa.store_id WHERE osa.outsource_id = attendance_records.outsource_id AND osa.status = \'active\' AND osa.deleted_at IS NULL AND wl.status = \'active\' AND wl.deleted_at IS NULL ORDER BY osa.id ASC LIMIT 1)
                )) as store_name'),
                DB::raw('(SELECT COALESCE(
                    (SELECT c.name FROM attendance_events ae JOIN work_location_pins wlp ON wlp.id = ae.work_location_pin_id JOIN work_locations wl ON wl.id = wlp.work_location_id JOIN cities c ON c.id = wl.city_id WHERE ae.attendance_id = attendance_records.id AND ae.event_type = \'check_in\' AND ae.work_location_pin_id IS NOT NULL ORDER BY ae.occurred_at ASC LIMIT 1),
                    (SELECT c.name FROM outsource_store_assignments osa JOIN work_locations wl ON wl.id = osa.store_id JOIN cities c ON c.id = wl.city_id WHERE osa.outsource_id = attendance_records.outsource_id AND osa.status = \'active\' AND osa.deleted_at IS NULL AND wl.status = \'active\' AND wl.deleted_at IS NULL AND c.status = \'active\' ORDER BY osa.id ASC LIMIT 1)
                )) as city_name'),
                DB::raw('(SELECT wlp.id FROM attendance_events ae JOIN work_location_pins wlp ON wlp.id = ae.work_location_pin_id WHERE ae.attendance_id = attendance_records.id AND ae.event_type = \'check_in\' AND ae.work_location_pin_id IS NOT NULL ORDER BY ae.occurred_at ASC LIMIT 1) as pin_id'),
                DB::raw('(SELECT wlp.name FROM attendance_events ae JOIN work_location_pins wlp ON wlp.id = ae.work_location_pin_id WHERE ae.attendance_id = attendance_records.id AND ae.event_type = \'check_in\' AND ae.work_location_pin_id IS NOT NULL ORDER BY ae.occurred_at ASC LIMIT 1) as pin_name'),
                DB::raw('(SELECT wlp.address FROM attendance_events ae JOIN work_location_pins wlp ON wlp.id = ae.work_location_pin_id WHERE ae.attendance_id = attendance_records.id AND ae.event_type = \'check_in\' AND ae.work_location_pin_id IS NOT NULL ORDER BY ae.occurred_at ASC LIMIT 1) as pin_address'),
                DB::raw('(SELECT wlp.latitude FROM attendance_events ae JOIN work_location_pins wlp ON wlp.id = ae.work_location_pin_id WHERE ae.attendance_id = attendance_records.id AND ae.event_type = \'check_in\' AND ae.work_location_pin_id IS NOT NULL ORDER BY ae.occurred_at ASC LIMIT 1) as pin_latitude'),
                DB::raw('(SELECT wlp.longitude FROM attendance_events ae JOIN work_location_pins wlp ON wlp.id = ae.work_location_pin_id WHERE ae.attendance_id = attendance_records.id AND ae.event_type = \'check_in\' AND ae.work_location_pin_id IS NOT NULL ORDER BY ae.occurred_at ASC LIMIT 1) as pin_longitude'),
                DB::raw('(SELECT wlp.id FROM attendance_events ae JOIN work_location_pins wlp ON wlp.id = ae.work_location_pin_id WHERE ae.attendance_id = attendance_records.id AND ae.event_type = \'check_out\' AND ae.work_location_pin_id IS NOT NULL ORDER BY ae.occurred_at DESC LIMIT 1) as check_out_pin_id'),
                DB::raw('(SELECT wlp.name FROM attendance_events ae JOIN work_location_pins wlp ON wlp.id = ae.work_location_pin_id WHERE ae.attendance_id = attendance_records.id AND ae.event_type = \'check_out\' AND ae.work_location_pin_id IS NOT NULL ORDER BY ae.occurred_at DESC LIMIT 1) as check_out_pin_name'),
                DB::raw('(SELECT wlp.address FROM attendance_events ae JOIN work_location_pins wlp ON wlp.id = ae.work_location_pin_id WHERE ae.attendance_id = attendance_records.id AND ae.event_type = \'check_out\' AND ae.work_location_pin_id IS NOT NULL ORDER BY ae.occurred_at DESC LIMIT 1) as check_out_pin_address'),
                DB::raw('(SELECT wlp.latitude FROM attendance_events ae JOIN work_location_pins wlp ON wlp.id = ae.work_location_pin_id WHERE ae.attendance_id = attendance_records.id AND ae.event_type = \'check_out\' AND ae.work_location_pin_id IS NOT NULL ORDER BY ae.occurred_at DESC LIMIT 1) as check_out_pin_latitude'),
                DB::raw('(SELECT wlp.longitude FROM attendance_events ae JOIN work_location_pins wlp ON wlp.id = ae.work_location_pin_id WHERE ae.attendance_id = attendance_records.id AND ae.event_type = \'check_out\' AND ae.work_location_pin_id IS NOT NULL ORDER BY ae.occurred_at DESC LIMIT 1) as check_out_pin_longitude'),
                DB::raw('(SELECT ae.latitude FROM attendance_events ae WHERE ae.attendance_id = attendance_records.id AND ae.event_type = \'check_in\' ORDER BY ae.occurred_at ASC LIMIT 1) as check_in_gps_latitude'),
                DB::raw('(SELECT ae.longitude FROM attendance_events ae WHERE ae.attendance_id = attendance_records.id AND ae.event_type = \'check_in\' ORDER BY ae.occurred_at ASC LIMIT 1) as check_in_gps_longitude'),
                DB::raw('(SELECT ae.accuracy_meters FROM attendance_events ae WHERE ae.attendance_id = attendance_records.id AND ae.event_type = \'check_in\' ORDER BY ae.occurred_at ASC LIMIT 1) as check_in_gps_accuracy_meters'),
                DB::raw('(SELECT ae.latitude FROM attendance_events ae WHERE ae.attendance_id = attendance_records.id AND ae.event_type = \'check_out\' ORDER BY ae.occurred_at DESC LIMIT 1) as check_out_gps_latitude'),
                DB::raw('(SELECT ae.longitude FROM attendance_events ae WHERE ae.attendance_id = attendance_records.id AND ae.event_type = \'check_out\' ORDER BY ae.occurred_at DESC LIMIT 1) as check_out_gps_longitude'),
                DB::raw('(SELECT ae.accuracy_meters FROM attendance_events ae WHERE ae.attendance_id = attendance_records.id AND ae.event_type = \'check_out\' ORDER BY ae.occurred_at DESC LIMIT 1) as check_out_gps_accuracy_meters'),
                DB::raw('(SELECT MIN(check_in_at) FROM attendance_sessions WHERE attendance_sessions.attendance_record_id = attendance_records.id) as first_check_in'),
                DB::raw('(SELECT MAX(check_out_at) FROM attendance_sessions WHERE attendance_sessions.attendance_record_id = attendance_records.id) as last_check_out'),
                DB::raw('(SELECT SUM(duration_minutes) FROM attendance_sessions WHERE attendance_sessions.attendance_record_id = attendance_records.id) as total_duration'),
                DB::raw('(SELECT COUNT(*) FROM attendance_sessions WHERE attendance_sessions.attendance_record_id = attendance_records.id) as session_count'),
            ])
            ->join('outsources', 'outsources.id', '=', 'attendance_records.outsource_id')
            ->whereNotNull('attendance_records.outsource_id')
            ->where('attendance_records.attendable_type', '=', 'outsource')
            ->whereDate('attendance_records.attendance_date', '>=', $filters['from'])
            ->whereDate('attendance_records.attendance_date', '<=', $filters['to'])
            ->when($filters['city_id'] ?? null, function ($query, $cityId) {
                $query->where(function ($outer) use ($cityId) {
                    $outer->whereExists(function ($q) use ($cityId) {
                        $q->select(DB::raw(1))
                            ->from('attendance_events as ae')
                            ->join('work_location_pins as wlp', 'wlp.id', '=', 'ae.work_location_pin_id')
                            ->join('work_locations as wl', 'wl.id', '=', 'wlp.work_location_id')
                            ->whereColumn('ae.attendance_id', 'attendance_records.id')
                            ->whereIn('ae.event_type', ['check_in', 'check_out'])
                            ->where('wl.city_id', $cityId);
                    })->orWhereExists(function ($q) use ($cityId) {
                        $q->select(DB::raw(1))
                            ->from('outsource_store_assignments')
                            ->join('work_locations', 'work_locations.id', '=', 'outsource_store_assignments.store_id')
                            ->whereColumn('outsource_store_assignments.outsource_id', 'attendance_records.outsource_id')
                            ->where('outsource_store_assignments.status', 'active')
                            ->whereNull('outsource_store_assignments.deleted_at')
                            ->where('work_locations.city_id', $cityId)
                            ->where('work_locations.status', 'active')
                            ->whereNull('work_locations.deleted_at');
                    });
                });
            })
            ->when($filters['store_id'] ?? null, function ($query, $storeId) {
                $query->where(function ($outer) use ($storeId) {
                    $outer->whereExists(function ($q) use ($storeId) {
                        $q->select(DB::raw(1))
                            ->from('attendance_events as ae')
                            ->join('work_location_pins as wlp', 'wlp.id', '=', 'ae.work_location_pin_id')
                            ->whereColumn('ae.attendance_id', 'attendance_records.id')
                            ->whereIn('ae.event_type', ['check_in', 'check_out'])
                            ->where('wlp.work_location_id', $storeId);
                    })->orWhereExists(function ($q) use ($storeId) {
                        $q->select(DB::raw(1))
                            ->from('outsource_store_assignments')
                            ->whereColumn('outsource_store_assignments.outsource_id', 'attendance_records.outsource_id')
                            ->where('outsource_store_assignments.store_id', $storeId)
                            ->where('outsource_store_assignments.status', 'active')
                            ->whereNull('outsource_store_assignments.deleted_at');
                    });
                });
            })
            ->when($filters['outsource_id'] ?? null, function ($query, $outsourceId) {
                $query->where('attendance_records.outsource_id', (int) $outsourceId);
            })
            ->when($filters['status'] ?? null, function ($query, $status) {
                $query->where('attendance_records.status', $status);
            })
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('outsources.name', 'like', "%{$search}%")
                        ->orWhere('outsources.outsource_code', 'like', "%{$search}%");
                });
            });

        $this->applySort($query, $filters['sort'] ?? 'attendance_date', $filters['direction'] ?? 'desc');

        return $query->paginate($filters['per_page'] ?? 25);
    }

    private function applySort(Builder $query, string $sort, string $direction): void
    {
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        $column = self::ALLOWED_SORTS[$sort] ?? self::ALLOWED_SORTS['attendance_date'];

        $query->orderBy($column, $direction);
    }
}
