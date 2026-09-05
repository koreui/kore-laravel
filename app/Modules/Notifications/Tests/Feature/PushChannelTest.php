<?php

declare(strict_types=1);

use App\Core\Contracts\PushTokenDirectory;
use App\Core\Data\NotificationData;
use App\Core\Enums\NotificationCategory;
use App\Models\User;
use App\Modules\Notifications\Support\GenericNotification;
use App\Modules\Notifications\Support\NotificationPreferences;
use App\Modules\Notifications\Support\PushChannel;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| PushChannel — cableado entero, envío no
|--------------------------------------------------------------------------
|
| El canal NO manda nada a ningún servicio: deja una línea en el log. No es un
| descuido, es la decisión del boilerplate — no puede elegir por un derivado
| entre FCM, APNs o Expo, y fingir una entrega que no ocurre es peor que no
| tenerla. Lo que sí está resuelto y se prueba aquí es todo lo demás: de dónde
| salen los tokens, qué pasa cuando no hay directorio y qué NO se escribe.
|
*/

/** Un directorio de tokens de mentira, para no depender del módulo Devices. */
function fakePushDirectory(string ...$tokens): PushTokenDirectory
{
    return new readonly class($tokens) implements PushTokenDirectory
    {
        /** @param array<int, string> $tokens */
        public function __construct(private array $tokens) {}

        /** @return array<int, string> */
        public function tokensFor(int $userId): array
        {
            return $this->tokens;
        }
    };
}

function pushNotification(): GenericNotification
{
    return new GenericNotification(
        new NotificationData(
            title: 'Nuevo inicio de sesión',
            body: 'Se emitió un token.',
            category: NotificationCategory::Account->value,
            url: '/notifications',
        ),
        resolve(NotificationPreferences::class),
    );
}

it('loguea el aviso con los tokens del directorio', function (): void {
    Log::spy();

    $ada = User::factory()->create();
    app()->instance(PushTokenDirectory::class, fakePushDirectory('token-a', 'token-b'));

    resolve(PushChannel::class)->send($ada, pushNotification());

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'notifications.push'
            && $context['user_id'] === $ada->getKey()
            && $context['tokens'] === 2
            && $context['category'] === 'account'
            && $context['title'] === 'Nuevo inicio de sesión');
});

it('no escribe los tokens en el log', function (): void {
    // Un token de push es la credencial con la que se le manda una notificación
    // a ese teléfono: el log no es sitio para una credencial.
    Log::spy();

    $ada = User::factory()->create();
    app()->instance(PushTokenDirectory::class, fakePushDirectory('token-secreto'));

    resolve(PushChannel::class)->send($ada, pushNotification());

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => ! in_array('token-secreto', $context, true));
});

it('avisa una vez y no envía cuando no hay directorio bindeado', function (): void {
    // Es el caso normal con DEVICES_ENABLED=false: sin inventario no hay a
    // dónde mandar un push, y eso no puede tumbar un aviso que ya está en la
    // bandeja.
    Log::spy();

    $ada = User::factory()->create();

    expect(app()->bound(PushTokenDirectory::class))->toBeFalse();

    resolve(PushChannel::class)->send($ada, pushNotification());

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(fn (string $message): bool => $message === 'notifications.push: sin directorio de tokens, no se envía nada.');
});

it('no loguea nada cuando la persona no tiene ningún token', function (): void {
    Log::spy();

    $ada = User::factory()->create();
    app()->instance(PushTokenDirectory::class, fakePushDirectory());

    resolve(PushChannel::class)->send($ada, pushNotification());

    Log::shouldNotHaveReceived('info');
});

it('ignora lo que no es un usuario o no es una notificación del módulo', function (): void {
    Log::spy();

    app()->instance(PushTokenDirectory::class, fakePushDirectory('token-a'));

    resolve(PushChannel::class)->send(new stdClass, pushNotification());
    resolve(PushChannel::class)->send(User::factory()->create(), new BaseNotification);

    Log::shouldNotHaveReceived('info');
});
