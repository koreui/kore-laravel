<?php

declare(strict_types=1);

use App\Core\Enums\ApiErrorCode;
use App\Core\Enums\SystemRole;
use App\Core\Http\Api\Resources\EnumResource;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| EnumResource · { value, label }
|--------------------------------------------------------------------------
*/

it('usa el método label() del enum cuando existe', function (): void {
    $resource = EnumResource::make(SystemRole::Admin);

    expect($resource->toArray(Request::create('/')))->toBe([
        'value' => 'Administrador',
        'label' => 'Administrador',
    ]);
});

it('cae al nombre del case cuando el enum no tiene label()', function (): void {
    $resource = EnumResource::make(ApiErrorCode::ValidationFailed);

    expect($resource->toArray(Request::create('/')))->toBe([
        'value' => 'validation_failed',
        'label' => 'ValidationFailed',
    ]);
});

it('envuelve en data cuando se serializa como respuesta', function (): void {
    $response = EnumResource::make(SystemRole::Superadmin)->response(Request::create('/'));

    expect($response->getData(true))->toBe([
        'data' => ['value' => 'superadmin', 'label' => 'Superadmin'],
    ]);
});

it('sirve para una colección de enums', function (): void {
    $collection = EnumResource::collection(SystemRole::assignable());

    $serialized = $collection->response(Request::create('/'))->getData(true);

    expect($serialized['data'])->toBe([
        ['value' => 'Administrador', 'label' => 'Administrador'],
        ['value' => 'Usuario', 'label' => 'Usuario'],
    ]);
});

it('rechaza lo que no es un BackedEnum', function (): void {
    expect(fn () => EnumResource::make('no soy un enum')->toArray(Request::create('/')))
        ->toThrow(InvalidArgumentException::class, 'EnumResource espera un BackedEnum');
});
