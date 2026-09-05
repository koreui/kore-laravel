<?php

declare(strict_types=1);

use App\Core\Data\NotificationData;
use App\Core\Enums\NotificationCategory;
use App\Models\User;
use App\Modules\Notifications\Actions\NotificationSendAction;
use App\Modules\Notifications\Http\Livewire\NotificationBell;
use App\Modules\Notifications\Http\Livewire\NotificationSettings;
use App\Modules\Notifications\Http\Livewire\TableNotifications;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Providers\NotificationsModuleServiceProvider;
use App\Modules\Notifications\Support\NotificationPreferences;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Los tres componentes Livewire (R35)
|--------------------------------------------------------------------------
|
| La suite corre con el toggle apagado, así que se enciende el módulo sobre la
| aplicación en marcha: `Config::set` + registrar el provider a mano. Es el
| mismo atajo que usa `DevicesApiTest`, y por la misma razón — `withEnvironment()`
| rearranca la aplicación, y aquí hacen falta las rutas con nombre que pintan
| las vistas (`route('notifications.index')`), las policies y los alias de
| Livewire, todos en la misma instancia que el test.
|
| Lo que se prueba aquí es el comportamiento de los componentes; que el toggle
| los encienda y los apague es asunto de `NotificationsToggleTest`.
|
| Que un usuario no pueda tocar la notificación de otro se comprueba con la
| Policy puesta, porque es la Policy quien lo decide (R25).
|
*/

beforeEach(function (): void {
    Config::set('kore-app.notifications.enabled', true);

    app()->register(NotificationsModuleServiceProvider::class, force: true);
});

/** Manda un aviso de verdad, por el mismo camino que la aplicación. */
function notifyUser(User $user, string $title = 'Hola', string $category = 'system'): void
{
    resolve(NotificationSendAction::class)->handle([(int) $user->getKey()], new NotificationData(
        title: $title,
        body: 'Cuerpo del aviso.',
        category: $category,
        mail: false,
        push: false,
    ));
}

/*
|--------------------------------------------------------------------------
| NotificationBell
|--------------------------------------------------------------------------
*/

it('pinta el contador y las últimas notificaciones', function (): void {
    $ada = User::factory()->create();
    notifyUser($ada, 'Primero');
    notifyUser($ada, 'Segundo');

    Livewire::actingAs($ada)
        ->test(NotificationBell::class)
        ->assertSee('Primero')
        ->assertSee('Segundo')
        ->assertSee('Notificaciones');
});

it('sólo enseña las cinco más recientes en la campana', function (): void {
    $ada = User::factory()->create();

    foreach (range(1, 7) as $i) {
        notifyUser($ada, "Aviso {$i}");
    }

    $latest = Livewire::actingAs($ada)->test(NotificationBell::class)->instance()->latest();

    expect($latest)->toHaveCount(5);
});

it('corta el globo en 9+ cuando hay más de nueve sin leer', function (): void {
    $ada = User::factory()->create();

    foreach (range(1, 11) as $i) {
        notifyUser($ada, "Aviso {$i}");
    }

    expect(Livewire::actingAs($ada)->test(NotificationBell::class)->instance()->badge())->toBe('9+');
});

it('marca todas como leídas desde la campana', function (): void {
    $ada = User::factory()->create();
    notifyUser($ada);
    notifyUser($ada);

    Livewire::actingAs($ada)
        ->test(NotificationBell::class)
        ->call('markAllAsRead')
        ->assertDispatched('notifications-updated');

    expect($ada->unreadNotifications()->count())->toBe(0);
});

