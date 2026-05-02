<form wire:submit="save"
      x-data="{
          role: @entangle('form.role').live,
          permissions: @entangle('form.permissions').live,
          modules: @js($this->modules),

          syncFromRole() {
              const auto = [];
              this.modules.forEach(m => {
                  if (m.roles && m.roles.includes(this.role)) {
                      m.permissions.forEach(p => auto.push(p.value));
                  }
              });
              this.permissions = auto;
          },

          toggleModule(moduleIndex) {
              const perms = this.modules[moduleIndex].permissions.map(p => p.value);
              const allChecked = perms.every(v => this.permissions.includes(v));

              if (allChecked) {
                  this.permissions = this.permissions.filter(v => !perms.includes(v));
              } else {
                  this.permissions = [...new Set([...this.permissions, ...perms])];
              }
          },
      }">

    <x-kore::card :title="$this->title">
        <div class="space-y-6">

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <x-kore::input id="form-name" :label="__('Nombre')" wire:model="form.name" name="form.name" />
                <x-kore::input id="form-email" :label="__('Email')" type="email" wire:model="form.email" name="form.email" />
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <x-kore::password id="form-password" :label="__('Contraseña')" wire:model="form.password" name="form.password"
                    :placeholder="$this->model ? __('Dejar en blanco para no cambiar') : null" />
                <x-kore::password id="form-password-confirmation" :label="__('Confirmar contraseña')"
                    wire:model="form.password_confirmation" name="form.password_confirmation" />
            </div>

            <x-kore::select
                :label="__('Rol')"
                :options="$this->roles"
                wire:model.live="form.role"
                name="form.role"
                x-on:change="syncFromRole()"
                native
            />

            <div class="space-y-2">
                <label class="text-sm font-medium">{{ __('Permisos') }}</label>
                <div class="rounded-md border border-kore-border divide-y divide-kore-border">
                    <template x-for="(module, idx) in modules" :key="module.module">
                        <div class="p-4">
                            <button type="button"
                                    class="text-sm font-semibold hover:underline"
                                    x-on:click="toggleModule(idx)"
                                    x-text="module.module"></button>

                            <div class="mt-2 grid grid-cols-2 gap-2 md:grid-cols-4">
                                <template x-for="perm in module.permissions" :key="perm.value">
                                    <label class="flex items-center gap-2 text-sm">
                                        <input type="checkbox"
                                               :value="perm.value"
                                               x-model="permissions"
                                               class="rounded border-kore-border">
                                        <span x-text="perm.label"></span>
                                    </label>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
                @error('permissions') <p class="text-sm text-kore-destructive">{{ $message }}</p> @enderror
            </div>
        </div>

        <x-slot:footer>
            <div class="flex justify-end gap-2">
                <x-kore::button :href="route('users.index')" variant="ghost">
                    {{ __('Cancelar') }}
                </x-kore::button>
                <x-kore::button type="submit" icon="check"
                    wire:loading.attr="disabled" wire:target="save">
                    {{ __('Guardar') }}
                </x-kore::button>
            </div>
        </x-slot:footer>
    </x-kore::card>
</form>
