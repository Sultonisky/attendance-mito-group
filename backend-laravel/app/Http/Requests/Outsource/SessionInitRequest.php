<?php

namespace App\Http\Requests\Outsource;

use Illuminate\Foundation\Http\FormRequest;

class SessionInitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'store_id' => ['required', 'integer', 'exists:work_locations,id'],
            'outsource_id' => ['required', 'integer', 'exists:outsources,id'],
        ];
    }
}
