<?php

namespace App\Http\Requests\Penalty;

use Illuminate\Foundation\Http\FormRequest;

class VoidPenaltyRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
