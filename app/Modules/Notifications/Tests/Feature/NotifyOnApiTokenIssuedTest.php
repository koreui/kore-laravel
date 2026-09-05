<?php

declare(strict_types=1);

use App\Core\Contracts\Notifier;
use App\Models\User;
use App\Modules\Auth\Events\ApiTokenIssued;
use App\Modules\Notifications\Listeners\NotifyOnApiTokenIssued;
use App\Modules\Notifications\Support\DatabaseNotifier;

/*
|--------------------------------------------------------------------------
| NotifyOnApiTokenIssued — la frontera de eventos (R5)
|--------------------------------------------------------------------------
|
| `App\Modules\Auth\Events\ApiTokenIssued` es la única parte de Auth que este
| módulo importa, y Auth no sabe que Notifications existe. El listener se
| ejercita a mano (no por el `Event::listen` del provider, que ya comprueba
| `NotificationsToggleTest`) para poder mirar el aviso que produce.
|
*/

/** El listener con el contrato resuelto, como lo arma el contenedor. */
function tokenIssuedListener(): NotifyOnApiTokenIssued
{
    app()->bind(Notifier::class, DatabaseNotifier::class);

    return resolve(NotifyOnApiTokenIssued::class);
}

it('avisa a quien acaba de emitir un token', function (): void {
    $ada = User::factory()->create();
    $token = $ada->createToken('iPhone de Ada');

    tokenIssuedListener()->handle(new ApiTokenIssued(
        user: $ada,
        tokenId: (int) $token->accessToken->getKey(),
        tokenName: 'iPhone de Ada',
        deviceId: 'device-1',
        platform: 'ios',
        appVersion: '2.4.0',
    ));

    $payload = (array) $ada->notifications()->firstOrFail()->getAttribute('data');

    expect($ada->unreadNotifications()->count())->toBe(1)
        ->and($payload['category'])->toBe('account')
        ->and($payload['title'])->toBe('Nuevo inicio de sesión por API')
        // R34: el nombre del token viaja como placeholder, no interpolado.
        ->and($payload['body'])->toBe('Se emitió un token para «iPhone de Ada».')
        ->and($payload['data'])->toBe([
            'token_id' => (int) $token->accessToken->getKey(),
            'device_id' => 'device-1',
            'platform' => 'ios',
            'app_version' => '2.4.0',
        ]);
});

it('no avisa a nadie más', function (): void {
    [$ada, $lin] = [User::factory()->create(), User::factory()->create()];
    $token = $ada->createToken('CLI');

    tokenIssuedListener()->handle(new ApiTokenIssued(
        user: $ada,
        tokenId: (int) $token->accessToken->getKey(),
        tokenName: 'CLI',
    ));

    expect($lin->notifications()->count())->toBe(0);
});
