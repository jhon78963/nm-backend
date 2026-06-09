<?php

namespace App\Inventory\Product\Requests;

use App\Shared\Foundation\Rules\ValidMagicBytes;
use Illuminate\Foundation\Http\FormRequest;

class ProductMediaStoreRequest extends FormRequest
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
            'image' => [
                'required',
                'file',
                'mimes:jpeg,png,jpg,webp',
                'max:5120',
                new ValidMagicBytes(['jpeg', 'png', 'webp']),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'image.required' => 'Selecciona una imagen para el producto.',
            'image.max' => 'La imagen no puede superar 5 MB.',
        ];
    }
}
