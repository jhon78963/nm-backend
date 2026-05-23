<?php

namespace App\Shared\Foundation\Services;

use Exception;

class SunatService
{
    public function dniConsultation($dni): mixed
    {
        $token = config('services.sunat.token');
        $url = 'https://api.apis.net.pe/v2/reniec/dni?'.http_build_query([
            'numero' => $dni,
        ]);

        return $this->performGetRequest($url, 'https://apis.net.pe/consulta-dni-api', $token);
    }

    public function rucConsultation($ruc): mixed
    {
        $token = config('services.sunat.token');
        $url = 'https://api.apis.net.pe/v2/sunat/ruc?'.http_build_query([
            'numero' => $ruc,
        ]);

        return $this->performGetRequest($url, 'http://apis.net.pe/api-ruc', $token);
    }

    /**
     * @throws Exception SUNAT_TIMEOUT | DOC_NOT_FOUND | SUNAT_UNAVAILABLE
     */
    private function performGetRequest(string $url, string $referer, ?string $token): mixed
    {
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => [
                "Referer: {$referer}",
                'Authorization: Bearer '.($token ?? ''),
            ],
        ]);

        $response = curl_exec($curl);
        $curlErrno = curl_errno($curl);
        $httpStatusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

        curl_close($curl);

        if ($curlErrno !== 0) {
            throw new Exception('SUNAT_TIMEOUT');
        }

        if ($httpStatusCode === 404) {
            throw new Exception('DOC_NOT_FOUND');
        }

        if ($httpStatusCode >= 500) {
            throw new Exception('SUNAT_UNAVAILABLE');
        }

        if ($response === false || $response === '') {
            throw new Exception('SUNAT_UNAVAILABLE');
        }

        $decoded = json_decode($response);

        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('SUNAT_UNAVAILABLE');
        }

        return $decoded;
    }
}
