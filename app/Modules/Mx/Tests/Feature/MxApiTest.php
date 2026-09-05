<?php

declare(strict_types=1);

use App\Modules\Mx\Models\PostalCode;
use App\Modules\Mx\Models\State;
use App\Modules\Mx\Providers\MxModuleServiceProvider;
use Illuminate\Support\Facades\Config;

/*
|--------------------------------------------------------------------------
| API de México
|--------------------------------------------------------------------------
|
| Los dos endpoints de `api/v1/mx`, contra el contrato de R54: envelope
| `{ data }` en éxito y `{ error: { code, message } }` en fallo, con los códigos
| canónicos (`not_found`, `validation_failed`).
|
| Los dos son PÚBLICOS: son catálogo y función pura, no leen nada de nadie. Que
| no pidan token es parte de lo que se prueba aquí, no un descuido.
|
*/

/**
 * Enciende el módulo sobre la aplicación en marcha y ejecuta el callback.
 *
 * Mismo motivo que en `DevicesApiTest`: `withEnvironment()` rearranca la
 * aplicación y deja la conexión con el nivel de transacción a 0 sobre un PDO que
 * sí está en transacción. Que el toggle encienda o apague estas rutas es asunto
 * de `MxToggleTest`, que sí usa `withEnvironment()` porque allí no se escribe.
 */
function withMxApiOn(Closure $callback): void
{
    Config::set('kore-app.mx.enabled', true);
    Config::set('kore-app.api.enabled', true);

    app()->register(MxModuleServiceProvider::class, force: true);

    $callback();
}

function seedMxCatalog(): void
{
    State::factory()->create(['code' => '09', 'name' => 'Ciudad de México', 'abbreviation' => 'CDMX']);

    PostalCode::factory()->create([
        'postal_code' => '01000',
        'settlement' => 'San Ángel',
        'settlement_type' => 'Colonia',
        'municipality' => 'Álvaro Obregón',
        'city' => 'Ciudad de México',
        'state_code' => '09',
    ]);

    PostalCode::factory()->create([
        'postal_code' => '01000',
        'settlement' => 'Axotla',
        'settlement_type' => 'Pueblo',
        'municipality' => 'Álvaro Obregón',
        'city' => 'Ciudad de México',
        'state_code' => '09',
    ]);
}

/*
|--------------------------------------------------------------------------
| GET /api/v1/mx/postal-codes/{postalCode}
|--------------------------------------------------------------------------
*/

it('devuelve el código postal con sus colonias, sin autenticar', function (): void {
    withMxApiOn(function (): void {
        seedMxCatalog();

        $this->getJson('/api/v1/mx/postal-codes/01000')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'postal_code' => '01000',
                    'state' => ['code' => '09', 'name' => 'Ciudad de México'],
                    'municipality' => 'Álvaro Obregón',
                    'city' => 'Ciudad de México',
                    'settlements' => [
                        ['name' => 'Axotla', 'type' => 'Pueblo'],
                        ['name' => 'San Ángel', 'type' => 'Colonia'],
                    ],
                ],
            ]);
    });
});

it('responde 404 con el código canónico cuando el CP no está en el catálogo', function (): void {
    withMxApiOn(function (): void {
        $this->getJson('/api/v1/mx/postal-codes/99999')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found')
            ->assertJsonMissingPath('data');
    });
});

it('responde 422 cuando el código postal no son cinco dígitos', function (): void {
    withMxApiOn(function (): void {
        // Un 404 aquí mentiría: el recurso no es que no exista, es que la
        // petición está mal escrita.
        $this->getJson('/api/v1/mx/postal-codes/1000')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['postalCode']]]);

        $this->getJson('/api/v1/mx/postal-codes/abcde')->assertStatus(422);
    });
});

it('deja cachear la respuesta del catálogo en el cliente', function (): void {
    withMxApiOn(function (): void {
        seedMxCatalog();

        $response = $this->getJson('/api/v1/mx/postal-codes/01000')->assertOk();

        expect($response->headers->get('ETag'))->not->toBeNull()
            // `private`: la respuesta no depende de quién pregunte, pero el
            // middleware del contrato marca así todas.
            ->and($response->headers->get('Cache-Control'))->toContain('max-age=3600');
    });
});

/*
|--------------------------------------------------------------------------
| GET /api/v1/mx/amount-in-words
|--------------------------------------------------------------------------
*/

it('devuelve el importe en letra', function (): void {
    withMxApiOn(function (): void {
        $this->getJson('/api/v1/mx/amount-in-words?amount=1234.56')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'amount' => 1234.56,
                    'words' => 'UN MIL DOSCIENTOS TREINTA Y CUATRO PESOS 56/100 M.N.',
                ],
            ]);
    });
});

it('devuelve el importe ya redondeado junto a la letra', function (): void {
    withMxApiOn(function (): void {
        $this->getJson('/api/v1/mx/amount-in-words?amount=1234.567')
            ->assertOk()
            ->assertJsonPath('data.amount', 1234.57)
            ->assertJsonPath('data.words', 'UN MIL DOSCIENTOS TREINTA Y CUATRO PESOS 57/100 M.N.');
    });
});

it('responde 422 cuando falta el importe o no es un número', function (): void {
    withMxApiOn(function (): void {
        $this->getJson('/api/v1/mx/amount-in-words')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');

        $this->getJson('/api/v1/mx/amount-in-words?amount=mucho')->assertStatus(422);
    });
});

it('responde 422 en vez de 500 con un importe fuera de escala o negativo', function (): void {
    withMxApiOn(function (): void {
        // El tope del request es el mismo que sabe nombrar MontoEnLetras: por
        // encima, la clase lanzaría y el cliente vería un 500 donde lo correcto
        // es decirle que el dato no vale.
        $this->getJson('/api/v1/mx/amount-in-words?amount=1000000000000')->assertStatus(422);
        $this->getJson('/api/v1/mx/amount-in-words?amount=-1')->assertStatus(422);
    });
});
