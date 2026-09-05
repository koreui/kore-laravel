<?php

declare(strict_types=1);

namespace App\Core\Data;

/**
 * La organización dueña de la instalación, tal y como sale de
 * `App\Core\Contracts\Settings`.
 *
 * Es lo que llega al layout, a la cabecera de un PDF, al pie de un correo o a
 * un export: un dato, nunca el modelo `Setting` ni el array crudo de ajustes
 * (R30). Quien lo pinta no sabe si el nombre salió de la tabla `settings` o del
 * `config/kore-settings.php` de una instalación recién clonada.
 *
 * ## Es también el ejemplo del snapshot inmutable
 *
 * Un documento con folio —un recibo, una factura— **copia** estos datos en su
 * propia fila al emitirlo, y no los vuelve a leer nunca. Si mañana la
 * organización cambia de dirección, el recibo del mes pasado sigue diciendo la
 * dirección que tenía el día que se emitió, que es la que el cliente recibió
 * impresa. Ver `App\Core\Concerns\HasIssuedNumber` y
 * `docs/modules/platform.md` §«El snapshot del emisor».
 *
 * Todas las propiedades salvo `name` son nullable: una instalación recién
 * clonada sólo tiene nombre, y una pantalla que reviente por falta del RFC
 * sería peor que una que lo omita.
 */
final class OrganizationData extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $legalName = null,
        public readonly ?string $taxId = null,
        public readonly ?string $address = null,
        public readonly ?string $phone = null,
        public readonly ?string $email = null,
        public readonly ?string $logoPath = null,
    ) {}

    /**
     * Compone el DTO desde el mapa que devuelve
     * `App\Core\Contracts\Settings::all()`.
     *
     * Vive aquí y no en el módulo Platform porque quien la necesita está
     * fuera: el layout, una hoja de PDF, el pie de un correo. Si la traducción
     * de `{clave => valor}` a este DTO viviera en `Platform\Support`, cada
     * consumidor tendría que importar el módulo para pintar el nombre de la
     * organización — que es exactamente lo que R5 prohíbe. Con esto le basta
     * el contrato y este DTO, los dos en `Core`.
     *
     * Sólo depende de un array: sigue siendo un dato, no una fachada (R8).
     *
     * @param array<string, mixed> $settings
     */
    public static function fromSettings(array $settings): self
    {
        $value = static function (string $key) use ($settings): ?string {
            $raw = $settings[$key] ?? null;

            return blank($raw) ? null : (string) $raw;
        };

        return new self(
            name: $value('organization.name') ?? 'Kore',
            legalName: $value('organization.legal_name'),
            taxId: $value('organization.tax_id'),
            address: $value('organization.address'),
            phone: $value('organization.phone'),
            email: $value('organization.email'),
            logoPath: $value('organization.logo_path'),
        );
    }

    /**
     * El nombre con el que se firma un documento: el fiscal si lo hay, y el
     * comercial si no.
     *
     * Los dos existen porque casi nunca coinciden («Kore» frente a «Kore
     * Software, S.A. de C.V.»), y porque el que va en una factura es el
     * segundo.
     */
    public function displayName(): string
    {
        return $this->legalName ?? $this->name;
    }
}
