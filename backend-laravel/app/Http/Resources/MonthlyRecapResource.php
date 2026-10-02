<?php

namespace App\Http\Resources;

use App\Enums\MonthlyRecapStatus;
use App\Support\OutsourceAttendancePeriod;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MonthlyRecapResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $summary = is_array($this->summary) ? $this->summary : [];

        return [
            'id' => $this->id,
            'source' => $this->source ?? 'employee',
            'employee_id' => $this->employee_id,
            'outsource_id' => $this->outsource_id,
            'employee_code' => $this->whenLoaded('employee', fn () => $this->employee?->employee_code),
            'outsource_code' => $this->whenLoaded('outsource', fn () => $this->outsource?->outsource_code),
            'subject_name' => $this->when(
                $this->relationLoaded('employee') || $this->relationLoaded('outsource'),
                fn () => ($this->source === 'outsource')
                    ? ($this->outsource?->name)
                    : ($this->employee?->full_name ?? $this->employee?->name),
            ),
            'period' => $this->period,
            ...$this->periodRange(),
            'status' => MonthlyRecapStatus::normalize($this->status),
            'summary' => [
                'scheduled_days' => (int) ($summary['scheduled_days'] ?? 0),
                'present_days' => (int) ($summary['present_days'] ?? 0),
                'late_days' => (int) ($summary['late_days'] ?? 0),
                'incomplete_days' => (int) ($summary['incomplete_days'] ?? 0),
                'absent_days' => (int) ($summary['absent_days'] ?? 0),
            ],
            'details' => MonthlyRecapDetailResource::collection($this->whenLoaded('details', $this->details)),
            'finalized_at' => $this->finalized_at?->toISOString(),
            'exported_at' => $this->exported_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * Actual date range behind the period key: calendar month for employees,
     * cutoff period (e.g. 2026-08-25 .. 2026-09-24) for outsources.
     *
     * @return array{period_start: string|null, period_end: string|null}
     */
    private function periodRange(): array
    {
        try {
            if (($this->source ?? 'employee') === 'outsource') {
                $period = OutsourceAttendancePeriod::fromKey((string) $this->period);

                return [
                    'period_start' => $period->startDate->toDateString(),
                    'period_end' => $period->endDate->toDateString(),
                ];
            }

            $start = CarbonImmutable::createFromFormat('!Y-m', (string) $this->period);
        } catch (\Throwable) {
            return ['period_start' => null, 'period_end' => null];
        }

        return [
            'period_start' => $start->toDateString(),
            'period_end' => $start->endOfMonth()->toDateString(),
        ];
    }
}
