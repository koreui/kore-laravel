<?php

declare(strict_types=1);

use App\Core\Data\NotificationData;
use App\Core\Enums\NotificationCategory;
use App\Models\User;
use App\Modules\Notifications\Actions\NotificationSendAction;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Providers\NotificationsModuleServiceProvider;
use Illuminate\Support\Facades\Config;

/*
|--------------------------------------------------------------------------
| API de notificaciones (`api/v1/me/*`)
|--------------------------------------------------------------------------
|
| Contra el contrato de R54: envelope `{ data, meta? }` en éxito,
| `{ error: { code, message } }` en fallo y códigos canónicos.
|
| El módulo se enciende sobre la aplicación en marcha (mismo atajo que
| `DevicesApiTest`): `withEnvironment()` rearranca y aquí hacen falta las rutas
| y las policies en la misma instancia que el test.
|
| Se manda el bearer en vez de `Sanctum::actingAs()` para ejercitar de verdad
| el `auth:sanctum` de las rutas.
|
*/

function withNotificationsApiOn(Closure $callback): void
{
    Config::set('kore-app.notifications.enabled', true);
    Config::set('kore-app.api.enabled', true);

    app()->register(NotificationsModuleServiceProvider::class, force: true);

    $callback();
}

/**
 * Usuario con token y `$count` avisos en la bandeja.
 *
 * @return array{0: User, 1: string}
 */
function notificationsApiActor(int $count = 1, string $category = 'system'): array
{
    $user = User::factory()->create();
    $token = $user->createToken('iPhone de Ada');

    foreach (range(1, max(0, $count)) as $i) {
        resolve(NotificationSendAction::class)->handle([(int) $user->getKey()], new NotificationData(
            title: "Aviso {$i}",
            body: 'Cuerpo del aviso.',
            category: $category,
            url: '/notifications',
            data: ['n' => $i],
            mail: false,
            push: false,
        ));
    }

    return [$user, $token->plainTextToken];
}

/*
|--------------------------------------------------------------------------
| GET /api/v1/me/notifications
|--------------------------------------------------------------------------
*/

it('rechaza a quien no manda token', function (): void {
    withNotificationsApiOn(function (): void {
        $this->getJson('/api/v1/me/notifications')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');
    });
});

it('lista sólo la bandeja del usuario del token, con su envelope', function (): void {
    withNotificationsApiOn(function (): void {
        [, $token] = notificationsApiActor(2);
        notificationsApiActor(1);

        $response = $this->withToken($token)->getJson('/api/v1/me/notifications')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.unread_count', 2)
            ->assertJsonStructure([
                'data' => [['id', 'category', 'title', 'body', 'url', 'payload', 'read', 'read_at', 'created_at']],
                'meta' => ['next_cursor', 'prev_cursor', 'per_page', 'unread_count'],
            ]);

        expect($response->json('data.0.url'))->toBe('/notifications')
            ->and($response->json('data.0.payload'))->toHaveKey('n');
    });
});

it('filtra por no leídas y por categoría', function (): void {
    withNotificationsApiOn(function (): void {
        [$user, $token] = notificationsApiActor(1, NotificationCategory::Account->value);

        resolve(NotificationSendAction::class)->handle([(int) $user->getKey()], new NotificationData(
            title: 'De sistema',
            body: 'Cuerpo.',
            category: NotificationCategory::System->value,
            mail: false,
            push: false,
        ));

        $this->withToken($token)->getJson('/api/v1/me/notifications?category=account')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.category', 'account');

        $this->withToken($token)->getJson('/api/v1/me/notifications?unread=1')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });
});

it('acota el tamaño de página al máximo del contrato', function (): void {
    withNotificationsApiOn(function (): void {
        [, $token] = notificationsApiActor(3);

        $this->withToken($token)->getJson('/api/v1/me/notifications?per_page=100000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', (int) config('kore-api.pagination.max'));
    });
});

/*
|--------------------------------------------------------------------------
| POST /api/v1/me/notifications/{id}/read · read-all
|--------------------------------------------------------------------------
*/

it('marca una notificación como leída y devuelve el contador', function (): void {
    withNotificationsApiOn(function (): void {
        [$user, $token] = notificationsApiActor(2);
        $id = (string) $user->notifications()->firstOrFail()->getKey();

        $this->withToken($token)->postJson("/api/v1/me/notifications/{$id}/read")
            ->assertOk()
            ->assertJsonPath('data.read', true)
            ->assertJsonPath('meta.unread_count', 1);
    });
});

it('devuelve 404 con la notificación de otra persona', function (): void {
    // 404 y no 403: decir «existe pero no es tuya» confirmaría el uuid a quien
    // lo estaba probando.
    withNotificationsApiOn(function (): void {
        [, $token] = notificationsApiActor(1);
        [$otra] = notificationsApiActor(1);

        $ajena = (string) $otra->notifications()->firstOrFail()->getKey();

        $this->withToken($token)->postJson("/api/v1/me/notifications/{$ajena}/read")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        expect($otra->unreadNotifications()->count())->toBe(1);
    });
});

it('marca todas como leídas', function (): void {
    withNotificationsApiOn(function (): void {
        [$user, $token] = notificationsApiActor(3);

        $this->withToken($token)->postJson('/api/v1/me/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.marked', 3)
            ->assertJsonPath('data.unread_count', 0);

        expect($user->unreadNotifications()->count())->toBe(0);
    });
});

/*
|--------------------------------------------------------------------------
| GET|PUT /api/v1/me/notification-preferences
|--------------------------------------------------------------------------
*/

it('publica el catálogo completo con los defaults ya aplicados', function (): void {
    withNotificationsApiOn(function (): void {
        [, $token] = notificationsApiActor(0);

        $this->withToken($token)->getJson('/api/v1/me/notification-preferences')
            ->assertOk()
            ->assertJsonCount(count(NotificationCategory::cases()), 'data')
            ->assertJsonStructure(['data' => [['category', 'label', 'in_app', 'mail', 'push']], 'meta' => ['push_available']])
            ->assertJsonPath('meta.push_available', false);
    });
});

it('guarda una preferencia y devuelve el catálogo recalculado', function (): void {
    withNotificationsApiOn(function (): void {
        [$user, $token] = notificationsApiActor(0);

        $response = $this->withToken($token)->putJson('/api/v1/me/notification-preferences', [
            'category' => NotificationCategory::Account->value,
            'in_app' => true,
            'mail' => false,
            'push' => false,
        ])->assertOk();

        expect(NotificationPreference::query()->where('user_id', $user->getKey())->count())->toBe(1);

        $account = collect($response->json('data'))->firstWhere('category', 'account');

        expect($account['mail'])->toBeFalse();
    });
});

it('rechaza una categoría que no está en el catálogo', function (): void {
    withNotificationsApiOn(function (): void {
        [, $token] = notificationsApiActor(0);

        $this->withToken($token)->putJson('/api/v1/me/notification-preferences', [
            'category' => 'inventada',
            'in_app' => true,
            'mail' => true,
            'push' => true,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['category']]]);
    });
});

it('exige los tres canales: un PUT no es un PATCH disfrazado', function (): void {
    withNotificationsApiOn(function (): void {
        [, $token] = notificationsApiActor(0);

        $this->withToken($token)->putJson('/api/v1/me/notification-preferences', [
            'category' => NotificationCategory::Account->value,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    });
});
