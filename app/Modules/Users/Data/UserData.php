<?php

declare(strict_types=1);

namespace App\Modules\Users\Data;

use App\Core\Data\Data;

/**
 * Entrada de las Actions del módulo Users.
 *
 * Es el contrato entre la capa de entrega (el Form Object de Livewire, un
 * comando artisan, un job) y los casos de uso: sin arrays asociativos y sin
 * lógica. La validación vive en `UserForm::rules()`; aquí sólo viajan datos
 * ya validados.
 *
 * `password` es nullable a propósito: al editar, `null` significa «no la
 * cambies».
 */
final class UserData extends Data
{
    /**
     * @param array<int, string> $permissions
     */
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly ?string $password,
        public readonly string $role,
        public readonly array $permissions,
    ) {}
}
