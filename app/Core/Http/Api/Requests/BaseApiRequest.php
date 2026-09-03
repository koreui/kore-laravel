<?php

declare(strict_types=1);

namespace App\Core\Http\Api\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * Base de todo `FormRequest` de la API (R54).
 *
 * Hace dos cosas, y sólo dos:
 *
 * 1. **`authorize()` devuelve `true`.** La autorización de la API vive en el
 *    controller, contra la Policy (R25): un `FormRequest` que autoriza decide
 *    antes de haber validado y sin ver el modelo cargado, así que la mitad de
 *    las veces acaba repitiendo la regla en dos sitios.
 * 2. **`failedValidation()` lanza una `ValidationException` sin respuesta
 *    prefabricada.** El `FormRequest` de Laravel construye ahí un *redirect*
 *    `back()->withErrors()` cuando el cliente no pidió JSON; en la API eso es
 *    un 302 opaco delante de un error de formulario. Sin esa respuesta, la
 *    excepción llega limpia a `ApiExceptionRenderer`, que la convierte en el
 *    422 del contrato con sus `details` por campo.
 *
 * Un request concreto sólo escribe `rules()` (y `messages()`/`attributes()` si
 * hace falta):
 *
 * ```php
 * final class DeviceStoreRequest extends BaseApiRequest
 * {
 *     public function rules(): array
 *     {
 *         return ['name' => ['required', 'string', 'max:120']];
 *     }
 * }
 * ```
 *
 * Ver `docs/guides/api.md`.
 */
abstract class BaseApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new ValidationException($validator);
    }
}
