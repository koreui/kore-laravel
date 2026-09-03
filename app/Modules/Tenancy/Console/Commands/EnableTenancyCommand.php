<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

#[Description('Activa el módulo de multi-tenancy (stancl/tenancy) y publica todos los assets necesarios.')]
#[Signature('kore:tenancy:enable
                            {--force : Reescribe configs y migraciones aunque ya existan}')]
final class EnableTenancyCommand extends Command
{
    public function handle(): int
    {
        $this->info('Activando módulo Tenancy...');

        $this->call('vendor:publish', [
            '--tag' => 'tenancy-config',
            '--force' => (bool) $this->option('force'),
        ]);

        $this->call('vendor:publish', [
            '--tag' => 'tenancy-migrations',
            '--force' => (bool) $this->option('force'),
        ]);

        $this->writeEnv();

        $this->newLine();
        $this->info('✔ Tenancy activado.');
        $this->newLine();
        $this->line('Próximos pasos:');
        $this->line('  1. Revisa <comment>config/tenancy.php</comment> para personalizar el comportamiento');
        $this->line('  2. Ejecuta <comment>php artisan migrate</comment> para crear la tabla tenants');
        $this->line('  3. Crea tu primer tenant: <comment>php artisan tenants:create</comment>');
        $this->line('  4. Documentación: https://tenancyforlaravel.com/docs/v3/');

        return self::SUCCESS;
    }

    private function writeEnv(): void
    {
        $envPath = base_path('.env');

        if (! file_exists($envPath)) {
            $this->warn('No se encontró .env — agrega TENANCY_ENABLED=true manualmente.');

            return;
        }

        $content = (string) file_get_contents($envPath);

        if (str_contains($content, 'TENANCY_ENABLED=')) {
            $content = (string) preg_replace('/TENANCY_ENABLED=.*/', 'TENANCY_ENABLED=true', $content);
        } else {
            $content .= "\nTENANCY_ENABLED=true\n";
        }

        file_put_contents($envPath, $content);

        Process::fromShellCommandline('php artisan config:clear', base_path())->run();

        $this->line('  → TENANCY_ENABLED=true escrito en .env');
    }
}
