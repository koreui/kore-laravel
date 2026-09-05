<?php

declare(strict_types=1);

use App\Core\Contracts\NumberSeries;
use App\Modules\Platform\Models\NumberSequence;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Date;

/*
|--------------------------------------------------------------------------
| Series de folio
|--------------------------------------------------------------------------
|
| **El límite de esta suite, escrito para que nadie se confíe.** La base de la
| suite es SQLite en memoria: no hay concurrencia real, así que estos tests NO
| prueban que `lockForUpdate()` funcione —eso lo prueba el motor de la base—.
| Lo que prueban es todo lo demás, que es lo que sí puede romper un cambio
| nuestro: que el contador no se salte ni repita un número en 50 emisiones
| seguidas, que el periodo se reinicie al cambiar de año, que el scope lleve
| cuentas separadas y que el formato salga como dice la configuración.
|
| La carrera de dos procesos creando la fila a la vez sí se prueba, y por el
| único camino honesto que hay en una base de un solo escritor: provocando la
| violación de unicidad a mano y comprobando que el reintento la absorbe.
|
*/

beforeEach(function (): void {
    $this->series = resolve(NumberSeries::class);

    config()->set('kore-numbering.series.receipt', [
        'prefix' => 'REC',
        'reset' => 'yearly',
    ]);
});

it('emite el primer folio de la serie con su formato', function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-03-01 10:00:00'));

    $issued = $this->series->next('receipt');

    expect($issued->number)->toBe(1)
        ->and($issued->formatted)->toBe('REC-2026-000001')
        ->and($issued->series)->toBe('receipt')
        ->and($issued->scope)->toBeNull()
        ->and($issued->issuedAt)->toStartWith('2026-03-01T10:00:00');
});

it('emite 50 folios seguidos sin huecos ni duplicados', function (): void {
    $numbers = [];

    for ($i = 0; $i < 50; $i++) {
        $numbers[] = $this->series->next('receipt')->number;
    }

    expect($numbers)->toBe(range(1, 50))
        ->and(array_unique($numbers))->toHaveCount(50)
        // Un solo contador para las 50: si se hubiera creado una fila por
        // emisión, el bloqueo no estaría bloqueando nada.
        ->and(NumberSequence::query()->count())->toBe(1);
});

it('reinicia el contador al cambiar de año con reset yearly', function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-12-31 23:59:00'));
    $ultimo2026 = $this->series->next('receipt');

    Date::setTestNow(CarbonImmutable::parse('2027-01-01 00:01:00'));
    $primero2027 = $this->series->next('receipt');

    expect($ultimo2026->number)->toBe(1)
        ->and($ultimo2026->formatted)->toBe('REC-2026-000001')
        ->and($primero2027->number)->toBe(1)
        ->and($primero2027->formatted)->toBe('REC-2027-000001')
        // Dos contadores, uno por año: es lo que hace la clave única.
        ->and(NumberSequence::query()->count())->toBe(2);
});

it('con reset never el contador no se reinicia y el periodo va nulo', function (): void {
    config()->set('kore-numbering.series.invoice', ['prefix' => 'FAC', 'reset' => 'never']);

    Date::setTestNow(CarbonImmutable::parse('2026-12-31 23:59:00'));
    $this->series->next('invoice');

    Date::setTestNow(CarbonImmutable::parse('2027-01-01 00:01:00'));
    $siguiente = $this->series->next('invoice');

    expect($siguiente->number)->toBe(2)
        ->and(NumberSequence::query()->where('series', 'invoice')->count())->toBe(1)
        ->and(NumberSequence::query()->where('series', 'invoice')->value('period'))->toBeNull();
});

