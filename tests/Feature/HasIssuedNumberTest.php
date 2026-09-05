<?php

declare(strict_types=1);

use App\Core\Concerns\HasIssuedNumber;
use App\Core\Contracts\NumberSeries;
use App\Exceptions\ConflictException;
use App\Modules\Platform\Models\NumberSequence;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| App\Core\Concerns\HasIssuedNumber
|--------------------------------------------------------------------------
|
| El trait es opt-in y hoy no lo usa ningún modelo del boilerplate, así que aquí
| se estrena sobre una tabla de laboratorio creada y tirada por el propio test.
| Es el mismo montaje que `HasPublicUuidTest`, y por la misma razón de fondo:
| PHPStan no analiza el cuerpo de un trait que nadie usa, así que este archivo
| entra en `phpstan.neon` para que el trait viaje comprobado.
|
| Vive en `tests/Feature` y no en `app/Modules/Platform/Tests` porque el trait
| es de `Core`: lo que se prueba es la pieza compartida, no el módulo. Que la
| serie que emite sea la de Platform es un detalle de la implementación, igual
| que `NumberSeries` es el contrato que un derivado inyectaría.
|
*/

/** Documento de laboratorio con folio. */
#[Fillable([])]
final class NumberedThing extends Model
{
    use HasIssuedNumber;

    public $table = 'numbered_things';
}

beforeEach(function (): void {
    Schema::create('numbered_things', function (Blueprint $table): void {
        $table->id();
        $table->string('number')->nullable()->unique();
        $table->timestamp('number_issued_at')->nullable();
        $table->timestamps();
    });

    config()->set('kore-numbering.series.receipt', ['prefix' => 'REC', 'reset' => 'yearly']);
});

afterEach(function (): void {
    Date::setTestNow();
    Schema::dropIfExists('numbered_things');
});

it('escribe el folio y la fecha de emisión en el modelo', function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-03-01 10:00:00'));

    $thing = NumberedThing::query()->create([]);
    $issued = $thing->issueNumber(resolve(NumberSeries::class), 'receipt');

    expect($thing->fresh()?->getAttribute('number'))->toBe('REC-2026-000001')
        ->and($thing->fresh()?->getAttribute('number_issued_at'))->not->toBeNull()
        ->and($issued->number)->toBe(1)
        ->and($thing->hasIssuedNumber())->toBeTrue();
});

it('un documento sin folio todavía no lo tiene', function (): void {
    expect(NumberedThing::query()->create([])->hasIssuedNumber())->toBeFalse();
});

it('no reemite el folio de un documento que ya lo tiene', function (): void {
    // Reemitir dejaría el folio anterior impreso en manos de alguien y un hueco
    // en la serie, que es exactamente lo que busca una auditoría.
    $thing = NumberedThing::query()->create([]);
    $thing->issueNumber(resolve(NumberSeries::class), 'receipt');

    expect(fn (): mixed => $thing->issueNumber(resolve(NumberSeries::class), 'receipt'))
        ->toThrow(ConflictException::class);

    expect(NumberSequence::query()->value('last_number'))->toBe(1);
});

it('el folio se revierte con la transacción que crea el documento', function (): void {
    /*
     * El motivo de que `next()` se llame DENTRO de la transacción del
     * documento: si el guardado falla, el contador tiene que volver atrás. Un
     * contador que avanzó sin documento es el hueco en la serie.
     */
    try {
        DB::transaction(function (): void {
            $thing = NumberedThing::query()->create([]);
            $thing->issueNumber(resolve(NumberSeries::class), 'receipt');

            throw new RuntimeException('algo falló después de emitir');
        });
    } catch (RuntimeException) {
        // Esperado.
    }

    expect(NumberedThing::query()->count())->toBe(0)
        ->and(NumberSequence::query()->count())->toBe(0);
});
