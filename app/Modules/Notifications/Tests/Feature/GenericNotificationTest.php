<?php

declare(strict_types=1);

use App\Core\Data\NotificationData;
use App\Core\Enums\NotificationCategory;
use App\Models\User;
use App\Modules\Notifications\Actions\NotificationSendAction;
use App\Modules\Notifications\Database\Factories\NotificationPreferenceFactory;
use App\Modules\Notifications\Support\GenericNotification;
use App\Modules\Notifications\Support\NotificationPreferences;
use App\Modules\Notifications\Support\PushChannel;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| GenericNotification::via() — el cruce de payload y preferencias
|--------------------------------------------------------------------------
|
| Es la pieza donde se decide si un aviso sale y por dónde, así que se prueba
| canal a canal. Dos reglas que no son intercambiables:
|
|   - `database` sólo mira la preferencia. Es el canal base: `NotificationData`
|     ni siquiera tiene con qué apagarlo desde quien notifica.
|   - `mail` y `push` miran las DOS cosas. Los booleanos del payload son un
|     techo («este aviso puede salir por correo»), nunca una orden: no hay forma
|     de saltarse la preferencia de una persona desde el código que avisa.
|
*/

/** El aviso armado tal como lo construye la Action. */
function notificationFor(NotificationData $payload): GenericNotification
{
    return new GenericNotification($payload, resolve(NotificationPreferences::class));
}

it('manda por bandeja y correo cuando la categoría lo trae por defecto', function (): void {
    $ada = User::factory()->create();

    // `account` viene con in_app y mail encendidos, push apagado.
    $channels = notificationFor(new NotificationData(
        title: 'Hola',
        body: 'Qué tal',
        category: NotificationCategory::Account->value,
    ))->via($ada);

    expect($channels)->toBe(['database', 'mail']);
});

it('no manda correo cuando la categoría no lo trae por defecto', function (): void {
    $ada = User::factory()->create();

    // `activity` viene sólo con la bandeja.
    $channels = notificationFor(new NotificationData(
        title: 'Hola',
        body: 'Qué tal',
        category: NotificationCategory::Activity->value,
    ))->via($ada);

    expect($channels)->toBe(['database']);
});

it('respeta la preferencia guardada por encima del default', function (): void {
    $ada = User::factory()->create();

    NotificationPreferenceFactory::new()->create([
        'user_id' => $ada->getKey(),
        'category' => NotificationCategory::Account->value,
        'in_app' => true,
        'mail' => false,
        'push' => true,
    ]);

    $channels = notificationFor(new NotificationData(
        title: 'Hola',
        body: 'Qué tal',
        category: NotificationCategory::Account->value,
    ))->via($ada);

    expect($channels)->toBe(['database', PushChannel::class]);
});

it('no manda por un canal que el payload no admite aunque la persona lo quiera', function (): void {
    // El booleano del payload es un techo: un aviso declarado `mail: false` no
    // sale por correo ni para quien lo tiene encendido.
    $ada = User::factory()->create();

    NotificationPreferenceFactory::new()->create([
        'user_id' => $ada->getKey(),
        'category' => NotificationCategory::Account->value,
        'in_app' => true,
        'mail' => true,
        'push' => true,
    ]);

    $channels = notificationFor(new NotificationData(
        title: 'Hola',
        body: 'Qué tal',
        category: NotificationCategory::Account->value,
        mail: false,
        push: false,
    ))->via($ada);

    expect($channels)->toBe(['database']);
});

it('no manda nada a quien apagó los tres canales', function (): void {
    $ada = User::factory()->create();

    NotificationPreferenceFactory::new()->silenced()->create([
        'user_id' => $ada->getKey(),
        'category' => NotificationCategory::Account->value,
    ]);

    expect(notificationFor(new NotificationData(
        title: 'Hola',
        body: 'Qué tal',
        category: NotificationCategory::Account->value,
    ))->via($ada))->toBe([]);
});

it('no manda nada a un notifiable que no es un usuario', function (): void {
    expect(notificationFor(new NotificationData(title: 'Hola', body: 'Qué tal'))->via(new stdClass))->toBe([]);
});

it('guarda el payload aplanado en la columna data', function (): void {
    $ada = User::factory()->create();

    resolve(NotificationSendAction::class)->handle([(int) $ada->getKey()], new NotificationData(
        title: 'Nuevo inicio de sesión',
        body: 'Se emitió un token.',
        category: NotificationCategory::Account->value,
        url: '/notifications',
        data: ['token_id' => 7],
        mail: false,
    ));

    $stored = (array) $ada->notifications()->firstOrFail()->getAttribute('data');

    expect($stored)->toBe([
        'category' => 'account',
        'title' => 'Nuevo inicio de sesión',
        'body' => 'Se emitió un token.',
        'url' => '/notifications',
        'data' => ['token_id' => 7],
    ]);
});

it('pone el enlace del payload como acción del correo', function (): void {
    Notification::fake();

    $ada = User::factory()->create();

    resolve(NotificationSendAction::class)->handle([(int) $ada->getKey()], new NotificationData(
        title: 'Nuevo inicio de sesión',
        body: 'Se emitió un token.',
        category: NotificationCategory::Account->value,
        url: '/notifications',
    ));

    Notification::assertSentTo($ada, GenericNotification::class, function (GenericNotification $notification) use ($ada): bool {
        $mail = $notification->toMail($ada);

        return $mail->subject === 'Nuevo inicio de sesión'
            && $mail->actionUrl === url('/notifications');
    });
});