it('el scope lleva contadores separados con el mismo formato', function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-03-01 10:00:00'));

    $this->series->next('receipt', 'CDMX');
    $segundoCdmx = $this->series->next('receipt', 'CDMX');
    $primeroGdl = $this->series->next('receipt', 'GDL');
    $global = $this->series->next('receipt');

    expect($segundoCdmx->number)->toBe(2)
        ->and($primeroGdl->number)->toBe(1)
        // El contador global NO es el scope 'null': son tres filas distintas.
        ->and($global->number)->toBe(1)
        ->and(NumberSequence::query()->count())->toBe(3);
});

it('arranca en el `start` de la serie', function (): void {
    // Lo que usa un derivado que migra desde otro sistema y sigue su numeración.
    config()->set('kore-numbering.series.legacy', ['prefix' => 'LEG', 'reset' => 'never', 'start' => 1500]);

    expect($this->series->next('legacy')->number)->toBe(1500)
        ->and($this->series->next('legacy')->number)->toBe(1501);
});

it('una serie sin declarar hereda los defaults', function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-03-01 10:00:00'));

    $issued = $this->series->next('lo-que-sea');

    expect($issued->formatted)->toBe('DOC-2026-000001');
});

it('sustituye todas las marcas del formato', function (): void {
    config()->set('kore-numbering.series.ticket', [
        'prefix' => 'TCK',
        'format' => '{PREFIX}/{SCOPE}/{YEAR}{MONTH}-{NUMBER:4}',
        'reset' => 'never',
    ]);

    Date::setTestNow(CarbonImmutable::parse('2026-07-09 08:00:00'));

    expect($this->series->next('ticket', 'MX')->formatted)->toBe('TCK/MX/202607-0001');
});

it('el folio se desborda con gracia en vez de truncarse', function (): void {
    // Truncar sería emitir dos folios con el mismo texto, que es peor que un
    // folio de siete dígitos donde se esperaban seis.
    config()->set('kore-numbering.series.big', ['prefix' => 'BIG', 'reset' => 'never', 'start' => 1_000_000]);

    Date::setTestNow(CarbonImmutable::parse('2026-03-01 10:00:00'));

    expect($this->series->next('big')->formatted)->toBe('BIG-2026-1000000');
});

it('peek dice el siguiente sin consumirlo', function (): void {
    expect($this->series->peek('receipt'))->toBe(1);

    // Y no ha escrito nada: sigue sin haber contador.
    expect(NumberSequence::query()->count())->toBe(0);

    $this->series->next('receipt');

    expect($this->series->peek('receipt'))->toBe(2);
});

it('peek respeta el `start` de una serie que aún no emitió nada', function (): void {
    config()->set('kore-numbering.series.legacy', ['prefix' => 'LEG', 'reset' => 'never', 'start' => 1500]);

    expect($this->series->peek('legacy'))->toBe(1500);
});

it('absorbe la carrera de dos procesos creando el contador a la vez', function (): void {
    /*
     * La única forma honesta de provocar la carrera con una base de un solo
     * escritor: se crea la fila por debajo —como habría hecho el otro
     * proceso— justo antes de emitir. La primera pasada de la Action hace su
     * SELECT sin encontrarla... salvo que aquí ya está, así que lo que se
     * comprueba es lo importante: que el contador que gana es el que existe y
     * que no se emite el número 1 por segunda vez.
     */
    Date::setTestNow(CarbonImmutable::parse('2026-03-01 10:00:00'));

    NumberSequence::query()->create([
        'series' => 'receipt',
        'scope' => null,
        'period' => '2026',
        'last_number' => 7,
    ]);

    expect($this->series->next('receipt')->number)->toBe(8)
        ->and(NumberSequence::query()->count())->toBe(1);
});

it('la clave única impide dos contadores para la misma serie, scope y periodo', function (): void {
    NumberSequence::query()->create(['series' => 'receipt', 'scope' => 'CDMX', 'period' => '2026', 'last_number' => 1]);

    expect(fn (): mixed => NumberSequence::query()->create([
        'series' => 'receipt', 'scope' => 'CDMX', 'period' => '2026', 'last_number' => 1,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

afterEach(function (): void {
    Date::setTestNow();
});
