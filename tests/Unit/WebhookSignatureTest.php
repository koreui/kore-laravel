<?php

declare(strict_types=1);

use App\Core\Support\WebhookSignature;

/*
|--------------------------------------------------------------------------
| App\Core\Support\WebhookSignature
|--------------------------------------------------------------------------
|
| La pieza que autentica un webhook, y por tanto la que decide si el receptor
| se cree lo que le llega. Es pura —tres cadenas y un entero—, así que se prueba
| sin base de datos y sin aplicación: este archivo vive en `tests/Unit`.
|
| Lo que se comprueba no es «que firme», sino las cuatro formas de colarse:
| cambiar el cuerpo, cambiar el timestamp, reenviar una petición vieja y traer
| una firma que no lo es.
|
*/

const WEBHOOK_SECRET = 'secreto-compartido-de-prueba';
const WEBHOOK_BODY = '{"id":"abc","event":"orders.created"}';

it('firma de forma determinista y distingue el secreto', function (): void {
    $primera = WebhookSignature::sign(WEBHOOK_SECRET, '1772668800', WEBHOOK_BODY);

    expect($primera)
        ->toBe(WebhookSignature::sign(WEBHOOK_SECRET, '1772668800', WEBHOOK_BODY))
        ->toMatch('/^[0-9a-f]{64}$/')
        ->not->toBe(WebhookSignature::sign('otro-secreto', '1772668800', WEBHOOK_BODY));
});

it('compone la cabecera con el esquema versionado', function (): void {
    expect(WebhookSignature::header('1772668800', 'abc123'))
        ->toBe('t=1772668800,v1=abc123');
});

it('acepta una firma correcta dentro de la ventana', function (): void {
    $timestamp = '1772668800';
    $signature = WebhookSignature::sign(WEBHOOK_SECRET, $timestamp, WEBHOOK_BODY);

    expect(WebhookSignature::verify(
        secret: WEBHOOK_SECRET,
        timestamp: $timestamp,
        body: WEBHOOK_BODY,
        signature: $signature,
        now: 1_772_668_830,
    ))->toBeTrue();
});

it('rechaza un cuerpo manipulado', function (): void {
    // Es el ataque que la firma existe para parar: la misma cabecera con otro
    // contenido.
    $timestamp = '1772668800';
    $signature = WebhookSignature::sign(WEBHOOK_SECRET, $timestamp, WEBHOOK_BODY);

    expect(WebhookSignature::verify(
        secret: WEBHOOK_SECRET,
        timestamp: $timestamp,
        body: '{"id":"abc","event":"orders.deleted"}',
        signature: $signature,
        now: 1_772_668_800,
    ))->toBeFalse();
});

it('rechaza un timestamp manipulado, porque entra en la carga firmada', function (): void {
    $signature = WebhookSignature::sign(WEBHOOK_SECRET, '1772668800', WEBHOOK_BODY);

    expect(WebhookSignature::verify(
        secret: WEBHOOK_SECRET,
        timestamp: '1772668900',
        body: WEBHOOK_BODY,
        signature: $signature,
        now: 1_772_668_900,
    ))->toBeFalse();
});

it('rechaza una firma correcta pero vieja: la ventana corta el reenvío', function (): void {
    $timestamp = '1772668800';
    $signature = WebhookSignature::sign(WEBHOOK_SECRET, $timestamp, WEBHOOK_BODY);

    expect(WebhookSignature::verify(
        secret: WEBHOOK_SECRET,
        timestamp: $timestamp,
        body: WEBHOOK_BODY,
        signature: $signature,
        toleranceSeconds: 300,
        // 301 segundos después: un segundo fuera.
        now: 1_772_669_101,
    ))->toBeFalse();
});

it('rechaza también un timestamp del futuro', function (): void {
    // Hacia delante también: un reloj adelantado en el emisor no puede comprar
    // tiempo indefinido.
    $timestamp = '1772669101';
    $signature = WebhookSignature::sign(WEBHOOK_SECRET, $timestamp, WEBHOOK_BODY);

    expect(WebhookSignature::verify(
        secret: WEBHOOK_SECRET,
        timestamp: $timestamp,
        body: WEBHOOK_BODY,
        signature: $signature,
        toleranceSeconds: 300,
        now: 1_772_668_800,
    ))->toBeFalse();
});

it('rechaza una firma vacía y un timestamp que no es un número', function (): void {
    expect(WebhookSignature::verify(WEBHOOK_SECRET, '1772668800', WEBHOOK_BODY, '', now: 1_772_668_800))
        ->toBeFalse()
        ->and(WebhookSignature::verify(WEBHOOK_SECRET, 'ayer', WEBHOOK_BODY, 'abc', now: 1_772_668_800))
        ->toBeFalse();
});

it('desarma la cabecera en cualquier orden e ignora lo que no entiende', function (): void {
    expect(WebhookSignature::parse('t=1772668800,v1=abc'))
        ->toBe(['timestamp' => '1772668800', 'signature' => 'abc'])
        ->and(WebhookSignature::parse('v1=abc, t=1772668800'))
        ->toBe(['timestamp' => '1772668800', 'signature' => 'abc'])
        // Un `v2` futuro no puede romper a quien sólo lee `v1`.
        ->and(WebhookSignature::parse('t=1772668800,v1=abc,v2=def'))
        ->toBe(['timestamp' => '1772668800', 'signature' => 'abc']);
});

it('devuelve null cuando la cabecera falta o viene a medias', function (): void {
    expect(WebhookSignature::parse(''))->toBeNull()
        ->and(WebhookSignature::parse('t=1772668800'))->toBeNull()
        ->and(WebhookSignature::parse('v1=abc'))->toBeNull()
        ->and(WebhookSignature::parse('t=,v1=abc'))->toBeNull();
});
