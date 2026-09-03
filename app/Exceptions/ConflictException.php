<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * El estado actual del recurso no admite la operación (HTTP 409).
 *
 * Es una excepción de **dominio**, no de la capa Http: la lanza una Action
 * («este dispositivo ya está registrado», «la factura ya está pagada») y quien
 * decide que eso se traduce en un 409 es
 * `App\Core\Http\Api\Exceptions\ApiExceptionRenderer`. Por eso no extiende
 * `HttpException` y por eso una Action puede lanzarla sin romper R20: sigue
 * siendo ejecutable desde un job o un comando, donde nadie va a rendir un
 * status HTTP.
 *
 * A diferencia del resto de códigos, aquí **sí** viaja el mensaje que escribió
 * el autor: es texto de dominio, escrito en español con `__()` y traducible
 * (R33), no el texto interno de una excepción del framework. Lo que no lleva
 * es `details`: en el envelope de error esa clave es exclusiva del 422 y
 * significa siempre «errores por campo».
 *
 * Vive en `App\Exceptions` y no en `App\Core` por la misma razón que
 * `App\Models\User`: es una pieza global de la aplicación, no del kernel, y el
 * preset `laravel` de Pest exige que los `Throwable` estén ahí. R3 tampoco
 * admite una carpeta `Exceptions/` dentro de un módulo, así que éste es el
 * único sitio donde una excepción de dominio compartida puede vivir.
 *
 * ```php
 * throw new ConflictException('Este dispositivo ya está registrado en otra cuenta.');
 * ```
 *
 * En código real el mensaje va envuelto en `__()`, como cualquier otro texto
 * (R33). Aquí no, a propósito: `TranslationsTest` extrae las claves leyendo el
 * texto de los archivos de `app/`, comentarios incluidos, y un ejemplo de
 * docblock acabaría pidiendo su traducción en `lang/en.json`.
 */
final class ConflictException extends RuntimeException {}
