<?php

declare(strict_types=1);

namespace App\Modules\Auth\Fortify;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Reglas de contraseña compartidas por los adaptadores de Fortify de esta
 * carpeta. Vive aquí (y no en `Core/Concerns/`) porque sólo tiene sentido
 * para los stubs que publica el paquete.
 */
trait PasswordValidationRules
{
    /**
     * @return array<int, Rule|array<mixed>|string>
     */
    protected function passwordRules(): array
    {
        return ['required', 'string', Password::default(), 'confirmed'];
    }
}
