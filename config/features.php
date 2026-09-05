<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Features por instalación — módulo Platform
    |--------------------------------------------------------------------------
    |
    | Qué módulos incluye ESTA instalación. Es la capa que responde «tu licencia
    | no incluye esto», y es distinta de las otras tres:
    |
    |   config/kore-app.php  · «el boilerplate TRAE esta capacidad»   (deploy)
    |   config/features.php  · «tu LICENCIA no incluye esto»          (venta)
    |   Laravel Pennant      · «TODAVÍA no te toca»                   (rollout)
    |   spatie/permission    · «no tienes PERMISO»                    (rol)
    |
    | Las cuatro dan un «no» y las cuatro son un «no» distinto. Mezclarlas —en
    | Notarium se apagaba un módulo no licenciado quitándole el permiso a todos
    | los roles— hace imposible saber si el cliente no ve una pantalla porque no
    | la compró o porque alguien le tocó los permisos. Ver
    | `docs/architecture/toggles.md` §«Tres capas».
    |
    | Se consume por el contrato `App\Core\Contracts\InstallationFeatures`, por
    | el middleware `feature:{clave}` o por la directiva `@feature('clave')`;
    | nunca leyendo este archivo a mano desde una vista.
    |
    | El mapa es plano y de booleanos: un feature de esta capa no depende del
    | usuario, no cambia a mitad del día y no tiene rollout gradual. Lo que sí
    | depende del usuario es Pennant.
    |
    | Recordatorio de R12: un `config/*.php` no puede leer otro. Y R11 no aplica
    | aquí: el check de toggles fantasma sólo vigila `kore-app`, porque un
    | feature sin lector en el boilerplate lo va a tener en el derivado — es
    | justo lo que un derivado añade.
    |
    */

    /*
     * Informes y tableros. Encendido: es la parte del producto que casi todo
     * derivado incluye.
     */
    'reports' => (bool) env('FEATURE_REPORTS', true),

    /*
     * Exportar a CSV, Excel o PDF (la carpeta `Exports/` de un módulo, ver
     * `docs/guides/exports.md`). Encendido por lo mismo.
     */
    'exports' => (bool) env('FEATURE_EXPORTS', true),

    /*
     * Webhooks salientes de la API. APAGADO por defecto, y es el ejemplo de por
     * qué esta capa existe: entregar webhooks obliga a mantener reintentos,
     * firmas y un buzón de fallos, así que es lo típico que se vende aparte y
     * no se enciende «por si acaso».
     */
    'api_webhooks' => (bool) env('FEATURE_API_WEBHOOKS', false),

];
