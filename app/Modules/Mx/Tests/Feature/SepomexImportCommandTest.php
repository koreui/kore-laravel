<?php

declare(strict_types=1);

use App\Modules\Mx\Models\PostalCode;
use App\Modules\Mx\Models\State;
use App\Modules\Mx\Providers\MxModuleServiceProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| mx:sepomex:import
|--------------------------------------------------------------------------
|
| El comando sólo está registrado con MX_ENABLED=true, así que cada caso enciende
| el módulo sobre la aplicación en marcha (ver `withMxImportOn`).
|
| El fixture es `Tests/fixtures/sepomex-sample.txt`: 20 filas INVENTADAS en el
| formato del archivo oficial —separado por `|` y codificado en ISO-8859-1—, con
| una línea de aviso antes de la cabecera, un código postal al que una hoja de
| cálculo le comió el cero, un asentamiento repetido, uno sin tipo y otro sin
| ciudad. El catálogo real no se copia aquí: pesa catorce megas y tiene su propia
| licencia.
|
*/

/** Las 20 filas del fixture menos la repetida. */
const MX_FIXTURE_SETTLEMENTS = 19;

function mxFixturePath(): string
{
    return __DIR__.'/../fixtures/sepomex-sample.txt';
}

/**
 * Enciende el módulo sobre la aplicación en marcha y ejecuta el callback.
 *
 * **Por qué no `withEnvironment()`**: ese helper rearranca la aplicación, y
 * `RefreshDatabase` deja abierta una transacción sobre el PDO en memoria que la
 * conexión nueva ya no contabiliza (`Connection::setPdo()` pone el nivel a 0).
 * El `DB::beginTransaction()` del comando intenta entonces un `BEGIN` sobre una
 * conexión que ya está en transacción y SQLite lo rechaza. Registrar el provider
 * a mano prueba lo mismo sin rearrancar; que el toggle registre o no el comando
 * es asunto de `MxToggleTest`. Mismo precedente que
 * `DevicesCleanupCommandTest::withDevicesCleanupOn()`.
 */
function withMxImportOn(Closure $callback): void
{
    Config::set('kore-app.mx.enabled', true);

    app()->register(MxModuleServiceProvider::class, force: true);

    $callback();
}

it('importa el catálogo y siembra las entidades', function (): void {
    withMxImportOn(function (): void {
        $this->artisan('mx:sepomex:import', ['path' => mxFixturePath()])
            ->assertSuccessful();

        expect(State::query()->count())->toBe(32)
            ->and(PostalCode::query()->count())->toBe(MX_FIXTURE_SETTLEMENTS);
    });
});

it('convierte el ISO-8859-1 del archivo oficial a UTF-8', function (): void {
    withMxImportOn(function (): void {
        $this->artisan('mx:sepomex:import', ['path' => mxFixturePath()])->assertSuccessful();

        // Si no convirtiera, esto sería «San Ãngel» y nadie lo miraría hasta
        // verlo impreso.
        expect(PostalCode::query()->where('settlement', 'San Ángel')->exists())->toBeTrue()
            ->and(PostalCode::query()->where('municipality', 'Álvaro Obregón')->count())->toBe(3);
    });
});

it('repone los ceros de la izquierda del código postal', function (): void {
    withMxImportOn(function (): void {
        $this->artisan('mx:sepomex:import', ['path' => mxFixturePath()])->assertSuccessful();

        // La fila de Tlacopac viene como 1000 en el fixture.
        expect(PostalCode::query()->where('settlement', 'Tlacopac')->value('postal_code'))->toBe('01000')
            ->and(PostalCode::query()->where('postal_code', '1000')->exists())->toBeFalse();
    });
});

it('rellena el tipo de asentamiento cuando el archivo lo deja vacío', function (): void {
    withMxImportOn(function (): void {
        $this->artisan('mx:sepomex:import', ['path' => mxFixturePath()])->assertSuccessful();

        // El tipo forma parte del índice único, así que no puede ser nulo.
        expect(PostalCode::query()->where('settlement', 'Mitras Centro')->value('settlement_type'))
            ->toBe('Colonia');
    });
});

it('deja la ciudad en null cuando el archivo no la trae', function (): void {
    withMxImportOn(function (): void {
        $this->artisan('mx:sepomex:import', ['path' => mxFixturePath()])->assertSuccessful();

        expect(PostalCode::query()->where('settlement', 'Ojocaliente')->value('city'))->toBeNull()
            ->and(PostalCode::query()->where('settlement', 'Centro')->where('postal_code', '44100')->value('city'))
            ->toBe('Guadalajara');
    });
});

