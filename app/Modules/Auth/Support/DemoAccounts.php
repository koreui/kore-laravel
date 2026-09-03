<?php

declare(strict_types=1);

namespace App\Modules\Auth\Support;

use Illuminate\Support\Str;

/**
 * Qué cuenta es «de demostración» y cuál no.
 *
 * La respuesta no es una lista de correos —cada proyecto derivado siembra los
 * suyos— sino una propiedad del dominio del correo: si es un dominio
 * **reservado**, no puede pertenecer a una persona real.
 *
 *   - RFC 2606 §2 y RFC 6761 reservan los TLD `.test`, `.example`, `.invalid`
 *     y `.localhost`; RFC 6762 añade `.local` para redes locales.
 *   - RFC 2606 §3 reserva `example.com`, `example.net` y `example.org`.
 *
 * Los seeders del boilerplate caen dentro por construcción:
 * `admin@example.com` (`DatabaseSeeder`) y `superadmin@e2e.test` y compañía
 * (`E2eSeeder`).
 *
 * Lo usan {@see \App\Modules\Auth\Actions\AuthDevImpersonateUserAction} para
 * decidir y el switcher de cuentas para listar, y por eso vive aquí: si la
 * lista estuviera en los dos sitios, el día que alguien añadiera un dominio se
 * quedaría a medias —el switcher enseñaría una cuenta en la que la Action no
 * deja entrar—.
 */
final class DemoAccounts
{
    /**
     * Dominios completos reservados (RFC 2606 §3).
     *
     * @var list<string>
     */
    private const array RESERVED_DOMAINS = ['example.com', 'example.net', 'example.org'];

    /**
     * TLDs reservados (RFC 2606 §2, RFC 6761, RFC 6762).
     *
     * @var list<string>
     */
    private const array RESERVED_TLDS = ['test', 'example', 'invalid', 'localhost', 'local'];

    /** ¿El correo pertenece a un dominio reservado? */
    public static function includes(string $email): bool
    {
        if (! str_contains($email, '@')) {
            return false;
        }

        $domain = Str::lower(Str::afterLast($email, '@'));

        if ($domain === '') {
            return false;
        }

        if (in_array($domain, self::RESERVED_DOMAINS, true)) {
            return true;
        }

        return in_array(Str::afterLast($domain, '.'), self::RESERVED_TLDS, true);
    }

    /**
     * Los mismos dominios, en forma de patrones `LIKE` para una consulta.
     *
     * Se hace con `LIKE` y no con una expresión regular porque SQLite —la base
     * de la suite y de muchos `local`— no trae `REGEXP` de serie.
     *
     * @return list<string>
     */
    public static function likePatterns(): array
    {
        $patterns = array_map(
            static fn (string $domain): string => '%@'.$domain,
            self::RESERVED_DOMAINS,
        );

        foreach (self::RESERVED_TLDS as $tld) {
            $patterns[] = '%@%.'.$tld;
            $patterns[] = '%@'.$tld;
        }

        return array_values($patterns);
    }

    /** Los dominios reservados, para explicárselos a quien lee un error. */
    public static function description(): string
    {
        return implode(', ', [
            ...array_map(static fn (string $tld): string => '.'.$tld, self::RESERVED_TLDS),
            ...self::RESERVED_DOMAINS,
        ]);
    }
}
