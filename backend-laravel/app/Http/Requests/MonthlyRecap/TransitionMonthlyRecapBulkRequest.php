<?php

namespace App\Http\Requests\MonthlyRecap;

use App\Actions\MonthlyRecap\TransitionMonthlyRecapsBulk;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionMonthlyRecapBulkRequest extends FormRequest
{
    public function authorize(): bool
    {
        $action = $this->input('action');
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        return match ($action) {
            TransitionMonthlyRecapsBulk::ACTION_REVIEW => $user->can('monthly_recap.review'),
            TransitionMonthlyRecapsBulk::ACTION_FINALIZE => $user->can('monthly_recap.finalize'),
            TransitionMonthlyRecapsBulk::ACTION_EXPORT => $user->can('monthly_recap.export'),
            TransitionMonthlyRecapsBulk::ACTION_REOPEN => $user->can('monthly_recap.finalize'),
            default => false,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:1000'],
            'ids.*' => ['integer', 'min:1', 'distinct'],
            'action' => ['required', 'string', Rule::in(TransitionMonthlyRecapsBulk::ACTIONS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ids.required' => 'Select at least one monthly recap.',
            'ids.min' => 'Select at least one monthly recap.',
            'action.required' => 'A bulk status action is required.',
            'action.in' => 'Invalid bulk status action.',
        ];
    }
}
