<?php

namespace App\Http\Requests\Penalty;

use App\Enums\PenaltyViolationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateManualPenaltyRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'penalty_rule_id' => ['required', 'integer', 'exists:penalty_rules,id'],
            'attendance_id' => ['nullable', 'integer', 'exists:attendance_records,id'],
            'occurred_at' => ['required', 'date'],
            'violation_type' => ['required', 'string', Rule::in([
                PenaltyViolationType::Late->value,
                PenaltyViolationType::EarlyCheckout->value,
                PenaltyViolationType::Absence->value,
                PenaltyViolationType::IncompleteAttendance->value,
                PenaltyViolationType::Other->value,
            ])],
            'violation_custom' => ['required_if:violation_type,'.PenaltyViolationType::Other->value, 'nullable', 'string', 'max:1000'],
            'points' => ['required', 'numeric', 'min:0', 'max:9999'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
