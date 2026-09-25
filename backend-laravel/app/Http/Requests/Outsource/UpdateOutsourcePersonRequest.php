<?php

namespace App\Http\Requests\Outsource;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOutsourcePersonRequest extends FormRequest
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
        return [
            'name'         => ['sometimes', 'required', 'string', 'max:255'],
            'password'     => ['nullable', 'string', 'digits_between:4,8'],
            'status'       => ['sometimes', 'required', 'string', 'in:active,inactive'],
            'store_id'     => ['nullable', 'integer', 'exists:work_locations,id'],
            'store_ids'    => ['nullable', 'array'],
            'store_ids.*'  => ['integer', 'exists:work_locations,id', 'distinct'],
            'pin_ids'      => ['nullable', 'array'],
            'pin_ids.*'    => ['integer', 'exists:work_location_pins,id', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required'           => 'Name is required.',
            'name.max'                => 'Name may not exceed 255 characters.',
            'password.digits_between' => 'Password must be a numeric PIN (4–8 digits).',
            'status.in'               => 'Status must be active or inactive.',
            'store_id.exists'         => 'The selected store does not exist.',
            'store_ids.*.exists'      => 'One or more selected stores do not exist.',
            'store_ids.*.distinct'    => 'Each cabang can only be assigned once.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $storeIds = $this->input('store_ids');
            if (! is_array($storeIds)) {
                return;
            }

            $normalized = array_map('intval', $storeIds);
            if (count($normalized) !== count(array_unique($normalized))) {
                $validator->errors()->add('store_ids', 'Duplicate cabang assignments are not allowed.');
            }
        });
    }
}
