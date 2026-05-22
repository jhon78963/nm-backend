<?php

namespace Database\Seeders\Support;

use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

final class SeederDefaultPassword
{
    public const WEAK_PLAINTEXT = 'password123';

    public static function hashed(?Seeder $seeder = null): string
    {
        return Hash::make(self::resolvePlaintext($seeder));
    }

    public static function resolvePlaintext(?Seeder $seeder = null): string
    {
        $configured = env('SEEDER_DEFAULT_PASSWORD');
        $isLocal = app()->environment('local');
        $isProduction = app()->environment('production');

        if ($configured === self::WEAK_PLAINTEXT) {
            if ($isProduction) {
                self::warn(
                    $seeder,
                    'SEEDER_DEFAULT_PASSWORD=password123 ignorada en producción. Se generó una contraseña aleatoria para los usuarios semilla.',
                );

                return Str::password(32);
            }

            if (! $isLocal) {
                throw new RuntimeException(
                    'No se debe usar la contraseña por defecto (password123) en entornos no locales. '
                    .'Define SEEDER_DEFAULT_PASSWORD en .env con un valor seguro antes de ejecutar los seeders.'
                );
            }

            self::warn(
                $seeder,
                'ADVERTENCIA: SEEDER_DEFAULT_PASSWORD=password123. Solo válido para desarrollo local.',
            );

            return $configured;
        }

        if ($configured === null || $configured === '') {
            if ($isProduction || ! $isLocal) {
                self::warn(
                    $seeder,
                    'SEEDER_DEFAULT_PASSWORD no definida. Se generó una contraseña aleatoria para los usuarios semilla.',
                );

                return Str::password(32);
            }

            self::warn(
                $seeder,
                'SEEDER_DEFAULT_PASSWORD no definida; usando password123 (solo entorno local).',
            );

            return self::WEAK_PLAINTEXT;
        }

        return $configured;
    }

    private static function warn(?Seeder $seeder, string $message): void
    {
        if ($seeder?->command instanceof Command) {
            $seeder->command->warn($message);

            return;
        }

        logger()->warning($message);
    }
}
