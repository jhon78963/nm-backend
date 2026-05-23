<?php

namespace App\Finance\Sale\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SearchCustomerDocRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'dni' => ['required', 'string'],
            'documentType' => ['sometimes', 'string', 'in:DNI,RUC,dni,ruc'],
            'document_type' => ['sometimes', 'string', 'in:DNI,RUC,dni,ruc'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $doc = (string) $this->input('dni');
            $documentType = strtoupper((string) (
                $this->input('documentType')
                ?? $this->input('document_type')
                ?? ''
            ));

            if ($documentType === 'DNI') {
                if (! preg_match('/^\d{8}$/', $doc)) {
                    $validator->errors()->add(
                        'dni',
                        'El DNI debe tener exactamente 8 dígitos numéricos.',
                    );
                }

                return;
            }

            if ($documentType === 'RUC') {
                if (! preg_match('/^\d{11}$/', $doc)) {
                    $validator->errors()->add(
                        'dni',
                        'El RUC debe tener exactamente 11 dígitos numéricos.',
                    );
                }

                return;
            }

            $length = strlen($doc);

            if ($length === 8) {
                if (! preg_match('/^\d{8}$/', $doc)) {
                    $validator->errors()->add(
                        'dni',
                        'El DNI debe tener exactamente 8 dígitos numéricos.',
                    );
                }

                return;
            }

            if ($length === 11) {
                if (! preg_match('/^\d{11}$/', $doc)) {
                    $validator->errors()->add(
                        'dni',
                        'El RUC debe tener exactamente 11 dígitos numéricos.',
                    );
                }

                return;
            }

            $validator->errors()->add(
                'dni',
                'El documento debe ser un DNI de 8 dígitos o un RUC de 11 dígitos numéricos.',
            );
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return array_merge($this->all(), $this->query());
    }
}
