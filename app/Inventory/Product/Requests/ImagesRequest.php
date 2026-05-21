<?php

namespace App\Inventory\Product\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImagesRequest extends FormRequest
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
            'image' => 'required|array|min:1',
            'image.*' => 'required|file|image|mimes:jpeg,png,jpg,webp|max:5120',
            'size' => 'required|array|min:1',
            'size.*' => 'required|string|max:50',
            'name' => 'required|array|min:1',
            'name.*' => 'required|string|max:255',
        ];
    }
}
