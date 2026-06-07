<?php

namespace App\Inventory\Purchase\Requests;

use App\Shared\Foundation\Rules\ValidMagicBytes;
use Illuminate\Foundation\Http\FormRequest;

class PurchaseVoucherUpdateRequest extends FormRequest
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
            'images' => 'required|array|min:1|max:10',
            'images.*' => ['file', 'mimes:jpeg,png,jpg,webp,pdf', 'max:5120', new ValidMagicBytes(['jpeg', 'png', 'webp', 'pdf'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'images.required' => 'Selecciona al menos un comprobante.',
            'images.max' => 'Máximo 10 comprobantes por envío.',
        ];
    }
}
