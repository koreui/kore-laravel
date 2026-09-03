import './bootstrap';

// kore-ui sirve su bundle JS via la ruta /vendor/kore-ui/kore-ui.js
// inyectada por la directiva @koreScripts (antes de @livewireScripts).
// No requiere importar nada desde vendor/.

import { Passkeys } from '@laravel/passkeys';

// Cliente oficial de passkeys. Habla con los endpoints que publica Fortify
// (`/passkeys/login*`, `/user/passkeys*`) y adjunta el CSRF leyendo el
// `<meta name="csrf-token">` que ponen los dos layouts.
window.Passkeys = Passkeys;

/**
 * Componente Alpine `korePasskeys`.
 *
 * Lo usan dos pantallas: `/login` (botón «Entrar con passkey») y
 * `/user/passkeys` (alta). Los textos llegan desde la Blade con `@js([...])`
 * para que sigan pasando por `__()` (R33): el cliente sólo devuelve mensajes en
 * inglés, y aquí se traducen por el `name` del error, no por su texto.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('korePasskeys', (messages = {}) => ({
        name: '',
        busy: false,
        error: '',
        supported: window.Passkeys.isSupported(),

        /** Mensaje traducido para un error del cliente de passkeys. */
        messageFor(error) {
            switch (error?.name) {
                case 'UserCancelledError':
                    return messages.cancelled ?? '';
                case 'NotSupportedError':
                    return messages.unsupported ?? '';
                case 'PasskeyExistsError':
                    return messages.exists ?? '';
                case 'InvalidDomainError':
                    return messages.domain ?? '';
                default:
                    return messages.failed ?? '';
            }
        },

        /** `/login` · ceremonia de verificación y redirección a lo que diga el servidor. */
        async signInWithPasskey() {
            this.error = '';
            this.busy = true;

            try {
                const { redirect } = await window.Passkeys.verify();

                window.location.assign(redirect ?? messages.redirect ?? '/');
            } catch (error) {
                this.error = this.messageFor(error);
                this.busy = false;
            }
        },

        /** `/user/passkeys` · alta y refresco del listado que pinta Livewire. */
        async registerPasskey() {
            const name = this.name.trim();

            if (name === '') {
                this.error = messages.nameRequired ?? '';

                return;
            }

            this.error = '';
            this.busy = true;

            try {
                await window.Passkeys.register({ name });

                this.name = '';
                await this.$wire?.$refresh();
            } catch (error) {
                this.error = this.messageFor(error);
            } finally {
                this.busy = false;
            }
        },
    }));
});
