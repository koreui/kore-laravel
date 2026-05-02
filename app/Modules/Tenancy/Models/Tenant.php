<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as StanclTenant;

final class Tenant extends StanclTenant implements TenantWithDatabase
{
    use HasDatabase;
    use HasDomains;

    /**
     * Atributos custom que el tenant guarda en la columna data.
     *
     * @return array<int, string>
     */
    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
            'plan',
            'created_at',
            'updated_at',
        ];
    }
}
