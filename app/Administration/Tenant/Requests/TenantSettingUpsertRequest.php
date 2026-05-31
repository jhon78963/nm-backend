<?php

namespace App\Administration\Tenant\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TenantSettingUpsertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ruc'              => ['sometimes', 'nullable', 'string', 'max:11'],
            'legalName'        => ['sometimes', 'nullable', 'string', 'max:191'],
            'tradeName'        => ['sometimes', 'nullable', 'string', 'max:191'],
            'address'          => ['sometimes', 'nullable', 'string', 'max:255'],
            'district'         => ['sometimes', 'nullable', 'string', 'max:100'],
            'province'         => ['sometimes', 'nullable', 'string', 'max:100'],
            'department'       => ['sometimes', 'nullable', 'string', 'max:100'],
            'phone'            => ['sometimes', 'nullable', 'string', 'max:30'],
            'email'            => ['sometimes', 'nullable', 'email', 'max:191'],
            'website'          => ['sometimes', 'nullable', 'url', 'max:255'],
            'socialLinks'                => ['sometimes', 'nullable', 'array'],
            'socialLinks.facebook'       => ['sometimes', 'nullable', 'string', 'max:255'],
            'socialLinks.instagram'      => ['sometimes', 'nullable', 'string', 'max:255'],
            'socialLinks.tiktok'         => ['sometimes', 'nullable', 'string', 'max:255'],
            'logoUrl'          => ['sometimes', 'nullable', 'string', 'max:512'],
            'ticketFooterNote' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
