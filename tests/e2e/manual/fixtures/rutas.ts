/**
 * Dónde vive el manual generado y contra qué se genera.
 *
 * Está en su propio archivo, sin importar nada, por una razón práctica:
 * `globalSetup` y `globalTeardown` los carga Playwright **antes** de montar el
 * runner, y un archivo que alcance al `test` de la suite —que registra un
 * `beforeEach`— revienta el arranque. Un par de constantes sueltas no arrastran
 * nada.
 */

/** Carpeta de salida del manual, relativa a la raíz del repositorio. */
export const RAIZ_MANUAL = 'docs/manual';

/** Carpeta de las capturas, dentro de la anterior. Es un artefacto: no se versiona. */
export const IMAGENES = 'imagenes';

/** Puerto propio del manual, para no pelearse con el 8010 de la suite. */
export const PUERTO_MANUAL = process.env.E2E_MANUAL_PORT ?? '8110';

/** URL base contra la que corre el manual. */
export const URL_MANUAL = process.env.E2E_MANUAL_URL ?? `http://localhost:${PUERTO_MANUAL}`;
