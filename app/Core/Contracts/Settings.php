<?php

declare(strict_types=1);

namespace App\Core\Contracts;

/**
 * Ajustes de la instalación: los valores que el cliente cambia sin tocar el
 * `.env` ni pedir un deploy.
 *
 * Es la frontera entre el módulo que los **implementa** (`App\Modules\Platform`,
 * con su tabla `settings`) y todo el que sólo los **lee**: una hoja de PDF que
 * quiere el nombre fiscal, un correo que firma con el teléfono de la oficina,
 * el layout que pinta el nombre de la organización en el sidebar. Ninguno de
 * ellos importa una clase de Platform (R5) ni sabe si el valor salió de la base
 * o del archivo de configuración.
 *
 * ## La cascada, y por qué es de dos escalones y no de tres
 *
 * ```
 * fila en `settings`  →  config('kore-settings.defaults.{clave}')  →  $default
 * ```
 *
 * Una instalación recién clonada **no tiene filas**, y aun así todo funciona:
 * `config/kore-settings.php` responde por todas las claves. La primera vez que
 * alguien guarda desde la pantalla se crea la fila, y a partir de ahí manda
 * ella. Es el patrón de `NotariaConfiguracion::instancia()` de Notarium sin su
 * parte cara: allí el primer acceso **escribía** una fila con los valores del
 * config, así que leer un ajuste en una petición GET podía provocar un INSERT.
 * Aquí leer no escribe nunca.
 *
 * ## Esto no es `config/kore-app.php`
 *
 * Un toggle de `kore-app` dice qué capacidades del boilerplate están
 * compiladas; lo cambia quien despliega, en el `.env`, y exige reiniciar. Un
 * ajuste lo cambia el administrador desde una pantalla y surte efecto en la
 * petición siguiente. Ver `docs/architecture/toggles.md` §«Tres capas».
 *
 * `$changedBy` es el id del actor y llega **por parámetro**: el contrato vive en
 * `Core`, donde `auth()` está prohibido (R19), porque un comando artisan o un
 * seeder tienen que poder guardar un ajuste igual que una pantalla.
 *
 * La implementación se bindea en `PlatformModuleServiceProvider::register()`, y
 * **siempre**: Platform no tiene toggle. Ver `docs/modules/platform.md`.
 */
interface Settings
{
    /**
     * Valor efectivo de una clave: la fila si existe, y si no el valor por
     * defecto de `config('kore-settings.defaults.…')`, y si tampoco `$default`.
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Guarda (o reemplaza) el valor de una clave e invalida la caché.
     *
     * Sólo se admiten claves declaradas en `kore-settings.editable`: la lista
     * de lo que se puede cambiar desde dentro de la aplicación es cerrada, para
     * que un `set()` con la clave mal escrita falle en vez de crear un ajuste
     * fantasma que nadie lee.
     */
    public function set(string $key, mixed $value, int $changedBy): void;

    /**
     * Todos los ajustes con su valor efectivo: los defaults del archivo de
     * configuración con las filas de la base puestas encima.
     *
     * @return array<string, mixed>
     */
    public function all(): array;

    /**
     * Borra la fila de una clave, con lo que su valor vuelve a ser el del
     * archivo de configuración.
     *
     * No es «poner el valor a null»: es devolver la clave a su defecto. La
     * diferencia se ve en `settings:show`, que dice de dónde sale cada valor.
     */
    public function forget(string $key, int $changedBy): void;
}