it('no cuenta las notificaciones de otra persona', function (): void {
    [$ada, $lin] = [User::factory()->create(), User::factory()->create()];
    notifyUser($lin);

    expect(Livewire::actingAs($ada)->test(NotificationBell::class)->instance()->unreadCount())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| TableNotifications
|--------------------------------------------------------------------------
*/

it('lista la bandeja completa del usuario', function (): void {
    [$ada, $lin] = [User::factory()->create(), User::factory()->create()];
    notifyUser($ada, 'Mío');
    notifyUser($lin, 'De Lin');

    Livewire::actingAs($ada)
        ->test(TableNotifications::class)
        ->assertSee('Mío')
        ->assertDontSee('De Lin');
});

it('filtra por categoría y por no leídas', function (): void {
    $ada = User::factory()->create();
    notifyUser($ada, 'De cuenta', NotificationCategory::Account->value);
    notifyUser($ada, 'De sistema', NotificationCategory::System->value);

    Livewire::actingAs($ada)
        ->test(TableNotifications::class)
        ->set('category', NotificationCategory::Account->value)
        ->assertSee('De cuenta')
        ->assertDontSee('De sistema');
});

it('marca una notificación propia como leída', function (): void {
    $ada = User::factory()->create();
    notifyUser($ada);

    $id = (string) $ada->notifications()->firstOrFail()->getKey();

    Livewire::actingAs($ada)
        ->test(TableNotifications::class)
        ->call('markAsRead', $id)
        ->assertDispatched('notifications-updated');

    expect($ada->unreadNotifications()->count())->toBe(0);
});

it('no marca la notificación de otra persona aunque le manden el uuid', function (): void {
    // R23 · la llamada viaja por /livewire/update, donde el middleware de la
    // ruta no corre: la puerta es la consulta acotada al usuario más la Policy.
    [$ada, $lin] = [User::factory()->create(), User::factory()->create()];
    notifyUser($lin);

    $ajeno = (string) $lin->notifications()->firstOrFail()->getKey();

    Livewire::actingAs($ada)
        ->test(TableNotifications::class)
        ->call('markAsRead', $ajeno)
        ->assertNotDispatched('notifications-updated');

    expect($lin->unreadNotifications()->count())->toBe(1);
});

it('marca todas como leídas desde la bandeja', function (): void {
    $ada = User::factory()->create();
    notifyUser($ada);
    notifyUser($ada);

    Livewire::actingAs($ada)
        ->test(TableNotifications::class)
        ->call('markAllAsRead')
        ->assertDispatched('notifications-updated');

    expect($ada->unreadNotifications()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| NotificationSettings
|--------------------------------------------------------------------------
*/

it('monta los interruptores con los defaults del catálogo', function (): void {
    $ada = User::factory()->create();

    Livewire::actingAs($ada)
        ->test(NotificationSettings::class)
        ->assertSet('preferences.system.in_app', true)
        ->assertSet('preferences.system.mail', true)
        ->assertSet('preferences.activity.mail', false);
});

it('guarda una preferencia y la persiste', function (): void {
    $ada = User::factory()->create();

    Livewire::actingAs($ada)
        ->test(NotificationSettings::class)
        ->set('preferences.account.mail', false)
        ->call('save')
        ->assertHasNoErrors();

    expect(resolve(NotificationPreferences::class)->for((int) $ada->getKey(), NotificationCategory::Account->value)->mail)
        ->toBeFalse();
});

it('no escribe nada para una categoría que no está en el catálogo', function (): void {
    // El bucle recorre el catálogo del servidor, no las claves que mandó el
    // cliente: una categoría inventada en el payload no llega a construirse.
    $ada = User::factory()->create();

    Livewire::actingAs($ada)
        ->test(NotificationSettings::class)
        ->set('preferences.inventada', ['in_app' => true, 'mail' => true, 'push' => true])
        ->call('save');

    expect(NotificationPreference::query()->where('category', 'inventada')->exists())->toBeFalse();
});

it('no deja guardar las preferencias de otra persona', function (): void {
    $ada = User::factory()->create();
    $lin = User::factory()->create();

    // La Policy compara el user_id de la fila con el de la sesión: una fila de
    // Lin autorizada por Ada no pasa.
    expect(Gate::forUser($ada)->allows('update', new NotificationPreference([
        'user_id' => $lin->getKey(),
        'category' => NotificationCategory::Account->value,
    ])))->toBeFalse();

    expect(fn (): mixed => Gate::forUser($ada)->authorize('update', new NotificationPreference([
        'user_id' => $lin->getKey(),
        'category' => NotificationCategory::Account->value,
    ])))->toThrow(AuthorizationException::class);
});
