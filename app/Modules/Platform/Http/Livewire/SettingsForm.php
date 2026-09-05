<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Livewire;

use App\Core\Concerns\RedirectsWithToast;
use App\Core\Contracts\Settings;
use App\Modules\Platform\Actions\SettingResetAction;
use App\Modules\Platform\Actions\SettingUpdateAction;
// El Form Object se llama igual que este componente y vive en otro namespace,
// así que hay que renombrarlo al importarlo: PHP no admite un `use` cuyo nombre
// corto sea el de la clase del archivo.
use App\Modules\Platform\Forms\SettingsForm as SettingsFormObject;
use App\Modules\Platform\Models\Setting;
use App\Modules\Platform\Support\EditableSettings;
use KoreUi\Core\Concerns\InteractsWithFeedback;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * `/settings` — la pantalla de ajustes de la instalación.
 *
 * Autoriza → valida → DTO → Action, como cualquier formulario del boilerplate
 * (R4). Lo que tiene de propio es que **los campos no están escritos en
 * ninguna parte**: salen de `config('kore-settings.editable')`, así que un
 * derivado añade un ajuste suyo con tres líneas de configuración y esta clase
 * no cambia.
 *
 * La autorización va **dentro** de cada método público que escribe, y no sólo
 * en el `permission:settings.manage` de la ruta: las llamadas de Livewire viajan
 * por `/livewire/update`, donde el middleware de la ruta no corre (R23).
 */
#[Layout('layouts.app')]
final class SettingsForm extends Component
{
    use InteractsWithFeedback;
    use RedirectsWithToast;

    public SettingsFormObject $form;

    public function mount(Settings $settings): void
    {
        $this->authorize('view', Setting::class);

        $this->form->fillFromSettings($settings->all());
    }

    /**
     * Guarda todos los ajustes editables de una vez.
     *
     * La Action llega por inyección de método (Livewire la resuelve del
     * contenedor), que es lo que permite fakearla en un test sin tocar el
     * componente.
     */
    public function save(SettingUpdateAction $updateSettings): mixed
    {
        $this->authorize('update', Setting::class);

        $this->form->validate();

        $updateSettings->handle($this->form->toData(), (int) auth()->id());

        return $this->redirectWithToast(
            'settings.edit',
            __('¡Listo!'),
            __('Ajustes guardados correctamente.'),
        );
    }

    /**
     * Devuelve un ajuste a su valor por defecto y recarga el formulario.
     *
     * No redirige: se queda en la pantalla con el campo ya cambiado, porque
     * restablecer uno de siete campos no es terminar de configurar nada.
     */
    public function restore(string $slug, SettingResetAction $resetSetting, Settings $settings): void
    {
        $this->authorize('update', Setting::class);

        $definitions = resolve(EditableSettings::class)->bySlug();

        // Un slug que no está declarado no existe: `/livewire/update` acepta
        // cualquier argumento, así que la lista blanca se comprueba aquí y no
        // en la vista.
        abort_unless(array_key_exists($slug, $definitions), 404);

        $resetSetting->handle($definitions[$slug]['key'], (int) auth()->id());

        $this->form->fillFromSettings($settings->all());

        $this->toast()
            ->success(__('Restablecido'), __('El ajuste volvió a su valor por defecto.'))
            ->send();
    }

    /**
     * Los campos a pintar: slug, etiqueta y tipo.
     *
     * Sale como array y no como objetos: lo que llega a una Blade es un dato
     * (R30).
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function fields(): array
    {
        $fields = [];

        foreach (resolve(EditableSettings::class)->bySlug() as $slug => $definition) {
            $fields[] = [
                'slug' => $slug,
                'key' => $definition['key'],
                'label' => __($definition['label']),
                'type' => $definition['type'],
                'required' => $definition['required'],
            ];
        }

        return $fields;
    }

    public function render(): mixed
    {
        return view('platform::livewire.settings-form');
    }
}
