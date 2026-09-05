<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Parámetros del módulo Notifications
    |--------------------------------------------------------------------------
    |
    | Este archivo NO es `config/kore-app.php` y no duplica su toggle: quién
    | enciende el módulo sigue siendo `NOTIFICATIONS_ENABLED`
    | (`kore-app.notifications.enabled`). Aquí sólo vive cómo se comporta cuando
    | está encendido, igual que `config/kore-api.php` con la API. Por eso el
    | check R11 no mira este archivo: declara parámetros, no capacidades.
    |
    | Recordatorio de R12: un `config/*.php` no puede leer otro (se cargan en
    | orden alfabético). Nada de aquí consulta `kore-app`.
    |
    | Ver `docs/modules/notifications.md`.
    |
    */

    /*
     * El catálogo de categorías, y **el sitio donde un derivado amplía el suyo**.
     *
     * `App\Core\Enums\NotificationCategory` lista las tres del núcleo para que
     * el código pueda citarlas con una constante, pero un enum de PHP no se
     * extiende: si el catálogo viviera sólo ahí, un proyecto hijo tendría que
     * editar Core para tener una categoría propia. Aquí no: añade una clave
     * más —`'facturacion' => ['label' => 'Facturación', ...]`— y aparece sola
     * en la pantalla de preferencias, en la API y en el filtro de la bandeja.
     *
     * Cada entrada declara su etiqueta (en español, R33: la traducción va en el
     * `en.json` del módulo) y qué canales trae encendidos **para quien nunca
     * configuró nada**. La ausencia de fila en `notification_preferences` no
     * significa «apagado»: significa «lo que diga esto», y por eso añadir una
     * categoría no obliga a sembrar una fila por usuario.
     *
     * `in_app` es el canal base: apagarlo hace que el aviso ni siquiera se
     * guarde para esa persona.
     *
     * **`push` va apagado en las tres a propósito.** El canal de push del
     * boilerplate sólo deja una línea en el log (ver
     * `Notifications\Support\PushChannel`), así que traerlo encendido de fábrica
     * prometería una entrega que no ocurre. Un derivado que enchufe FCM o Expo
     * lo enciende aquí, y mientras tanto cada persona puede pedirlo desde su
     * pantalla de preferencias.
     */
    'categories' => [
        'system' => [
            'label' => 'Sistema',
            'in_app' => true,
            'mail' => true,
            'push' => false,
        ],
        'account' => [
            'label' => 'Cuenta',
            'in_app' => true,
            'mail' => true,
            'push' => false,
        ],
        'activity' => [
            'label' => 'Actividad',
            'in_app' => true,
            'mail' => false,
            'push' => false,
        ],
    ],

    /*
     * Días que se conserva una notificación **leída** antes de que
     * `notifications:prune` se la lleve.
     *
     * Las no leídas no se borran nunca por edad: si nadie las vio, borrarlas es
     * perder el aviso. La cifra es el plazo por defecto del comando; el
     * scheduler la repite en su línea a propósito, porque borrar es destructivo
     * y el número tiene que verse donde se aplica.
     */
    'prune_days' => 90,

    /*
     * La campana del encabezado.
     *
     * `poll_seconds` es cada cuánto vuelve a preguntar por el contador de no
     * leídas. No es gratis: son tantas consultas por minuto como pestañas
     * abiertas haya. Treinta segundos es el compromiso entre «me entero» y «no
     * convierto la portada en un cron»; un derivado con websockets lo sube a
     * cero y refresca por evento.
     */
    'bell' => [
        'poll_seconds' => 30,
    ],

];
