<?php

declare(strict_types=1);

namespace App\Modules\Auth\Events;

use App\Models\User;

/**
 * Se acaba de emitir un token de API (Sanctum) para un usuario.
 *
 * Es la frontera pública de Auth hacia quien quiera reaccionar a un login por
 * API sin importar nada más del módulo (R5): el módulo `Devices` lo escucha
 * para registrar el dispositivo que pidió el token. Los datos del dispositivo
 * son opcionales porque un cliente de API no tiene por qué ser un móvil.
 */
final readonly class ApiTokenIssued
{
    public function __construct(
        public User $user,
        public int $tokenId,
        public string $tokenName,
        public ?string $deviceId = null,
        public ?string $platform = null,
        public ?string $appVersion = null,
    ) {}
}
