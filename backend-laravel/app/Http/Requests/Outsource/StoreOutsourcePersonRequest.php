<?php

namespace App\Http\Requests\Outsource;

use Illuminate\Foundation\Http\FormRequest;

class StoreOutsourcePersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $storeIds = $this->input('store_ids');
        $single = $this->input('store_id');

        // Legacy single store_id → store_ids so create always has an assignment list.
        if ((! is_array($storeIds) || $storeIds === []) && $single !== null && $single !== '') {
            $this->merge(['store_ids' => [(int) $single]]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name'         => ['required', 'string', 'max:255'],
            'password'     => ['nullable', 'string', 'digits_between:4,8'],
            'store_id'     => ['nullable', 'integer', 'exists:work_locations,id'],
            'store_ids'    => ['required', 'array', 'min:1'],
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
            'store_id.exists'         => 'The selected store does not exist.',
            'store_ids.required'      => 'Assign at least one kota/cabang.',
            'store_ids.min'           => 'Assign at least one kota/cabang.',
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