it('es idempotente: correrlo dos veces deja la misma tabla', function (): void {
    withMxImportOn(function (): void {
        $this->artisan('mx:sepomex:import', ['path' => mxFixturePath()])->assertSuccessful();
        $this->artisan('mx:sepomex:import', ['path' => mxFixturePath()])->assertSuccessful();

        expect(PostalCode::query()->count())->toBe(MX_FIXTURE_SETTLEMENTS);
    });
});

it('actualiza el municipio de una fila que ya existía en vez de duplicarla', function (): void {
    withMxImportOn(function (): void {
        $this->artisan('mx:sepomex:import', ['path' => mxFixturePath()])->assertSuccessful();

        PostalCode::query()
            ->where('postal_code', '44100')
            ->where('settlement', 'Americana')
            ->update(['municipality' => 'Valor viejo']);

        $this->artisan('mx:sepomex:import', ['path' => mxFixturePath()])->assertSuccessful();

        expect(PostalCode::query()->where('settlement', 'Americana')->value('municipality'))
            ->toBe('Guadalajara')
            ->and(PostalCode::query()->count())->toBe(MX_FIXTURE_SETTLEMENTS);
    });
});

it('con --dry-run cuenta y no escribe nada', function (): void {
    withMxImportOn(function (): void {
        $this->artisan('mx:sepomex:import', ['path' => mxFixturePath(), '--dry-run' => true])
            ->assertSuccessful();

        // Ni el catálogo ni las entidades: el ensayo tampoco siembra.
        expect(PostalCode::query()->count())->toBe(0)
            ->and(State::query()->count())->toBe(0);
    });
});

it('lee también el CSV convertido a coma y UTF-8 que circula por ahí', function (): void {
    withMxImportOn(function (): void {
        $converted = sys_get_temp_dir().'/sepomex-utf8-'.Str::random(16).'.csv';
        $source = (string) file_get_contents(mxFixturePath());
        file_put_contents(
            $converted,
            str_replace('|', ',', mb_convert_encoding($source, 'UTF-8', 'ISO-8859-1')),
        );

        $this->artisan('mx:sepomex:import', ['path' => $converted])->assertSuccessful();

        expect(PostalCode::query()->count())->toBe(MX_FIXTURE_SETTLEMENTS)
            ->and(PostalCode::query()->where('settlement', 'San Ángel')->exists())->toBeTrue();

        unlink($converted);
    });
});

it('falla con una ruta que no existe, sin tocar la base', function (): void {
    withMxImportOn(function (): void {
        $this->artisan('mx:sepomex:import', ['path' => '/no/existe.txt'])->assertFailed();

        expect(State::query()->count())->toBe(0);
    });
});

it('falla cuando el archivo no es el de SEPOMEX', function (): void {
    withMxImportOn(function (): void {
        $other = sys_get_temp_dir().'/no-sepomex-'.Str::random(16).'.csv';
        file_put_contents($other, "columna_a,columna_b\n1,2\n");

        $this->artisan('mx:sepomex:import', ['path' => $other])->assertFailed();

        expect(PostalCode::query()->count())->toBe(0);

        unlink($other);
    });
});

it('se niega a recibir una ruta y una URL a la vez', function (): void {
    withMxImportOn(function (): void {
        $this->artisan('mx:sepomex:import', [
            'path' => mxFixturePath(),
            '--url' => 'https://example.test/CPdescarga.txt',
        ])->assertFailed();
    });
});

it('descarga el catálogo con --url', function (): void {
    withMxImportOn(function (): void {
        Http::fake([
            'example.test/*' => Http::response((string) file_get_contents(mxFixturePath())),
        ]);

        $this->artisan('mx:sepomex:import', ['--url' => 'https://example.test/CPdescarga.txt'])
            ->assertSuccessful();

        expect(PostalCode::query()->count())->toBe(MX_FIXTURE_SETTLEMENTS);
    });
});

it('falla cuando la descarga no responde 200', function (): void {
    withMxImportOn(function (): void {
        Http::fake(['example.test/*' => Http::response('', 500)]);

        $this->artisan('mx:sepomex:import', ['--url' => 'https://example.test/CPdescarga.txt'])
            ->assertFailed();

        expect(PostalCode::query()->count())->toBe(0);
    });
});
