<?php

namespace App\Http\Requests\Attendance;

use App\Enums\AttendanceCorrectionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAttendanceCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $type = $this->input('request_type');

        return [
            'request_type' => ['required', 'string', Rule::in(array_column(AttendanceCorrectionType::cases(), 'value'))],
            'attendance_date' => ['required', 'date', 'before_or_equal:today'],
            'requested_check_in_at' => [
                Rule::requiredIf(in_array($type, [
                    AttendanceCorrectionType::ClockIn->value,
                    AttendanceCorrectionType::Both->value,
                ], true)),
                'nullable',
                'date',
            ],
            'requested_check_out_at' => [
                Rule::requiredIf(in_array($type, [
                    AttendanceCorrectionType::ClockOut->value,
                    AttendanceCorrectionType::Both->value,
                ], true)),
                'nullable',
                'date',
                Rule::when(
                    $this->filled('requested_check_in_at'),
                    ['after:requested_check_in_at']
                ),
            ],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }
}
