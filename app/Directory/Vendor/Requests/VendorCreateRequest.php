<?php

namespace App\Directory\Vendor\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VendorCreateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'address' => 'required|string|max:100',
            'local' => 'nullable|string|max:100',
            'balancy' => 'nullable|numeric',
            'phone' => 'nullable|string',
        ];
    }
}
