<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ConfigCheckSecurityCommand extends Command
{
    protected $signature = 'config:check-security';

    protected $description = 'Valida variables críticas de seguridad antes de desplegar a producción';

    /**
     * @return list<string>
     */
    private function collectFailures(): array
    {
        $failures = [];

        if (config('app.debug')) {
            $failures[] = 'APP_DEBUG debe ser false en producción.';
        }

        if (empty(config('app.key')) || str_starts_with((string) config('app.key'), 'base64:generate')) {
            $failures[] = 'APP_KEY debe estar generado (php artisan key:generate).';
        }

        if (! config('session.secure')) {
            $failures[] = 'SESSION_SECURE_COOKIE debe ser true.';
        }

        if (! config('session.encrypt')) {
            $failures[] = 'SESSION_ENCRYPT debe ser true.';
        }

        $sameSite = config('session.same_site');
        if ($sameSite === 'none' && ! config('session.secure')) {
            $failures[] = 'SESSION_SAME_SITE=none requiere SESSION_SECURE_COOKIE=true.';
        }

        $statefulDomains = array_values(array_filter(
            config('sanctum.stateful', []),
            static fn (mixed $domain): bool => is_string($domain) && trim($domain) !== ''
        ));

        if ($statefulDomains === []) {
            $failures[] = 'SANCTUM_STATEFUL_DOMAINS debe incluir al menos un dominio del SPA.';
        }

        $corsOrigins = config('cors.allowed_origins', []);
        if ($corsOrigins === [] || in_array('*', $corsOrigins, true)) {
            $failures[] = 'CORS_ALLOWED_ORIGINS debe listar orígenes explícitos (no usar * con credentials).';
        }

        $appUrl = (string) config('app.url');
        if (! str_starts_with($appUrl, 'https://')) {
            $failures[] = 'APP_URL debe usar HTTPS en producción.';
        }

        return $failures;
    }

    public function handle(): int
    {
        if (! app()->environment('production')) {
            $this->warn('APP_ENV='.app()->environment().' — las comprobaciones siguen la política de producción.');
        }

        $failures = $this->collectFailures();

        if ($failures === []) {
            $this->info('Configuración de seguridad OK para producción.');

            return self::SUCCESS;
        }

        $this->error('Se encontraron problemas de configuración:');
        foreach ($failures as $failure) {
            $this->line('  • '.$failure);
        }
        $this->newLine();
        $this->line('Consulta docs/DEPLOY-SECURITY.md para el checklist completo.');

        return self::FAILURE;
    }
}
