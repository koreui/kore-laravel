<?php

declare(strict_types=1);

use App\Modules\Webhooks\Rules\PublicHttpUrl;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;

/*
|--------------------------------------------------------------------------
| PublicHttpUrl — la puerta cerrada al SSRF
|--------------------------------------------------------------------------
|
| Todo el archivo evita el DNS de verdad: se usan IPs literales (que la regla
| toma tal cual) y `localhost`, que resuelve por `/etc/hosts` sin salir a la
| red. Un test que dependiera de resolver `example.com` fallaría en un portátil
| sin conexión, y esta regla se prueba igual sin ella.
|
*/

beforeEach(function (): void {
    Config::set('kore-webhooks.allow_private_networks', false);
});

/** ¿Pasa la regla esta URL? */
function urlEsAdmisible(string $url): bool
{
    return Validator::make(['url' => $url], ['url' => [new PublicHttpUrl]])->passes();
}

it('rechaza las direcciones internas escritas como IP literal', function (string $url): void {
    expect(urlEsAdmisible($url))->toBeFalse();
})->with([
    'loopback IPv4' => 'https://127.0.0.1/hooks',
    'loopback, otra del /8' => 'https://127.1.2.3/hooks',
    'loopback IPv6' => 'https://[::1]/hooks',
    'metadatos de la nube' => 'https://169.254.169.254/latest/meta-data/',
    'link-local IPv6' => 'https://[fe80::1]/hooks',
    'privada 10/8' => 'https://10.0.0.5/hooks',
    'privada 172.16/12' => 'https://172.16.31.9/hooks',
    'privada 192.168/16' => 'https://192.168.1.10/hooks',
    'privada IPv6 fc00::/7' => 'https://[fd00::1]/hooks',
    'todo ceros' => 'https://0.0.0.0/hooks',
    'IPv4 mapeada en IPv6' => 'https://[::ffff:127.0.0.1]/hooks',
]);

it('admite una IP pública literal', function (): void {
    expect(urlEsAdmisible('https://8.8.8.8/hooks'))->toBeTrue();
});

it('rechaza un nombre que resuelve a una dirección interna', function (): void {
    // `localhost` resuelve por /etc/hosts a 127.0.0.1: es el caso de
    // «bloquear la cadena no sirve, hay que mirar la dirección».
    expect(urlEsAdmisible('https://localhost/hooks'))->toBeFalse();
});

it('rechaza un host que no resuelve a nada', function (): void {
    // `.invalid` está reservado por la RFC 2606 justo para esto.
    expect(urlEsAdmisible('https://receptor.invalid/hooks'))->toBeFalse();
});

it('rechaza una URL sin host', function (): void {
    expect(urlEsAdmisible('https:///hooks'))->toBeFalse();
});

it('nombra la dirección culpable en el mensaje', function (): void {
    $validator = Validator::make(['url' => 'https://169.254.169.254/'], ['url' => [new PublicHttpUrl]]);

    expect($validator->errors()->first('url'))->toContain('169.254.169.254');
});

it('deja pasar cualquier dirección con allow_private_networks encendido', function (): void {
    Config::set('kore-webhooks.allow_private_networks', true);

    expect(urlEsAdmisible('https://127.0.0.1/hooks'))->toBeTrue()
        ->and(urlEsAdmisible('https://169.254.169.254/'))->toBeTrue()
        ->and(urlEsAdmisible('https://receptor.invalid/hooks'))->toBeTrue();
});

it('no opina sobre un valor vacío ni sobre algo que no es una cadena', function (): void {
    // El `required` y el `url:` de la lista son los que responden por eso; una
    // regla que además fallara aquí duplicaría el mensaje.
    expect(urlEsAdmisible(''))->toBeTrue();

    expect(Validator::make(['url' => 42], ['url' => [new PublicHttpUrl]])->passes())->toBeTrue();
});
