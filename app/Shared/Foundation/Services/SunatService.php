<?php

namespace App\Shared\Foundation\Services;

class SunatService
{
    public function dniConsultation($dni): mixed
    {
        $token = config('services.sunat.token');
        $url = 'https://api.apis.net.pe/v2/reniec/dni?'.http_build_query([
            'numero' => $dni,
        ]);

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 2,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => [
                'Referer: https://apis.net.pe/consulta-dni-api',
                "Authorization: Bearer {$token}",
            ],
        ]);

        $response = curl_exec($curl);

        curl_close($curl);

        return json_decode($response);
    }

    public function rucConsultation($ruc): mixed
    {
        $token = config('services.sunat.token');
        $url = 'https://api.apis.net.pe/v2/sunat/ruc?'.http_build_query([
            'numero' => $ruc,
        ]);

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => [
                'Referer: http://apis.net.pe/api-ruc',
                "Authorization: Bearer {$token}",
            ],
        ]);

        $response = curl_exec($curl);

        curl_close($curl);

        return json_decode($response);
    }
}
