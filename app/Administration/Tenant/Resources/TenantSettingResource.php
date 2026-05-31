<?php

namespace App\Administration\Tenant\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantSettingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $social = $this->social_links ?? [];

        return [
            'ruc'               => $this->ruc,
            'legalName'         => $this->legal_name,
            'tradeName'         => $this->trade_name,
            'address'           => $this->address,
            'district'          => $this->district,
            'province'          => $this->province,
            'department'        => $this->department,
            'phone'             => $this->phone,
            'email'             => $this->email,
            'website'           => $this->website,
            'socialLinks'       => [
                'facebook'  => $social['facebook']  ?? null,
                'instagram' => $social['instagram'] ?? null,
                'tiktok'    => $social['tiktok']    ?? null,
            ],
            'logoUrl'           => $this->logo_url,
            'ticketFooterNote'  => $this->ticket_footer_note,
        ];
    }
}
