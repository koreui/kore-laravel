<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Módulo Webhooks — parámetros
    |--------------------------------------------------------------------------
    |
    | Cómo se comporta la salida de webhooks cuando está encendida. Ver
    | `docs/modules/webhooks.md`.
    |
    | Este archivo NO es `config/kore-app.php` y no duplica su toggle: quién
    | enciende el módulo sigue siendo `WEBHOOKS_ENABLED`
    | (`kore-app.webhooks.enabled`), que es lo que hace que
    | `WebhooksModuleServiceProvider` registre el binding de `WebhookPublisher`,
    | las rutas, los listeners, el alias `webhook.signed` y los dos comandos. Es
    | el mismo reparto que `kore-api.php` respecto de `API_ENABLED` y que
    | `files.php` respecto de `FILES_ENABLED`.
    |
    | Recordatorio de R12: un `config/*.php` no puede leer otro (se cargan en
    | orden alfabético), así que aquí no aparece ningún `config('kore-app.…')`.
    |
    */

    /*
     * Segundos que se espera a que el receptor conteste, y a que llegue a
     * aceptar la conexión.
     *
     * Cortos a propósito: la entrega la hace un listener en cola, así que un
     * receptor lento no bloquea a nadie, pero un worker esperando treinta
     * segundos por cada uno de mil webhooks sí atasca la cola entera. Lo que
     * falla por timeout se reintenta con el backoff de abajo.
     */
    'timeout' => (int) env('WEBHOOKS_TIMEOUT', 10),
    'connect_timeout' => (int) env('WEBHOOKS_CONNECT_TIMEOUT', 5),

    /*
     * Intentos totales por entrega, contando el primero.
     *
     * Con el backoff de abajo son seis intentos repartidos en algo menos de
     * quince horas: si el receptor no ha vuelto en ese plazo, no es un corte de
     * red sino una integración rota, y seguir golpeándola sólo llena la tabla.
     * Al agotarse, la entrega queda en `exhausted` y se puede reintentar a mano
     * desde la pantalla del endpoint.
     */
    'max_attempts' => (int) env('WEBHOOKS_MAX_ATTEMPTS', 6),

    /*
     * Espera antes de cada reintento, en segundos: 1 m, 5 m, 30 m, 2 h, 12 h.
     *
     * El primer reintento llega en un minuto porque la mayoría de los fallos
     * son un despliegue del receptor o un 502 de su balanceador; el último a
     * las doce horas porque a esas alturas lo que se espera es que alguien
     * arregle algo al día siguiente.
     *
     * La lista se recorre por número de intento fallido; si se agota antes que
     * `max_attempts`, se repite el último valor.
     */
    'backoff' => [60, 300, 1800, 7200, 43200],

    /*
     * Cuántas entregas vencidas barre cada pasada de `webhooks:dispatch`.
     *
     * El scheduler lo corre cada minuto: el tope es lo que evita que una caída
     * larga del receptor produzca una pasada de diez mil peticiones cuando
     * vuelve.
     */
    'dispatch_batch' => (int) env('WEBHOOKS_DISPATCH_BATCH', 100),

    /*
     * Días que se guardan las entregas ya cerradas (`delivered` y `exhausted`)
     * antes de que `webhooks:prune` se las lleve. Las que siguen en juego no se
     * tocan nunca, dure lo que dure su reintento.
     */
    'prune_after_days' => (int) env('WEBHOOKS_PRUNE_DAYS', 30),

    /*
     * Caracteres de `last_error` que se guardan. Un stack trace entero en una
     * columna de auditoría no se lee y sí pesa; los primeros 500 caracteres
     * llevan siempre el mensaje.
     */
    'error_max_length' => 500,

    /*
     * Ventana de la firma, en segundos, para los dos lados: el emisor sella con
     * ella y `VerifyWebhookSignature` rechaza lo que se salga. Cinco minutos es
     * el valor que usan Stripe y GitHub, y aguanta un desfase de reloj
     * razonable sin dejar la puerta abierta a repetir una petición capturada.
     */
    'tolerance_seconds' => (int) env('WEBHOOKS_TOLERANCE', 300),

    /*
     * Secreto del lado RECEPTOR: el que se usa para verificar los webhooks que
     * ESTA instalación recibe de otra (middleware `webhook.signed`).
     *
     * No tiene nada que ver con los secretos de `webhook_endpoints`, que son
     * los del lado emisor y viven cifrados en la base, uno por endpoint. Vacío
     * significa «esta instalación no recibe webhooks»: el middleware devuelve
     * 404 en vez de quedarse abierto por omisión.
     */
    'inbound_secret' => env('WEBHOOKS_INBOUND_SECRET'),

    /*
     * ¿Se exige `https` en la URL de un endpoint?
     *
     * La firma protege el contenido, no la confidencialidad: por `http` el
     * payload viaja legible para cualquiera en el camino. El formulario lo
     * exige salvo en el entorno `local`, donde el receptor suele ser un
     * `php artisan serve` de al lado.
     */
    'require_https' => (bool) env('WEBHOOKS_REQUIRE_HTTPS', true),

    /*
     * ¿Se admite un endpoint que apunte a la red interna?
     *
     * En `false` —el defecto— la URL de un endpoint tiene que resolver a una
     * dirección pública: se rechazan loopback, link-local (la `169.254.169.254`
     * de los metadatos de la nube), las privadas y `0.0.0.0`. Sin eso,
     * cualquiera con `webhooks.manage` convierte el emisor en un lector de los
     * servicios que esta máquina sí alcanza y nadie más
     * (`App\Modules\Webhooks\Rules\PublicHttpUrl`).
     *
     * En `true` se admite cualquier dirección. Es para la instalación donde el
     * receptor está legítimamente dentro —servicios de un clúster privado que
     * se mandan webhooks entre sí— y ahí la comprobación estorba en vez de
     * proteger.
     *
     * **No se apaga sola en `local` ni en `testing`**, a diferencia de
     * `require_https`: si se relajara en desarrollo, el único sitio donde la
     * regla se probaría de verdad sería producción. Los tests que necesitan una
     * URL interna encienden la clave a mano.
     */
    'allow_private_networks' => (bool) env('WEBHOOKS_ALLOW_PRIVATE_NETWORKS', false),

    /*
     * `User-Agent` de las peticiones salientes. Es lo que el receptor ve en su
     * log cuando quiere saber quién le está llamando, así que no se traduce ni
     * se personaliza por instalación.
     */
    'user_agent' => 'kore-laravel-webhooks/1',

    /*
    |--------------------------------------------------------------------------
    | Catálogo de eventos publicables
    |--------------------------------------------------------------------------
    |
    | Nombre → descripción en español. Manda dos cosas:
    |
    |   1. El selector de eventos del formulario de endpoints: lo que un
    |      suscriptor puede elegir es exactamente esto.
    |   2. La validación de `WebhookPublisher::publish()`: un nombre que no esté
    |      aquí lanza `InvalidArgumentException`. Un evento mal escrito no puede
    |      fallar en silencio, porque el suscriptor no recibiría nada y nadie se
    |      enteraría hasta que lo reclamara.
    |
    | El convenio de nombres es `{dominio}.{recurso}.{verbo en pasado}`. Un
    | módulo que quiera publicar los suyos añade la línea aquí y llama a
    | `publish()` dentro de su transacción; ver `docs/modules/webhooks.md`.
    |
    */

    'events' => [
        'auth.api_token.issued' => 'Se emitió un token de API para un usuario',
    ],

];
