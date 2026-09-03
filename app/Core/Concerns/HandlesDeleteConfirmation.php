<?php

declare(strict_types=1);

namespace App\Core\Concerns;

use KoreUi\Core\Concerns\InteractsWithFeedback;
use Livewire\Attributes\Locked;

/**
 * El par «confirmar → borrar» de una tabla, con el id bajo llave.
 *
 * Es el bloque que se repetía tal cual en cada tabla de un proyecto derivado
 * (en Notarium, un `deleteConfirmed(int $id)` con el mismo cuerpo en once
 * componentes): recibir un id del cliente, autorizar, borrar y avisar. Lo único
 * que cambia de una tabla a otra es qué modelo se borra y con qué Action, y eso
 * es lo que se queda en el componente.
 *
 * Reparto:
 *
 *   - `confirmDelete(int $id)` es el punto de entrada de koreUi: el
 *     `RowAction::confirm()` lo invoca **después** de que el usuario acepte el
 *     diálogo, con el id de la fila.
 *   - `deleteConfirmed()` es la segunda puerta, para una UI que guarda el id
 *     antes y confirma después (un modal propio, un botón fuera de la tabla).
 *   - `deleteAuthorized(int $id)` lo escribe el componente: `authorize()` +
 *     Action + toast.
 *
 * Arrastra `InteractsWithFeedback` de koreUi porque el workaround de
 * `hydrate()` toca su `$koreConfirmable`; usarlo además en el componente —como
 * hace `TableUsers`— no molesta: PHP aplana el trait una sola vez.
 *
 * **R23 · por qué el hook es público.** `kore:arch:check` sólo mira los
 * `public function` de los componentes Livewire de un módulo
 * (`app/Modules/{X}/Http/Livewire`); este trait vive en Core y queda fuera de
 * ese barrido. Si el hook fuera `protected`, mover un
 * componente a este trait le quitaría en silencio su cobertura de R23. Siendo
 * `public` y empezando por `delete`, el check lo sigue viendo —y sigue
 * exigiendo el `authorize()`— en el archivo del módulo, que es donde tiene que
 * estar.
 *
 * **R24** · `$pendingDeleteId` es `#[Locked]`: Livewire rehidrata las
 * propiedades públicas desde el payload del cliente, así que sin el candado
 * sería el navegador quien eligiera sobre qué registro opera
 * `deleteConfirmed()`.
 */
trait HandlesDeleteConfirmation
{
    use InteractsWithFeedback;

    /**
     * Id de la fila pendiente de borrar, entre la confirmación y el borrado.
     */
    #[Locked]
    public ?int $pendingDeleteId = null;

    /**
     * Workaround koreUi 2.3: `RowAction::confirm()` arma el diálogo en el
     * cliente y, al aceptar, `InteractsWithFeedback::handleConfirmCallback()`
     * sólo ejecuta métodos presentes en `$koreConfirmable`, lista que
     * únicamente rellena `Confirm::send()` —camino que las row actions no
     * recorren, a diferencia de las bulk actions—. Sin esto `confirmDelete()`
     * no se invoca nunca.
     *
     * `hydrate()` corre tras restaurar las propiedades del snapshot y justo
     * antes de despachar el listener, así que es el único punto donde llega a
     * tiempo. Y hay que reponer la entrada en cada petición porque
     * `handleConfirmCallback()` la consume al usarla.
     *
     * Quitar cuando koreUi autorice las row actions por sí mismo.
     */
    public function hydrate(): void
    {
        if (! in_array('confirmDelete', $this->koreConfirmable, true)) {
            $this->koreConfirmable[] = 'confirmDelete';
        }
    }

    /**
     * Punto de entrada del `RowAction`: guarda el id confirmado y borra.
     */
    public function confirmDelete(int $id): void
    {
        $this->pendingDeleteId = $id;

        $this->deleteConfirmed();
    }

    /**
     * Borra el id pendiente y lo suelta, haya ido bien o mal.
     *
     * El id se limpia **antes** de delegar: si `deleteAuthorized()` lanza —una
     * `AuthorizationException`, un `abort()`— la fila no se queda armada
     * esperando al siguiente clic.
     */
    public function deleteConfirmed(): void
    {
        $id = $this->pendingDeleteId;

        $this->pendingDeleteId = null;

        if ($id === null) {
            return;
        }

        $this->deleteAuthorized($id);
    }

    /**
     * Lo único que cambia entre una tabla y otra: autorizar y borrar.
     *
     * El componente hace aquí `authorize()` → Action → toast. Público a
     * propósito (ver el docblock del trait): es lo que mantiene el
     * `authorize()` bajo el radar de R23.
     */
    abstract public function deleteAuthorized(int $id): void;
}
