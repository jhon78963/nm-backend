<?php

namespace App\Directory\Customer\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerUpdateRequest extends FormRequest
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
            'dni' => [
                'required',
                'string',
                'digits:8',
                Rule::unique('customers', 'dni')->ignore($this->route('customer')),
            ],
            'name' => 'required|string|max:100',
            'surname' => 'required|string|max:100',
        ];
    }
}
