<?php

declare(strict_types=1);

namespace App\Core\Contracts;

use App\Core\Data\IssuedNumberData;

/**
 * Series de folio: números correlativos, sin huecos y sin repetirse.
 *
 * Es la frontera entre el módulo que las **implementa** (`App\Modules\Platform`,
 * con su tabla `number_sequences`) y todo el que sólo **consume** un número: un
 * recibo, una factura, un expediente, una orden de trabajo. Ninguno importa una
 * clase de Platform (R5) ni sabe cómo se guarda el contador.
 *
 * ## Por qué esto no es `max(id) + 1`
 *
 * Porque dos peticiones simultáneas leen el mismo máximo y emiten el mismo
 * folio. La implementación bloquea la fila del contador dentro de una
 * transacción (`lockForUpdate()`), que es lo que hace `ReciboService::emitir()`
 * en Notarium y la razón de que ese sistema lleve años sin un folio duplicado.
 * Un folio repetido en un documento fiscal no es un bug de la aplicación: es un
 * problema con la autoridad tributaria.
 *
 * ## Lo que este contrato NO hace
 *
 * **No reserva.** `next()` consume: el número sale de aquí ya gastado, exista o
 * no la fila que lo va a llevar. Es deliberado — un contador que devuelve
 * números «en préstamo» necesita saber cuándo se abandonó uno, y eso no se
 * puede saber. Por eso `next()` se llama **dentro** de la transacción que crea
 * el documento (ver `App\Core\Concerns\HasIssuedNumber`): si la transacción se
 * revierte, el folio se revierte con ella.
 *
 * **No formatea a gusto de quien llama.** El formato de cada serie vive en
 * `config/kore-numbering.php`, no en el llamante, para que el mismo folio se
 * imprima igual en la pantalla, en el PDF y en el export.
 *
 * La implementación se bindea en `PlatformModuleServiceProvider::register()`, y
 * **siempre**: Platform no tiene toggle. Ver `docs/modules/platform.md`.
 */
interface NumberSeries
{
    /**
     * Consume y devuelve el número siguiente de la serie.
     *
     * `$scope` separa contadores dentro de la misma serie —una sucursal, una
     * caja, un tenant— sin declarar una serie nueva: `next('receipt', 'CDMX')`
     * y `next('receipt', 'GDL')` llevan cuentas distintas con el mismo formato.
     * `null` es el contador global de la serie, y **no** es lo mismo que un
     * scope llamado `'null'`.
     *
     * Llámalo dentro de la transacción que escribe el documento.
     */
    public function next(string $series, ?string $scope = null): IssuedNumberData;

    /**
     * El número que devolvería `next()`, **sin consumirlo**.
     *
     * Sirve para pintar «el siguiente recibo será el 000123» en un formulario.
     * No es una reserva y no vale como garantía: entre el `peek()` y el `next()`
     * puede emitir otro. Quien necesite el número de verdad, que lo emita.
     */
    public function peek(string $series, ?string $scope = null): int;
}
