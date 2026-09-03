<?php

declare(strict_types=1);

namespace App\Core\Concerns;

use Illuminate\Http\RedirectResponse;
use KoreUi\Feedback\Toast;

/**
 * «Guardado correctamente» + volver al listado, en una línea.
 *
 * Es el final de todo `save()` de un formulario Livewire y se escribía entero
 * cada vez —cuatro líneas encadenadas donde lo único que cambia es la ruta y el
 * texto—, con una trampa que sólo se ve cuando falla: sin `viaSession()` el
 * toast se despacha por el navegador y la redirección inmediata se lo lleva por
 * delante, así que el usuario aterriza en el listado sin ninguna señal de que
 * su cambio se guardó.
 *
 * Requiere `KoreUi\Core\Concerns\InteractsWithFeedback` en el componente: el
 * `abstract toast()` de abajo es la forma de exigirlo sin que PHPStan tenga que
 * adivinarlo (PHP deja que un método concreto de otro trait satisfaga a un
 * abstracto de éste).
 */
trait RedirectsWithToast
{
    /**
     * Deja un toast de éxito en sesión y redirige a una ruta con nombre.
     *
     * @param array<string, mixed> $params parámetros de la ruta
     */
    protected function redirectWithToast(string $route, string $title, string $message, array $params = []): RedirectResponse
    {
        $this->toast()
            ->success($title, $message)
            ->viaSession()
            ->send();

        return to_route($route, $params);
    }

    /**
     * La aporta `InteractsWithFeedback` de koreUi.
     */
    abstract public function toast(): Toast;
}
