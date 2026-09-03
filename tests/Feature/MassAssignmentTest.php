<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;

it('does not unguard models globally', function (): void {
    // Regresión: AppServiceProvider hacía `Model::unguard()`, lo que anulaba
    // la protección de mass assignment de toda la app.
    expect(Model::isUnguarded())->toBeFalse();
});

it('refuses to mass assign an unlisted attribute on User', function (): void {
    expect(fn (): User => new User(['id' => 99, 'name' => 'X']))
        ->toThrow(MassAssignmentException::class);
});

it('keeps the User fillable list minimal', function (): void {
    expect((new User)->getFillable())
        ->toBe(['name', 'email', 'password', 'email_verified_at']);
});
