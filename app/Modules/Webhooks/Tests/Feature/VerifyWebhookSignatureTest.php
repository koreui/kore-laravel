<?php

declare(strict_types=1);

use App\Core\Support\WebhookSignature;
use App\Modules\Webhooks\Http\Middleware\VerifyWebhookSignature;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| El lado RECEPTOR: middleware `webhook.signed`
|--------------------------------------------------------------------------
|
| El boilerplate manda webhooks, no los recibe: ninguna ruta suya lleva este
| middleware. Existe para el derivado que sí los reciba, así que el test monta
| su propia ruta —igual de real que la que montaría ese derivado— en vez de
| esperar a que alguien la escriba.
|
| La clase se registra a mano y no por el alias: el alias sólo existe con el
| toggle encendido y lo que se prueba aquí es el middleware, no el provider.
|
*/

const INBOUND_SECRET = 'secreto-del-emisor';

beforeEach(function (): void {
    Config::set('kore-webhooks.inbound_secret', INBOUND_SECRET);
    Config::set('kore-webhooks.tolerance_seconds', 300);

    Route::post('/_test/hooks', fn (): array => ['ok' => true])
        ->middleware(['api', VerifyWebhookSignature::class]);
});

/**
 * Cabecera firmada para un cuerpo y un desfase de reloj dados.
 */
function signedHeader(string $body, string $secret = INBOUND_SECRET, int $offsetSeconds = 0): string
{
    $timestamp = (string) CarbonImmutable::now()->addSeconds($offsetSeconds)->getTimestamp();

    return WebhookSignature::header($timestamp, WebhookSignature::sign($secret, $timestamp, $body));
}

it('deja pasar una petición bien firmada', function (): void {
    $body = '{"event":"orders.created"}';

    $this->call(
        method: 'POST',
        uri: '/_test/hooks',
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_KORE_SIGNATURE' => signedHeader($body),
        ],
        content: $body,
    )->assertOk();
});

it('rechaza con 401 una firma de otro secreto', function (): void {
    $body = '{"event":"orders.created"}';

    $this->call(
        method: 'POST',
        uri: '/_test/hooks',
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_KORE_SIGNATURE' => signedHeader($body, secret: 'otro-secreto'),
        ],
        content: $body,
    )->assertUnauthorized();
});

it('rechaza con 401 un cuerpo cambiado después de firmar', function (): void {
    // Es el ataque que el middleware existe para parar.
    $header = signedHeader('{"event":"orders.created"}');

    $this->call(
        method: 'POST',
        uri: '/_test/hooks',
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_KORE_SIGNATURE' => $header,
        ],
        content: '{"event":"orders.deleted"}',
    )->assertUnauthorized();
});

it('rechaza con 401 una firma fuera de la ventana', function (): void {
    $body = '{"event":"orders.created"}';

    $this->call(
        method: 'POST',
        uri: '/_test/hooks',
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_KORE_SIGNATURE' => signedHeader($body, offsetSeconds: -600),
        ],
        content: $body,
    )->assertUnauthorized();
});

it('rechaza con 401 si no viene cabecera', function (): void {
    $this->postJson('/_test/hooks', ['event' => 'orders.created'])
        ->assertUnauthorized();
});

it('devuelve 404 si esta instalación no tiene secreto de entrada', function (): void {
    // Sin secreto configurado el endpoint sencillamente no existe: mejor eso
    // que quedar abierto por omisión en una instalación que no lo usa.
    Config::set('kore-webhooks.inbound_secret');

    $body = '{"event":"orders.created"}';

    $this->call(
        method: 'POST',
        uri: '/_test/hooks',
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_KORE_SIGNATURE' => signedHeader($body),
        ],
        content: $body,
    )->assertNotFound();
});
