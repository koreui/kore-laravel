<?php

declare(strict_types=1);

use App\Core\Concerns\RedirectsWithToast;
use KoreUi\Core\Concerns\InteractsWithFeedback;
use Livewire\Component;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| App\Core\Concerns\RedirectsWithToast
|--------------------------------------------------------------------------
|
| El final de todo `save()`: avisar y volver al listado. Lo que aquí se
| verifica no es la redirección —eso ya lo hacía `to_route()`— sino la parte
| que se olvida y no falla: sin `viaSession()` el toast se despacha por el
| navegador y la redirección se lo lleva por delante, así que el usuario
| aterriza en el listado sin señal de que su cambio se guardó.
|
*/

/**
 * Componente de laboratorio: guarda y vuelve.
 */
final class RedirectsWithToastHarness extends Component
{
    use InteractsWithFeedback;
    use RedirectsWithToast;

    public function save(): mixed
    {
        return $this->redirectWithToast('login', 'Listo', 'Guardado.');
    }

    public function saveWithParams(): mixed
    {
        return $this->redirectWithToast('users.edit', 'Listo', 'Guardado.', ['user' => 7]);
    }

    public function render(): string
    {
        return '<div></div>';
    }
}

it('redirige a la ruta con nombre', function (): void {
    Livewire::test(RedirectsWithToastHarness::class)
        ->call('save')
        ->assertRedirect(route('login'));
});

it('pasa los parámetros de la ruta', function (): void {
    Livewire::test(RedirectsWithToastHarness::class)
        ->call('saveWithParams')
        ->assertRedirect(route('users.edit', ['user' => 7]));
});

/*
 * El toast viaja en la sesión (`viaSession()`), no como evento del navegador:
 * es lo único que sobrevive a la redirección.
 */
it('deja el toast en sesión para que sobreviva a la redirección', function (): void {
    Livewire::test(RedirectsWithToastHarness::class)->call('save');

    expect(session('kore:toast'))
        ->toBeArray()
        ->toMatchArray([
            'type' => 'success',
            'title' => 'Listo',
            'description' => 'Guardado.',
        ]);
});

it('no despacha el toast como evento del navegador', function (): void {
    Livewire::test(RedirectsWithToastHarness::class)
        ->call('save')
        ->assertNotDispatched('kore:toast');
});
