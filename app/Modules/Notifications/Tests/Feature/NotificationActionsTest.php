<?php

declare(strict_types=1);

use App\Core\Data\NotificationData;
use App\Core\Enums\NotificationCategory;
use App\Models\User;
use App\Modules\Notifications\Actions\NotificationMarkAllReadAction;
use App\Modules\Notifications\Actions\NotificationMarkReadAction;
use App\Modules\Notifications\Actions\NotificationPreferenceUpdateAction;
use App\Modules\Notifications\Actions\NotificationPruneAction;
use App\Modules\Notifications\Actions\NotificationSendAction;
use App\Modules\Notifications\Data\NotificationPreferenceData;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Support\GenericNotification;
use App\Modules\Notifications\Support\NotificationPreferences;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| Las cinco Actions del módulo (R35)
|--------------------------------------------------------------------------
|
| Se prueban con el módulo APAGADO, que es como corre la suite: las Actions no
| dependen del toggle —lo que depende es el binding del contrato y las rutas—,
| y resolverlas del contenedor funciona igual. Que el toggle encienda y apague
| lo observable es asunto de `NotificationsToggleTest`.
|
*/

/** Una notificación en la bandeja de alguien, sin pasar por los canales. */
function seedNotification(User $user, string $category = 'system', ?CarbonImmutable $readAt = null): DatabaseNotification
{
    $notification = new DatabaseNotification;

    $notification->forceFill([
        'id' => (string) Str::uuid(),
        'type' => GenericNotification::class,
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->getKey(),
        'data' => ['category' => $category, 'title' => 'Hola', 'body' => 'Qué tal', 'url' => null, 'data' => []],
        'read_at' => $readAt,
    ])->save();

    return $notification;
}

/*
|--------------------------------------------------------------------------
| NotificationSendAction
|--------------------------------------------------------------------------
*/

it('manda el aviso a cada destinatario que existe', function (): void {
    Notification::fake();

    [$ada, $lin] = [User::factory()->create(), User::factory()->create()];

    $sent = resolve(NotificationSendAction::class)->handle(
        [(int) $ada->getKey(), (int) $lin->getKey()],
        new NotificationData(title: 'Hola', body: 'Qué tal'),
    );

    expect($sent)->toBe(2);

    Notification::assertSentTo([$ada, $lin], GenericNotification::class);
});

it('no avisa dos veces a quien llega por dos caminos', function (): void {
    Notification::fake();

    $ada = User::factory()->create();

    $sent = resolve(NotificationSendAction::class)->handle(
        [(int) $ada->getKey(), (int) $ada->getKey()],
        new NotificationData(title: 'Hola', body: 'Qué tal'),
    );

    expect($sent)->toBe(1);

    Notification::assertSentToTimes($ada, GenericNotification::class, 1);
});

it('no revienta cuando el destinatario ya no existe', function (): void {
    Notification::fake();

    // Un aviso es el efecto secundario de algo que ya pasó: que el
    // destinatario se borrara entre medias no puede tumbar la operación.
    $sent = resolve(NotificationSendAction::class)->handle(
        [999_999],
        new NotificationData(title: 'Hola', body: 'Qué tal'),
    );

    expect($sent)->toBe(0);

    Notification::assertNothingSent();
});

it('ignora los ids vacíos o inválidos', function (): void {
    Notification::fake();

    expect(resolve(NotificationSendAction::class)->handle([], new NotificationData(title: 'Hola', body: 'Qué tal')))->toBe(0)
        ->and(resolve(NotificationSendAction::class)->handle([0], new NotificationData(title: 'Hola', body: 'Qué tal')))->toBe(0);
});

/*
|--------------------------------------------------------------------------
| NotificationMarkReadAction
|--------------------------------------------------------------------------
*/

it('marca como leída una notificación propia', function (): void {
    $ada = User::factory()->create();
    $notification = seedNotification($ada);

    expect(resolve(NotificationMarkReadAction::class)->handle($ada, (string) $notification->getKey()))->toBeTrue()
        ->and($ada->unreadNotifications()->count())->toBe(0);
});

it('no marca la notificación de otra persona', function (): void {
    // El ámbito lo pone la relación del usuario, no un `where` que se pueda
    // olvidar: mandar el uuid de otro no marca nada.
    [$ada, $lin] = [User::factory()->create(), User::factory()->create()];
    $deLin = seedNotification($lin);

    expect(resolve(NotificationMarkReadAction::class)->handle($ada, (string) $deLin->getKey()))->toBeFalse()
        ->and($lin->unreadNotifications()->count())->toBe(1);
});

