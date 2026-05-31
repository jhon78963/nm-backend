<?php

namespace App\Administration\Tenant\Controllers;

use App\Administration\Tenant\Models\Tenant;
use App\Administration\Tenant\Models\TenantSetting;
use App\Administration\Tenant\Requests\TenantSettingUpsertRequest;
use App\Administration\Tenant\Resources\TenantSettingResource;
use App\Shared\Foundation\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class TenantSettingController extends Controller
{
    /**
     * Devuelve la configuración del tenant.
     * Si aún no existe, retorna un objeto vacío (nunca falla).
     */
    public function get(Tenant $tenant): JsonResponse
    {
        $setting = TenantSetting::firstOrNew(['tenant_id' => $tenant->id]);

        return response()->json(new TenantSettingResource($setting));
    }

    /**
     * Crea o actualiza la configuración (upsert).
     * Operación idempotente: seguro llamar siempre con PUT.
     */
    public function upsert(TenantSettingUpsertRequest $request, Tenant $tenant): JsonResponse
    {
        return DB::transaction(function () use ($request, $tenant): JsonResponse {
            $data = $request->validated();

            $payload = [
                'ruc'               => $data['ruc']              ?? null,
                'legal_name'        => $data['legalName']        ?? null,
                'trade_name'        => $data['tradeName']        ?? null,
                'address'           => $data['address']          ?? null,
                'district'          => $data['district']         ?? null,
                'province'          => $data['province']         ?? null,
                'department'        => $data['department']       ?? null,
                'phone'             => $data['phone']            ?? null,
                'email'             => $data['email']            ?? null,
                'website'           => $data['website']          ?? null,
                'social_links'      => $data['socialLinks']      ?? null,
                'logo_url'          => $data['logoUrl']          ?? null,
                'ticket_footer_note'=> $data['ticketFooterNote'] ?? null,
            ];

            $setting = TenantSetting::updateOrCreate(
                ['tenant_id' => $tenant->id],
                $payload,
            );

            return response()->json(new TenantSettingResource($setting));
        });
    }
}
