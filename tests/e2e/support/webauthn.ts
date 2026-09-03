import type { Page } from '@playwright/test';

/**
 * Autenticador WebAuthn virtual, vía CDP.
 *
 * Sin esto no hay forma de probar passkeys en un E2E: la ceremonia la resuelve
 * el sistema operativo (Touch ID, Windows Hello, una llave física) y Playwright
 * no puede tocar ese diálogo. Chrome DevTools Protocol expone un autenticador
 * de mentira que responde por él, con claves reales y firmas válidas — el
 * servidor las verifica igual que las de un dispositivo de verdad.
 *
 * Los cuatro flags que importan:
 *
 * - `hasResidentKey` · el paquete pide `residentKey: required` para que el
 *   login funcione sin escribir el email (credencial «descubrible»). Sin esto
 *   el registro falla con `NotSupportedError`.
 * - `hasUserVerification` + `isUserVerified` · también pide
 *   `userVerification: required`: el autenticador tiene que declarar que sabe
 *   verificar al usuario **y** responder que lo ha hecho.
 * - `automaticPresenceSimulation` · nadie va a tocar la llave, así que la
 *   presencia se simula sola.
 *
 * El autenticador vive en el target de esa `page`: sobrevive a las
 * navegaciones (registrar → cerrar sesión → entrar con passkey) y muere con
 * ella. Un `page` nuevo necesita el suyo.
 *
 * @see https://chromedevtools.github.io/devtools-protocol/tot/WebAuthn/
 */
export type VirtualAuthenticator = {
    /** Id que devuelve CDP, por si el test necesita inspeccionar credenciales. */
    readonly authenticatorId: string;
    /** Credenciales que el autenticador guarda ahora mismo. */
    readonly credentials: () => Promise<unknown[]>;
};

export async function addVirtualAuthenticator(page: Page): Promise<VirtualAuthenticator> {
    const cdp = await page.context().newCDPSession(page);

    await cdp.send('WebAuthn.enable');

    const { authenticatorId } = await cdp.send('WebAuthn.addVirtualAuthenticator', {
        options: {
            protocol: 'ctap2',
            transport: 'internal',
            hasResidentKey: true,
            hasUserVerification: true,
            isUserVerified: true,
            automaticPresenceSimulation: true,
        },
    });

    return {
        authenticatorId,
        credentials: async (): Promise<unknown[]> => {
            const { credentials } = await cdp.send('WebAuthn.getCredentials', { authenticatorId });

            return credentials;
        },
    };
}
