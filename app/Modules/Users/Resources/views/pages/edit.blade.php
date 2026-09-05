<x-layouts.app :title="__('Editar usuario')">
    {{-- El panel de estado va FUERA del <form> del componente de edición, y no
         por estilo: anidar un componente con botones dentro de otro formulario
         haría que un clic en «Activar» enviara el formulario del usuario. Sólo
         existe con AUTH_INVITATIONS, porque con el toggle apagado el estado de
         la cuenta no gobierna nada. --}}
    @if (config('kore-app.auth.invitations'))
        <div class="mb-6">
            <livewire:users.account-status-panel :user="$model" />
        </div>
    @endif

    <livewire:users.form-component :$model />
</x-layouts.app>
