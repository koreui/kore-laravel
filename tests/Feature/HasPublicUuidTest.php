<?php

declare(strict_types=1);

use App\Core\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| App\Core\Concerns\HasPublicUuid
|--------------------------------------------------------------------------
|
| El trait es opt-in y hoy no lo usa ningún modelo del boilerplate, así que
| aquí se estrena sobre una tabla de laboratorio creada y tirada por el propio
| test. Lo que se prueba es lo que un derivado va a dar por hecho: que el uuid
| aparece solo al crear, que no pisa el que venga puesto, y que la llave de
| ruta sólo cambia si el modelo lo pide.
|
*/

/** Modelo de laboratorio con identidad pública y rutas por id. */
#[Fillable(['uuid', 'name'])]
#[WithoutTimestamps]
final class PublicUuidThing extends Model
{
    use HasPublicUuid;

    public $table = 'public_uuid_things';
}

/** El mismo, pero enrutando por uuid. */
#[Fillable(['uuid', 'name'])]
#[WithoutTimestamps]
final class PublicUuidRoutedThing extends Model
{
    use HasPublicUuid;

    public const bool ROUTE_BY_UUID = true;

    public $table = 'public_uuid_things';
}

beforeEach(function (): void {
    Schema::create('public_uuid_things', function (Blueprint $table): void {
        $table->id();
        $table->uuid('uuid')->nullable()->unique();
        $table->string('name')->nullable();
    });
});

afterEach(function (): void {
    Schema::dropIfExists('public_uuid_things');
});

it('rellena el uuid al crear', function (): void {
    $thing = PublicUuidThing::create(['name' => 'primero']);

    expect($thing->getAttribute('uuid'))
        ->toBeString()
        ->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');
});

it('deja la llave primaria entera', function (): void {
    $thing = PublicUuidThing::create(['name' => 'primero']);

    expect($thing->getKey())->toBeInt()->toBeGreaterThan(0);
});

it('da un uuid distinto a cada registro', function (): void {
    $first = PublicUuidThing::create(['name' => 'a']);
    $second = PublicUuidThing::create(['name' => 'b']);

    expect($first->getAttribute('uuid'))->not->toBe($second->getAttribute('uuid'));
});

/*
 * Una importación que conserva el uuid de origen es el caso que hace útil la
 * columna: si el trait lo pisara, el registro dejaría de ser el mismo objeto
 * al otro lado.
 */
it('respeta el uuid que ya viene puesto', function (): void {
    $uuid = '11111111-2222-3333-4444-555555555555';

    $thing = PublicUuidThing::create(['name' => 'importado', 'uuid' => $uuid]);

    expect($thing->getAttribute('uuid'))->toBe($uuid);
});

it('enruta por id mientras el modelo no diga lo contrario', function (): void {
    expect((new PublicUuidThing)->getRouteKeyName())->toBe('id');
});

it('enruta por uuid cuando el modelo declara ROUTE_BY_UUID', function (): void {
    expect((new PublicUuidRoutedThing)->getRouteKeyName())->toBe('uuid');
});

it('resuelve un binding de ruta por el uuid', function (): void {
    $thing = PublicUuidRoutedThing::create(['name' => 'buscado']);

    $found = (new PublicUuidRoutedThing)->resolveRouteBinding($thing->getAttribute('uuid'));

    expect($found?->getKey())->toBe($thing->getKey());
});
