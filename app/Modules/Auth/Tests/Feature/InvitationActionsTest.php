<?php

declare(strict_types=1);

use App\Core\Enums\AccountStatus;
use App\Core\Enums\SystemRole;
use App\Exceptions\ConflictException;
use App\Models\User;
use App\Modules\Auth\Actions\InvitationCreateAction;
use App\Modules\Auth\Actions\InvitationRedeemAction;
use App\Modules\Auth\Actions\InvitationRevokeAction;
use App\Modules\Auth\Data\InvitationData;
use App\Modules\Auth\Data\RegisterData;
use App\Modules\Auth\Database\Seeders\ModulesSeeder;
use App\Modules\Auth\Events\AccountActivated;
use App\Modules\Auth\Models\InvitationCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;

/*
|--------------------------------------------------------------------------
| Las tres Actions de invitaciones
|--------------------------------------------------------------------------
|
| Ninguna depende del toggle: una Action es un caso de uso, y quien decide si la
| aplicación lo ofrece es el provider. Por eso estos tests corren con
| `AUTH_INVITATIONS=false`, que es como corre la suite.
|
*/

beforeEach(function (): void {
    (new ModulesSeeder)->run();
});

it('creates a code with the shape asked for', function (): void {
    $creator = User::factory()->create();

    $invitation = resolve(InvitationCreateAction::class)->handle(
        new InvitationData(
            role: SystemRole::User->value,
            maxUses: 5,
            expiresAt: CarbonImmutable::now()->addWeek()->toIso8601String(),
            note: 'Equipo de soporte',
        ),
        $creator,
    );

    expect($invitation->code)->toHaveLength(InvitationCode::GENERATED_LENGTH)
        ->and($invitation->code)->toBe(mb_strtoupper($invitation->code))
        ->and($invitation->role)->toBe(SystemRole::User->value)
        ->and($invitation->max_uses)->toBe(5)
        ->and($invitation->uses)->toBe(0)
        ->and($invitation->created_by)->toBe($creator->id)
        ->and($invitation->note)->toBe('Equipo de soporte')
        ->and($invitation->isUsable())->toBeTrue();
});

it('revokes a code by bringing its expiry forward', function (): void {
    $invitation = InvitationCode::factory()->create();

    expect($invitation->isUsable())->toBeTrue();

    resolve(InvitationRevokeAction::class)->handle($invitation);

    expect($invitation->fresh()?->isUsable())->toBeFalse()
        ->and($invitation->fresh()?->isExpired())->toBeTrue();
});

it('redeems a code, activates the account and counts the use', function (): void {
    Event::fake([AccountActivated::class]);

    $invitation = InvitationCode::factory()->forRole(SystemRole::Admin)->create(['max_uses' => 2]);

    $user = resolve(InvitationRedeemAction::class)->handle(
        new RegisterData(name: 'Grace Hopper', email: 'grace@example.com', password: 'StrongPassword123!'),
        $invitation->code,
    );

    expect($user->accountStatus())->toBe(AccountStatus::Active)
        ->and($user->activated_at)->not->toBeNull()
        ->and($user->hasRole(SystemRole::Admin->value))->toBeTrue()
        ->and(Hash::check('StrongPassword123!', (string) $user->password))->toBeTrue()
        ->and($invitation->fresh()?->uses)->toBe(1);

    Event::assertDispatched(AccountActivated::class, fn (AccountActivated $e): bool => $e->user->is($user));
});

it('refuses to redeem an expired code and creates nothing', function (): void {
    $invitation = InvitationCode::factory()->expired()->create();

    expect(fn (): User => resolve(InvitationRedeemAction::class)->handle(
        new RegisterData(name: 'Grace', email: 'grace@example.com', password: 'StrongPassword123!'),
        $invitation->code,
    ))->toThrow(ConflictException::class);

    expect(User::where('email', 'grace@example.com')->exists())->toBeFalse();
});

it('refuses to redeem an unknown code', function (): void {
    expect(fn (): User => resolve(InvitationRedeemAction::class)->handle(
        new RegisterData(name: 'Grace', email: 'grace@example.com', password: 'StrongPassword123!'),
        'NOEXISTE',
    ))->toThrow(ConflictException::class);
});

it('normalises the code both when looking it up and when generating it', function (): void {
    expect(InvitationCode::normalize('  kore 2026 '))->toBe('KORE2026')
        ->and(InvitationCode::normalize('   '))->toBe('');

    $invitation = InvitationCode::factory()->create(['code' => 'KORE2026']);

    expect(InvitationCode::findByCode('kore 2026')?->is($invitation))->toBeTrue()
        ->and(InvitationCode::findByCode(''))->toBeNull();
});
