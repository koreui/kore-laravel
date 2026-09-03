<?php

declare(strict_types=1);

use App\Core\Concerns\HandlesDeleteConfirmation;
use Livewire\Component;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| App\Core\Concerns\HandlesDeleteConfirmation
|--------------------------------------------------------------------------
|
| El trait aporta el par «confirmar → borrar» y el candado del id; el
| componente aporta el `deleteAuthorized()` con su `authorize()`. Aquí se
| prueba la mitad del trait con un componente de laboratorio: que el id llegue
| entero al hook, que el candado de R24 aguante y que el workaround de koreUi
| (`hydrate()` reponiendo `$koreConfirmable`) siga siendo lo que hace que una
| row action con `confirm()` llegue a ejecutarse.
|
| La otra mitad —que el hook autorice de verdad— la prueban los tests del
| módulo Users (`UsersAuthorizationTest`).
|
*/

/**
 * Componente de laboratorio: guarda lo que le mandan borrar.
 */
final class DeleteConfirmationHarness extends Component
{
    use HandlesDeleteConfirmation;

    /** @var array<int, int> */
    public array $deleted = [];

    public bool $shouldFail = false;

    /** Deja un id pendiente desde el servidor, como haría un modal propio. */
    public function stage(int $id): void
    {
        $this->pendingDeleteId = $id;
    }

    public function deleteAuthorized(int $id): void
    {
        if ($this->shouldFail) {
            throw new RuntimeException('el borrado falló');
        }

        $this->deleted[] = $id;
    }

    public function render(): string
    {
        return '<div></div>';
    }
}

it('lleva el id de confirmDelete hasta el hook y suelta el pendiente', function (): void {
    Livewire::test(DeleteConfirmationHarness::class)
        ->call('confirmDelete', 7)
        ->assertSet('deleted', [7])
        ->assertSet('pendingDeleteId', null);
});

it('no borra nada cuando deleteConfirmed llega sin id pendiente', function (): void {
    Livewire::test(DeleteConfirmationHarness::class)
        ->call('deleteConfirmed')
        ->assertSet('deleted', []);
});

it('borra el id que quedó pendiente de una confirmación anterior', function (): void {
    // El componente guarda el id (un modal propio, un botón fuera de la tabla)
    // y confirma en una segunda petición.
    Livewire::test(DeleteConfirmationHarness::class)
        ->call('stage', 12)
        ->assertSet('pendingDeleteId', 12)
        ->call('deleteConfirmed')
        ->assertSet('deleted', [12])
        ->assertSet('pendingDeleteId', null);
});

it('suelta el id pendiente aunque el borrado reviente', function (): void {
    $component = Livewire::test(DeleteConfirmationHarness::class)->set('shouldFail', true);

    expect(fn (): mixed => $component->call('confirmDelete', 3))
        ->toThrow(RuntimeException::class);

    expect($component->instance()->pendingDeleteId)->toBeNull();
});

/*
 * R24 · el id es `#[Locked]`. Sin el candado, el payload de /livewire/update
 * decidiría sobre qué fila opera `deleteConfirmed()`.
 */
it('R24 · el cliente no puede reescribir el id pendiente', function (): void {
    expect(fn (): mixed => Livewire::test(DeleteConfirmationHarness::class)->set('pendingDeleteId', 99))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

/*
 * El workaround de koreUi: `handleConfirmCallback()` sólo ejecuta métodos que
 * estén en `$koreConfirmable`, y `Confirm::send()` —el único que rellena esa
 * lista— no corre para las row actions. El `hydrate()` del trait la repone en
 * cada petición; sin él, aceptar el diálogo no hace absolutamente nada, que es
 * exactamente la cicatriz de R36.
 */
it('repone confirmDelete en la lista que koreUi consume al confirmar', function (): void {
    $component = Livewire::test(DeleteConfirmationHarness::class);

    $component->call('handleConfirmCallback', 'confirmDelete', [5], $component->id())
        ->assertSet('deleted', [5]);
});

it('koreUi sigue rechazando un método que nadie autorizó', function (): void {
    $component = Livewire::test(DeleteConfirmationHarness::class);

    $component->call('handleConfirmCallback', 'deleteAuthorized', [5], $component->id())
        ->assertSet('deleted', []);
});
