<?php

declare(strict_types=1);

namespace App\Core\Contracts;

/**
 * Qué módulos incluye **esta instalación**.
 *
 * Es la tercera capa de encendido del boilerplate, y la que más se confunde con
 * las otras dos. La frase que la distingue:
 *
 * | Capa | La frase que dice | Quién la cambia |
 * |------|-------------------|-----------------|
 * | `config/kore-app.php` | «este boilerplate **trae** esta capacidad» | quien despliega, en el `.env` |
 * | `config/features.php` (esto) | «tu **licencia** no incluye esto» | quien vende / instala, en el `.env` |
 * | Laravel Pennant | «**todavía** no te toca» | el producto, por usuario o por porcentaje |
 * | spatie/laravel-permission | «no **tienes permiso**» | el administrador del cliente |
 *
 * Las cuatro dan un «no», y las cuatro son un «no» distinto. Mezclarlas duele
 * en el sitio de siempre: en Notarium, apagar un módulo no licenciado se hacía
 * quitándole el permiso a todos los roles, y entonces nadie sabía si el cliente
 * no veía Escrituras porque no lo había comprado o porque alguien le había
 * tocado los permisos. Son dos preguntas y necesitan dos respuestas.
 *
 * Un feature de esta capa es **estático por instalación**: no depende del
 * usuario, no cambia a mitad del día y no tiene rollout gradual. Si lo que
 * quieres es enseñar algo a un 10 % de los usuarios, eso es Pennant.
 *
 * La implementación (`App\Modules\Platform\Support\ConfigFeatures`) se bindea en
 * `PlatformModuleServiceProvider::register()`. Ver `docs/modules/platform.md`.
 */
interface InstallationFeatures
{
    /**
     * ¿Esta instalación incluye el módulo?
     *
     * Una clave que no existe es `false`: lo que no está licenciado
     * explícitamente, no está licenciado. Se prefiere el falso negativo —una
     * pantalla que hay que encender— al falso positivo, que sería entregar un
     * módulo que nadie pagó.
     */
    public function enabled(string $feature): bool;

    /**
     * Todos los features de la instalación, para que el cliente decida qué
     * pintar sin preguntar uno a uno.
     *
     * @return array<string, bool>
     */
    public function all(): array;
}
