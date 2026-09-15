<?php

namespace App\Http\Requests\Penalty;

use Illuminate\Foundation\Http\FormRequest;

class AdjustPenaltyRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'points' => ['required', 'numeric', 'min:0', 'max:9999'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
