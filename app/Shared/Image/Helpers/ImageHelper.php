<?php

if (!function_exists('s3_url')) {
    /**
     * Genera la URL completa de un archivo almacenado en S3.
     *
     * @param string $path Ruta relativa del archivo (por ejemplo, 'uploads/foto.jpg')
     * @return string URL completa del archivo
     */
    function s3_url(string $path): string
    {
        $baseUrl = rtrim((string) config('filesystems.disks.s3.public_url', ''), '/');
        $cleanPath = ltrim($path, '/');

        return "$baseUrl/$cleanPath";
    }
}
