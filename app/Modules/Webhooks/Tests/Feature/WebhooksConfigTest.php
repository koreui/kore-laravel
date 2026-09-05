<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| config/kore-webhooks.php
|--------------------------------------------------------------------------
|
| `config/kore-webhooks.php` NO es `config/kore-app.php`: no declara capacidades
| sino parámetros, así que el check R11 no lo mira. Este test es su equivalente,
| y vigila las cifras cuyo valor cambia el comportamiento de forma que no se ve
| hasta que un webhook no llega.
|
*/

it('los timeouts son cortos y positivos', function (): void {
    // La entrega la hace un worker: un timeout largo no molesta a nadie hasta
    // que mil entregas lo multiplican y atascan la cola entera.
    expect((int) config('kore-webhooks.timeout'))
        ->toBeGreaterThan(0)
        ->toBeLessThanOrEqual(30)
        ->and((int) config('kore-webhooks.connect_timeout'))
        ->toBeGreaterThan(0)
        ->toBeLessThanOrEqual((int) config('kore-webhooks.timeout'));
});

it('el backoff es creciente y cabe en los intentos configurados', function (): void {
    /** @var array<int, int> $backoff */
    $backoff = (array) config('kore-webhooks.backoff');

    expect($backoff)->not->toBeEmpty();

    $anterior = 0;

    foreach ($backoff as $espera) {
        expect((int) $espera)->toBeGreaterThan($anterior);
        $anterior = (int) $espera;
    }

    // Un `max_attempts` menor que la lista dejaría tramos del backoff sin usar,
    // y la documentación mentiría sobre cuánto se insiste.
    expect((int) config('kore-webhooks.max_attempts'))
        ->toBeGreaterThan(count($backoff));
});

it('la ventana de la firma es la misma para los dos lados y es razonable', function (): void {
    // Demasiado corta rechaza a un emisor con el reloj desviado; demasiado larga
    // deja repetir una petición capturada.
    expect((int) config('kore-webhooks.tolerance_seconds'))
        ->toBeGreaterThanOrEqual(60)
        ->toBeLessThanOrEqual(900);
});

it('no viene ningún secreto de entrada por defecto', function (): void {
    // Vacío significa «esta instalación no recibe webhooks», y el middleware
    // devuelve 404 en vez de quedar abierto por omisión.
    expect(config('kore-webhooks.inbound_secret'))->toBeNull();
});

it('exige https por defecto', function (): void {
    expect(config('kore-webhooks.require_https'))->toBeTrue();
});

it('no admite redes internas por defecto, tampoco en testing', function (): void {
    // A diferencia de `require_https`, esta clave NO se relaja fuera de
    // producción: si se apagara sola en `local` o en `testing`, el único sitio
    // donde `Rules\PublicHttpUrl` se probaría de verdad sería producción.
    expect(config('kore-webhooks.allow_private_networks'))->toBeFalse();
});

it('el catálogo de eventos no está vacío y usa el convenio de nombres', function (): void {
    /** @var array<string, string> $events */
    $events = (array) config('kore-webhooks.events');

    expect($events)->not->toBeEmpty()
        ->and($events)->toHaveKey('auth.api_token.issued');

    foreach ($events as $name => $description) {
        expect($name)->toMatch('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/')
            ->and($description)->toBeString()->not->toBeEmpty();
    }
});

it('la retención y el tope de la pasada son positivos', function (): void {
    expect((int) config('kore-webhooks.prune_after_days'))->toBeGreaterThan(0)
        ->and((int) config('kore-webhooks.dispatch_batch'))->toBeGreaterThan(0)
        ->and((int) config('kore-webhooks.error_max_length'))->toBeGreaterThan(0);
});
