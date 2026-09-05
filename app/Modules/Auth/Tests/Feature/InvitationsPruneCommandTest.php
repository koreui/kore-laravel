<?php

declare(strict_types=1);

use App\Modules\Auth\Models\InvitationCode;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| invitations:prune
|--------------------------------------------------------------------------
|
| El comando sólo se registra con el toggle encendido, así que cada caso arranca
| la aplicación con `withEnvironment()` — igual que `FilesCleanupCommandTest`.
| Que el registro dependa del toggle lo prueba `InvitationsToggleTest`; aquí se
| comprueba **qué** borra.
|
*/

it('deletes only the codes expired long ago', function (): void {
    $viejo = InvitationCode::factory()->create(['expires_at' => CarbonImmutable::now()->subDays(200)]);
    $reciente = InvitationCode::factory()->create(['expires_at' => CarbonImmutable::now()->subDays(10)]);
    $vivo = InvitationCode::factory()->create();
    $agotadoSinCaducidad = InvitationCode::factory()->exhausted()->create();

    withEnvironment(['AUTH_INVITATIONS' => 'true'], function (): void {
        $this->artisan('invitations:prune', ['--days' => 90])->assertSuccessful();
    });

    expect(InvitationCode::find($viejo->id))->toBeNull()
        ->and(InvitationCode::find($reciente->id))->not->toBeNull()
        ->and(InvitationCode::find($vivo->id))->not->toBeNull()
        // Agotado no es lo mismo que cerrado: subirle el cupo lo reabre, y la
        // fila es el rastro de cuánta gente entró por él.
        ->and(InvitationCode::find($agotadoSinCaducidad->id))->not->toBeNull();
});

it('deletes nothing with --dry-run', function (): void {
    $viejo = InvitationCode::factory()->create(['expires_at' => CarbonImmutable::now()->subDays(200)]);

    withEnvironment(['AUTH_INVITATIONS' => 'true'], function (): void {
        $this->artisan('invitations:prune', ['--days' => 90, '--dry-run' => true])
            ->expectsOutputToContain('Simulacro')
            ->assertSuccessful();
    });

    expect(InvitationCode::find($viejo->id))->not->toBeNull();
});
