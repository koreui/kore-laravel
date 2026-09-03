<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Líneas de idioma de restablecimiento de contraseña
    |--------------------------------------------------------------------------
    |
    | Cada clave corresponde a un estado que devuelve el password broker
    | (`Password::sendResetLink()` / `Password::reset()`). Fortify las expone
    | en `session('status')` y en los errores del formulario.
    |
    */

    'reset' => 'Tu contraseña ha sido restablecida.',
    'sent' => 'Te hemos enviado por correo el enlace para restablecer tu contraseña.',
    'throttled' => 'Espera un momento antes de volver a intentarlo.',
    'token' => 'Este token de restablecimiento de contraseña no es válido.',
    'user' => 'No encontramos ningún usuario con ese correo electrónico.',

];
