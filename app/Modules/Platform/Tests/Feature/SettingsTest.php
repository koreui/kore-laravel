<?php

declare(strict_types=1);

use App\Core\Contracts\Settings;
use App\Core\Data\OrganizationData;
use App\Models\User;
use App\Modules\Platform\Actions\SettingUpdateAction;
use App\Modules\Platform\Data\SettingsFormData;
use App\Modules\Platform\Models\Setting;
use App\Modules\Platform\Support\SettingsCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| El contrato Settings sobre la tabla `settings`
|--------------------------------------------------------------------------
|
| La cascada (fila → config → $default), que leer no escriba, y que la caché se
| invalide sola en cada escritura.
|
*/

beforeEach(function (): void {
    $this->actor = User::factory()->create();
    $this->settings = resolve(Settings::class);
});

it('devuelve el valor de config cuando la clave no tiene fila', function (): void {
    // El array ENTERO, no `defaults.organization.name`: la clave lleva punto y
    // `config()` lo interpretaría como un nivel. Ver DatabaseSettings::defaults().
    config()->set('kore-settings.defaults', ['organization.name' => 'Kore por defecto']);

    expect($this->settings->get('organization.name'))->toBe('Kore por defecto');
});

it('devuelve el $default cuando la clave no está ni en la base ni en config', function (): void {
    expect($this->settings->get('organization.inventada', 'nada'))->toBe('nada');
});

it('leer un ajuste no crea ninguna fila', function (): void {
    // La diferencia con NotariaConfiguracion::instancia(), que insertaba al
    // primer acceso: allí una petición GET podía acabar en un INSERT.
    $this->settings->get('organization.name');
    $this->settings->all();

    expect(Setting::query()->count())->toBe(0);
});

it('la fila gana al valor de config', function (): void {
    // El array ENTERO, no `defaults.organization.name`: la clave lleva punto y
    // `config()` lo interpretaría como un nivel. Ver DatabaseSettings::defaults().
    config()->set('kore-settings.defaults', ['organization.name' => 'Kore por defecto']);

    $this->settings->set('organization.name', 'Notaría 42', $this->actor->id);

    expect($this->settings->get('organization.name'))->toBe('Notaría 42')
        ->and(Setting::query()->where('key', 'organization.name')->value('changed_by'))
        ->toBe($this->actor->id);
});

it('forget borra la fila y devuelve la clave a su defecto', function (): void {
    // El array ENTERO, no `defaults.organization.name`: la clave lleva punto y
    // `config()` lo interpretaría como un nivel. Ver DatabaseSettings::defaults().
    config()->set('kore-settings.defaults', ['organization.name' => 'Kore por defecto']);

    $this->settings->set('organization.name', 'Notaría 42', $this->actor->id);
    $this->settings->forget('organization.name', $this->actor->id);

    expect(Setting::query()->where('key', 'organization.name')->exists())->toBeFalse()
        ->and($this->settings->get('organization.name'))->toBe('Kore por defecto');
});

it('all() mezcla los defaults con lo guardado', function (): void {
    $this->settings->set('organization.tax_id', 'XAXX010101000', $this->actor->id);

    $all = $this->settings->all();

    expect($all)->toHaveKey('organization.name')
        ->and($all['organization.tax_id'])->toBe('XAXX010101000');
});

it('rechaza una clave que no está declarada como editable', function (): void {
    // Sin este corte, un set() con la clave mal escrita crearía un ajuste
    // fantasma que no lee nadie y que nunca se nota.
    expect(fn (): mixed => $this->settings->set('organization.inventada', 'x', $this->actor->id))
        ->toThrow(InvalidArgumentException::class);
});

it('rechaza un valor que no pasa las reglas de su tipo', function (): void {
    // La pantalla ya valida, pero `Settings::set()` sirve igual desde un
    // comando o un seeder, y ahí no hay formulario: sin esta comprobación un
    // correo mal escrito se guardaría y el error saldría meses después, en el
    // pie de un PDF. Las reglas son las MISMAS que deriva `SettingsForm`,
    // porque las dos salen de `EditableSettings`.
    expect(fn (): mixed => $this->settings->set('organization.email', 'no-es-un-correo', $this->actor->id))
        ->toThrow(ValidationException::class);

    expect(Setting::query()->where('key', '=', 'organization.email')->exists())->toBeFalse();
});

it('rechaza un requerido en blanco y no guarda nada del lote', function (): void {
    expect(fn (): mixed => resolve(SettingUpdateAction::class)->handle(
        new SettingsFormData([
            'organization.name' => null,
            'organization.tax_id' => 'XAXX010101000',
        ]),
        $this->actor->id,
    ))->toThrow(ValidationException::class);

    // Ni la clave buena: media configuración aplicada es peor que ninguna.
    expect(Setting::query()->count())->toBe(0);
});

it('nombra el ajuste en español en el mensaje de error', function (): void {
    try {
        $this->settings->set('organization.email', 'no-es-un-correo', $this->actor->id);
    } catch (ValidationException $exception) {
        expect($exception->validator->errors()->first('organization_email'))
            ->toContain('correo de contacto');

        return;
    }

    throw new RuntimeException('Se esperaba una ValidationException.');
});

it('la escritura invalida la caché', function (): void {
    $key = resolve(SettingsCache::class)->key();

    // Primera lectura: cachea el mapa vacío.
    $this->settings->get('organization.name');
    expect(Cache::has($key))->toBeTrue();

    $this->settings->set('organization.name', 'Notaría 42', $this->actor->id);

    expect(Cache::has($key))->toBeFalse();
});

it('una lectura posterior a la escritura no sirve el valor viejo desde la caché', function (): void {
    // El caso de verdad: dos peticiones distintas, la segunda con la caché ya
    // caliente de la primera. `Settings` es singleton por petición, así que se
    // resuelve una instancia nueva a propósito.
    $this->settings->get('organization.name');
    $this->settings->set('organization.name', 'Notaría 42', $this->actor->id);

    app()->forgetInstance(Settings::class);

    expect(resolve(Settings::class)->get('organization.name'))->toBe('Notaría 42');
});

it('guardar bool y int conserva el tipo', function (): void {
    // La columna es JSON justamente por esto: con `varchar`, el `false` volvería
    // como la cadena "" y el 0 como "0".
    config()->set('kore-settings.editable', [
        'organization.activa' => ['type' => 'bool', 'label' => 'Activa'],
        'organization.empleados' => ['type' => 'int', 'label' => 'Empleados'],
    ]);

    $this->settings->set('organization.activa', false, $this->actor->id);
    $this->settings->set('organization.empleados', 12, $this->actor->id);

    app()->forgetInstance(Settings::class);
    $settings = resolve(Settings::class);

    expect($settings->get('organization.activa'))->toBeFalse()
        ->and($settings->get('organization.empleados'))->toBe(12);
});

it('compone OrganizationData desde el mapa de ajustes', function (): void {
    $this->settings->set('organization.name', 'Notaría 42', $this->actor->id);
    $this->settings->set('organization.legal_name', 'Notaría 42, S.C.', $this->actor->id);

    $organization = OrganizationData::fromSettings($this->settings->all());

    expect($organization->name)->toBe('Notaría 42')
        ->and($organization->displayName())->toBe('Notaría 42, S.C.')
        ->and($organization->taxId)->toBeNull();
});

it('displayName cae al nombre comercial cuando no hay razón social', function (): void {
    expect(OrganizationData::fromSettings(['organization.name' => 'Kore'])->displayName())
        ->toBe('Kore');
});
