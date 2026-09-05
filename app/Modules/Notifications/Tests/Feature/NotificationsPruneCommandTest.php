<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Notifications\Console\Commands\NotificationsPruneCommand;
use App\Modules\Notifications\Support\GenericNotification;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| notifications:prune
|--------------------------------------------------------------------------
|
| El comando sólo está registrado con el toggle encendido (lo comprueba
| `NotificationsToggleTest`), así que aquí se ejercita la clase directamente
| con `Artisan::registerCommand()`: lo que se prueba es qué borra y qué dice, no
| que exista.
|
*/

beforeEach(function (): void {
    Artisan::registerCommand(resolve(NotificationsPruneCommand::class));
});

/** Una notificación en la bandeja de alguien, leída o no. */
function seedPrunableNotification(User $user, ?CarbonImmutable $readAt = null): void
{
    $notification = new DatabaseNotification;

    $notification->forceFill([
        'id' => (string) Str::uuid(),
        'type' => GenericNotification::class,
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->getKey(),
        'data' => ['category' => 'system', 'title' => 'Hola', 'body' => 'Qué tal', 'url' => null, 'data' => []],
        'read_at' => $readAt,
    ])->save();
}

it('borra las leídas más viejas que el plazo del config', function (): void {
    Config::set('kore-notifications.prune_days', 90);

    $ada = User::factory()->create();
    seedPrunableNotification($ada, readAt: CarbonImmutable::now()->subDays(120));
    seedPrunableNotification($ada, readAt: CarbonImmutable::now()->subDays(30));

    $this->artisan('notifications:prune')
        ->expectsOutputToContain('1 notificación(es) leídas borradas')
        ->assertSuccessful();

    expect($ada->notifications()->count())->toBe(1);
});

it('acepta un plazo por opción que pisa el config', function (): void {
    Config::set('kore-notifications.prune_days', 365);

    $ada = User::factory()->create();
    seedPrunableNotification($ada, readAt: CarbonImmutable::now()->subDays(40));

    $this->artisan('notifications:prune --days=30')->assertSuccessful();

    expect($ada->notifications()->count())->toBe(0);
});

it('cuenta y no borra en un ensayo', function (): void {
    $ada = User::factory()->create();
    seedPrunableNotification($ada, readAt: CarbonImmutable::now()->subDays(120));

    $this->artisan('notifications:prune --dry-run')
        ->expectsOutputToContain('Simulacro (--dry-run)')
        ->assertSuccessful();

    expect($ada->notifications()->count())->toBe(1);
});

it('nunca se lleva una no leída', function (): void {
    $ada = User::factory()->create();
    seedPrunableNotification($ada);

    $this->artisan('notifications:prune --days=1')->assertSuccessful();

    expect($ada->notifications()->count())->toBe(1);
});
