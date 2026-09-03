<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| El 419 en el escritorio no es una pantalla de error
|--------------------------------------------------------------------------
|
| El handler vive en `bootstrap/app.php`. Un 419 casi nunca es un ataque: es la
| sesión que caducó con la pestaña abierta, o el mismo formulario enviado dos
| veces. En vez de la pantalla «Page Expired» —que no dice qué hacer— se
| devuelve al usuario a un sitio conocido.
|
| Los tests lanzan `TokenMismatchException` a propósito, y no un
| `HttpException(419)` ya construido: el handler tipa sobre `HttpException`
| porque Laravel hace esa conversión en `prepareException()`, **antes** de
| evaluar los callbacks de render. Lanzar la excepción original es lo único que
| comprueba que esa cadena sigue entera.
|
*/

beforeEach(function (): void {
    Route::middleware('web')->post('/_prueba-419', function (): never {
        throw new TokenMismatchException('CSRF token mismatch.');
    });
});

it('manda al login con aviso cuando no hay sesión iniciada', function (): void {
    $this->post('/_prueba-419')
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => __('Tu sesión expiró. Vuelve a intentarlo.')]);
});

it('manda al dashboard sin aviso cuando la sesión sigue viva', function (): void {
    $this->actingAs(User::factory()->create())
        ->post('/_prueba-419')
        ->assertRedirect(route('dashboard'))
        ->assertSessionHasNoErrors();
});

it('no redirige una petición que espera JSON', function (): void {
    $this->postJson('/_prueba-419')->assertStatus(419);
});

/*
 * Livewire recarga la página por su cuenta ante un 419; redirigirlo dejaría al
 * componente pidiendo una vista dentro de una respuesta que ya no es suya.
 */
it('no redirige una petición de Livewire', function (): void {
    $this->actingAs(User::factory()->create())
        ->post('/_prueba-419', [], ['X-Livewire' => 'true'])
        ->assertStatus(419);
});

/*
 * La API responde con su propio contrato: un cliente móvil que recibe un 302 a
 * `/login` se encuentra HTML donde esperaba JSON.
 */
it('no redirige una ruta de api', function (): void {
    Route::middleware('web')->post('/api/_prueba-419', function (): never {
        throw new TokenMismatchException('CSRF token mismatch.');
    });

    $this->post('/api/_prueba-419')->assertStatus(419);
});

it('no toca otros errores HTTP', function (): void {
    Route::middleware('web')->post('/_prueba-404', function (): never {
        abort(404);
    });

    $this->post('/_prueba-404')->assertStatus(404);
});