it('es idempotente: marcar dos veces no mueve la marca de tiempo', function (): void {
    $ada = User::factory()->create();
    $notification = seedNotification($ada);
    $action = resolve(NotificationMarkReadAction::class);

    $action->handle($ada, (string) $notification->getKey());
    $first = $notification->refresh()->getAttribute('read_at');

    expect($action->handle($ada, (string) $notification->getKey()))->toBeFalse()
        ->and($notification->refresh()->getAttribute('read_at'))->toEqual($first);
});

/*
|--------------------------------------------------------------------------
| NotificationMarkAllReadAction
|--------------------------------------------------------------------------
*/

it('marca todas las no leídas de una persona y ninguna de otra', function (): void {
    [$ada, $lin] = [User::factory()->create(), User::factory()->create()];

    seedNotification($ada);
    seedNotification($ada);
    seedNotification($lin);

    expect(resolve(NotificationMarkAllReadAction::class)->handle($ada))->toBe(2)
        ->and($ada->unreadNotifications()->count())->toBe(0)
        ->and($lin->unreadNotifications()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| NotificationPreferenceUpdateAction
|--------------------------------------------------------------------------
*/

it('crea la fila la primera vez y la reescribe después', function (): void {
    $ada = User::factory()->create();
    $action = resolve(NotificationPreferenceUpdateAction::class);

    $action->handle($ada, new NotificationPreferenceData(
        category: NotificationCategory::Account->value,
        inApp: true,
        mail: false,
        push: false,
    ));

    $action->handle($ada, new NotificationPreferenceData(
        category: NotificationCategory::Account->value,
        inApp: false,
        mail: false,
        push: true,
    ));

    // `unique(user_id, category)`: una fila, no dos contradictorias.
    expect(NotificationPreference::query()->where('user_id', $ada->getKey())->count())->toBe(1);

    $preference = NotificationPreference::query()->where('user_id', $ada->getKey())->firstOrFail();

    expect($preference->in_app)->toBeFalse()
        ->and($preference->mail)->toBeFalse()
        ->and($preference->push)->toBeTrue();
});

it('vacía la caché de preferencias al guardar', function (): void {
    $ada = User::factory()->create();

    // El provider registra `NotificationPreferences` como `scoped()` — una
    // instancia por petición— y sin ese binding cada `resolve()` devolvería un
    // objeto nuevo, con lo que la caché (y el `forget()`) no significarían nada.
    // Aquí se reproduce ese registro porque la suite corre con el toggle
    // apagado, que es justo cuando el provider no lo hace.
    app()->scoped(NotificationPreferences::class);

    $preferences = resolve(NotificationPreferences::class);

    // Se resuelve una vez para llenar la caché...
    expect($preferences->for((int) $ada->getKey(), NotificationCategory::Account->value)->mail)->toBeTrue();

    resolve(NotificationPreferenceUpdateAction::class)->handle($ada, new NotificationPreferenceData(
        category: NotificationCategory::Account->value,
        inApp: true,
        mail: false,
        push: false,
    ));

    // ...y sin el `forget()` esto seguiría diciendo `true`.
    expect($preferences->for((int) $ada->getKey(), NotificationCategory::Account->value)->mail)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| NotificationPruneAction
|--------------------------------------------------------------------------
*/

it('borra las leídas viejas y deja las no leídas', function (): void {
    $ada = User::factory()->create();

    seedNotification($ada, readAt: CarbonImmutable::now()->subDays(120));
    seedNotification($ada, readAt: CarbonImmutable::now()->subDays(10));
    $sinLeer = seedNotification($ada);

    expect(resolve(NotificationPruneAction::class)->handle(90))->toBe(1)
        ->and($ada->notifications()->count())->toBe(2)
        ->and($ada->notifications()->whereKey($sinLeer->getKey())->exists())->toBeTrue();
});

it('nunca borra una no leída por antigua que sea', function (): void {
    // Si nadie la vio, borrarla es perder el aviso.
    $ada = User::factory()->create();

    $vieja = seedNotification($ada);
    $vieja->forceFill(['created_at' => CarbonImmutable::now()->subYears(3)])->save();

    expect(resolve(NotificationPruneAction::class)->handle(1))->toBe(0)
        ->and($ada->notifications()->count())->toBe(1);
});

it('cuenta lo mismo y no escribe nada en un ensayo', function (): void {
    $ada = User::factory()->create();
    seedNotification($ada, readAt: CarbonImmutable::now()->subDays(120));

    expect(resolve(NotificationPruneAction::class)->handle(90, dryRun: true))->toBe(1)
        ->and($ada->notifications()->count())->toBe(1);
});

it('pone suelo de un día al plazo de retención', function (): void {
    // Un `0` convertiría la poda en «borra todo lo leído hoy».
    $ada = User::factory()->create();
    seedNotification($ada, readAt: CarbonImmutable::now());

    expect(resolve(NotificationPruneAction::class)->handle(0))->toBe(0)
        ->and($ada->notifications()->count())->toBe(1);
});
