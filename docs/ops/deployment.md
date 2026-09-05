# Despliegue en VPS con Docker

**TL;DR**: el stack Docker (Nginx + PHP-FPM + MySQL + Redis + worker + scheduler)
escucha **sólo en `127.0.0.1:8081`**; el Nginx del host pone el TLS y hace
`proxy_pass` hacia ahí. Se despliega con `docker compose -f docker-compose.prod.yml
up -d --build` y los secretos viven en `secrets/`, no en el `.env`. Nada del stack
interno se expone al exterior: ni MySQL, ni Redis, ni el puerto 8081.

Esta guía describe cómo desplegar `kore-laravel` en un VPS usando Docker, con el Nginx del host manejando TLS y proxy_pass al stack interno.

## Arquitectura

```
Internet
  │
  ▼ (80/443 — UFW)
Nginx del HOST  (TLS, Let's Encrypt, proxy_pass → 127.0.0.1:8081)
  │
  ▼ localhost:8081
Nginx Docker    (HTTP, security headers, rate limiting, FastCGI → app:9000)
  │
  ▼ red interna kore_net
PHP-FPM app  ──→  MySQL  (sin puerto al host)
             ──→  Redis  (sin puerto al host)

Queue worker (misma imagen, sin puertos)
Scheduler    (misma imagen, sin puertos)
```

Todo el stack Docker corre detrás de un Nginx en el host que ya tiene TLS configurado para otros proyectos. Sólo se le agrega un server block para `kore-laravel`.

> **Por qué `trustProxies(at: '*')` es seguro aquí.** `bootstrap/app.php` confía
> en las cabeceras `X-Forwarded-*` de **cualquier** proxy. Eso sólo es aceptable
> porque el contenedor de Nginx publica su puerto en `127.0.0.1:8081` y no en
> `0.0.0.0`: la única forma de llegar a la app es a través del Nginx del host,
> que reescribe esas cabeceras. Si publicas el puerto en todas las interfaces, o
> metes el contenedor detrás de un balanceador accesible desde fuera, cualquiera
> podría falsificar su IP con una cabecera y saltarse los rate limiters por IP.
> En ese caso, cambia el `at: '*'` por la lista de IPs de tus proxies.

---

## Requisitos del VPS

- Ubuntu 22.04 LTS o superior
- Docker (`docker compose` v2)
- Nginx en el host con Let's Encrypt configurado
- Dominio apuntando a la IP del VPS

---

## 1. Preparar el servidor (one-time)

### Docker

```bash
curl -fsSL https://get.docker.com | sh
systemctl enable --now docker
usermod -aG docker $USER
newgrp docker
```

### Firewall (UFW)

```bash
ufw default deny incoming
ufw default allow outgoing
ufw allow ssh
ufw allow 80/tcp
ufw allow 443/tcp
ufw enable
```

### SSH endurecido

`/etc/ssh/sshd_config`:

```
PasswordAuthentication no
PermitRootLogin no
PubkeyAuthentication yes
```

```bash
systemctl restart sshd
```

### Fail2ban

```bash
apt install -y fail2ban
```

`/etc/fail2ban/jail.local`:

```ini
[DEFAULT]
bantime  = 3600
findtime = 600
maxretry = 5

[sshd]
enabled = true
maxretry = 3
```

```bash
systemctl enable --now fail2ban
```

### Actualizaciones automáticas de seguridad

```bash
apt install -y unattended-upgrades
dpkg-reconfigure --priority=low unattended-upgrades
```

---

## 2. Clonar el repositorio

```bash
cd /opt
git clone <repo-url> kore-laravel
cd kore-laravel
```

---

## 3. Configurar el entorno

### Secrets de Docker (MySQL)

```bash
mkdir -p secrets
openssl rand -base64 32 > secrets/db_root_password.txt
openssl rand -base64 32 > secrets/db_password.txt
chmod 600 secrets/*.txt

cat secrets/db_password.txt   # copia este valor para DB_PASSWORD en .env
```

### Crear `.env`

```bash
cp .env.example .env
chmod 600 .env
nano .env
```

Valores **obligatorios** para producción:

```env
APP_NAME="kore-laravel"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://tu-dominio.com

DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=kore
DB_USERNAME=kore
DB_PASSWORD=<contenido exacto de secrets/db_password.txt>

SESSION_DRIVER=redis
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true

CACHE_STORE=redis
QUEUE_CONNECTION=redis

REDIS_HOST=redis
REDIS_PASSWORD=<openssl rand -base64 24>

# Logging: a stderr, en JSON, para que lo recoja Docker (ver § Logs)
LOG_CHANNEL=stack
LOG_STACK=stderr
LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter
LOG_LEVEL=warning
TRUSTED_PROXIES=*

# Toggles del boilerplate (kore-app)
API_ENABLED=true
TENANCY_ENABLED=false
# Visor de docs/ en /docs. En producción SIEMPRE apagado: .env.example lo trae
# en true y publicaría la documentación interna a cualquiera que sepa la URL.
DOCS_ENABLED=false

# Auth
AUTH_2FA_ENABLED=true
AUTH_MAGIC_LINKS=true
AUTH_SOCIAL_LOGIN=false
# Sub-toggles por proveedor: con AUTH_SOCIAL_LOGIN=false no pintan nada, pero
# son las otras dos claves de config/kore-app.php y el SocialiteController
# devuelve 404 para un proveedor cuyo sub-toggle esté apagado.
SOCIAL_GOOGLE=false
SOCIAL_GITHUB=false
# Passkeys (WebAuthn). El «relying party id» sale de APP_URL, así que esta
# variable no se toca sola: lee la nota de abajo antes de encenderla.
AUTH_PASSKEYS=true

# Opcional, y sólo si algún día vas a rotar APP_KEY: el user handle de WebAuthn
# se deriva de la clave de la aplicación. Fija este secreto ANTES de rotar y las
# passkeys sobreviven; rota sin fijarlo y quedan todas invalidadas.
# PASSKEYS_USER_HANDLE_SECRET=<openssl rand -base64 32, guardado fuera del VPS>

# Observabilidad
SENTRY_LARAVEL_DSN=
SENTRY_TRACES_SAMPLE_RATE=0.1
PULSE_ENABLED=true

# Health: /health/json exige este token en la cabecera X-Secret-Token.
# Si lo dejas vacío, el endpoint queda ABIERTO.
HEALTH_SECRET_TOKEN=<openssl rand -hex 32>

# Cabeceras (ver «Cabeceras de seguridad y CSP»). Report-only el primer
# despliegue; sin CSP_REPORT_URI nadie ve lo que se bloquearía.
CSP_ENABLED=true
CSP_REPORT_ONLY=true
CSP_REPORT_URI=<endpoint de informes CSP, p. ej. el de Sentry>

# Backups (ver «Backups» más abajo). La contraseña es OBLIGATORIA: sin ella el
# zip va en claro.
BACKUP_ENABLED=true
BACKUP_DISKS=local
BACKUP_ARCHIVE_PASSWORD=<openssl rand -base64 32, guardada fuera del VPS>
BACKUP_NOTIFICATION_MAIL=ops@tu-dominio.com
```

`APP_DEBUG=false` no es una recomendación: desde la v1.3.0 la aplicación **se
niega a arrancar** con `APP_DEBUG=true` y `APP_ENV=production`
(`AppServiceProvider::refuseToBootWithDebugInProduction()`, que lanza un
`RuntimeException` durante el boot). La razón es que la pantalla de error de
Laravel en modo debug vuelca el `.env` entero —`APP_KEY`, credenciales de base
de datos, tokens de terceros— a quien provoque cualquier excepción, y un `.env`
mal copiado no da ninguna otra señal hasta que alguien ve el volcado. Si el
contenedor arranca y muere con ese mensaje en `docker compose logs app`, el
arreglo es poner `APP_DEBUG=false` y volver a cachear la config
(`php artisan config:cache`).

**`APP_URL` es el dominio de las passkeys, y ese dominio es para siempre.**
Con `AUTH_PASSKEYS=true` (el default), `config/fortify.php` deriva de `APP_URL`
el *relying party id* de WebAuthn —el host, sin esquema ni puerto— y la lista de
orígenes permitidos. Tres consecuencias que hay que aceptar antes del primer
despliegue:

1. **Cambiar de dominio invalida todas las passkeys registradas.** Pasar de
   `app.tu-dominio.com` a `tu-dominio.com` no es una migración: es empezar de
   cero, y cada usuario tiene que volver a registrar la suya desde
   `/user/passkeys`. Si el dominio definitivo no está decidido, despliega con
   `AUTH_PASSKEYS=false` y enciéndelo cuando lo esté.
2. **`https://` obligatorio.** WebAuthn sólo funciona en contexto seguro; la
   única excepción es `localhost`. Un `APP_URL` con IP no vale: el relying party
   id tiene que ser un dominio y los navegadores rechazan los literales IP.
3. **Un origen extra se añade en el config, no en el `.env`.** Si sirves el
   mismo sitio con y sin `www`, el origen que no esté en
   `config/fortify.php` → `passkeys.allowed_origins` falla la ceremonia. Va en
   el config por lo mismo que la CSP va en `config/security.php` (R46): así
   aparece en el diff y lo ve el review.

No hay que publicar `config/passkeys.php`: Fortify sobrescribe sus claves desde
su propio `register()`, y un archivo publicado sólo serviría para mentir sobre
lo que está activo.

### Generar `APP_KEY`

```bash
docker compose -f docker-compose.prod.yml run --rm \
  --no-deps \
  -e DB_CONNECTION=sqlite \
  app php artisan key:generate --show
```

Copia el output (`base64:xxx...`) a `APP_KEY=` en `.env`.

---

## 4. Configurar el Nginx del host

`/etc/nginx/sites-available/kore-laravel`:

```nginx
server {
    listen 80;
    server_name tu-dominio.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    server_name tu-dominio.com;

    ssl_certificate     /etc/letsencrypt/live/tu-dominio.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/tu-dominio.com/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_session_cache   shared:SSL:10m;
    ssl_session_timeout 1d;

    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    location / {
        proxy_pass         http://127.0.0.1:8081;
        proxy_set_header   Host              $host;
        # X-Real-IP es usado por el Nginx interno para rate limiting por IP real
        proxy_set_header   X-Real-IP         $remote_addr;
        proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;
        proxy_read_timeout 60s;
        proxy_buffer_size  128k;
        proxy_buffers      4 256k;
        # Debe coincidir con client_max_body_size del Nginx interno (50M)
        client_max_body_size 50M;
    }
}
```

```bash
ln -s /etc/nginx/sites-available/kore-laravel /etc/nginx/sites-enabled/kore-laravel
nginx -t && systemctl reload nginx
```

Si el dominio no tiene certificado:

```bash
certbot --nginx -d tu-dominio.com
```

---

## 5. Construir y levantar

```bash
cd /opt/kore-laravel

export GIT_SHA=$(git rev-parse --short HEAD)
docker compose -f docker-compose.prod.yml build app

docker compose -f docker-compose.prod.yml up -d
```

El entrypoint corre automáticamente `migrate --force`, sincroniza assets compilados al volumen compartido y calienta los caches antes de levantar PHP-FPM. El orden DB → Redis → app → queue/scheduler lo gestiona `depends_on`.

### Verificar arranque

```bash
docker compose -f docker-compose.prod.yml logs -f app
docker compose -f docker-compose.prod.yml logs -f nginx
docker compose -f docker-compose.prod.yml ps
```

### Sembrar datos iniciales (sólo primera vez, opcional)

```bash
docker compose -f docker-compose.prod.yml exec app php artisan db:seed --force
```

---

## 6. Despliegue de nuevas versiones

```bash
cd /opt/kore-laravel
git pull origin main

export GIT_SHA=$(git rev-parse --short HEAD)
docker compose -f docker-compose.prod.yml build app

docker compose -f docker-compose.prod.yml up -d --no-deps app queue scheduler

# El nginx interno usa bind mount; up -d no lo reinicia aunque cambie nginx.conf
docker compose -f docker-compose.prod.yml restart nginx
```

El entrypoint del contenedor `app` hace, en este orden: sincronizar los assets,
`migrate --force`, calentar los caches (`config`, `route`, `view`) y, desde la
v1.3.0, **avisar a los workers** con `queue:restart`.

`queue:restart` deja una marca de tiempo en la caché (Redis en producción). Los
workers la miran entre job y job: terminan el que tienen entre manos y salen
limpiamente, en vez de que un `docker restart` les corte una ejecución a la
mitad. Es la pieza que cierra la ventana entre «las migraciones ya están
aplicadas» y «el worker todavía tiene el esquema viejo en memoria».

Lo que `queue:restart` **no** hace es cambiar el código del worker: el
contenedor `queue` se levanta con la imagen con la que fue creado, así que
`queue` y `scheduler` siguen en el `up -d --no-deps` de arriba. Con la imagen
reconstruida, `up -d` los recrea; sin él, el worker reaparece con la versión
anterior.

Cuándo hace falta tocarlos a mano:

| Situación | Qué hacer |
|-----------|-----------|
| Código nuevo (el caso normal) | Nada: `up -d --no-deps app queue scheduler` ya los recrea |
| Sólo migraciones o sólo config cacheada en `app` | Nada: el `queue:restart` del entrypoint basta |
| Cambió el `.env` | `docker compose -f docker-compose.prod.yml restart queue scheduler` — leen su `env_file` al arrancar y cachean la config en su propio entrypoint |

---

## 7. Verificación post-despliegue

```bash
# Headers de seguridad (los emite la app, no Nginx: ver «Cabeceras de seguridad y CSP»)
curl -sI https://tu-dominio.com | grep -iE "content-security|strict-transport|x-frame|x-content-type|referrer-policy|permissions-policy"

# En el primer despliegue debe salir Content-Security-Policy-REPORT-ONLY.
# Si sale Content-Security-Policy a secas, alguien ya puso CSP_REPORT_ONLY=false.
# Si salen las dos, hay una CSP colada en el Nginx del host: quítala.
curl -sI https://tu-dominio.com | grep -ci "content-security-policy"   # debe ser 1

# Las rutas de passkeys existen y el relying party id es el dominio real
docker compose -f docker-compose.prod.yml exec app php artisan route:list --name=passkey
docker compose -f docker-compose.prod.yml exec app php artisan config:show fortify.passkeys

# .env y vendor/ no accesibles (deben retornar 404)
curl -s -o /dev/null -w "%{http_code}\n" https://tu-dominio.com/.env
curl -s -o /dev/null -w "%{http_code}\n" https://tu-dominio.com/vendor/autoload.php

# MySQL no acepta conexiones externas (debe fallar)
nc -zv <IP-del-VPS> 3306

# Redis no acepta conexiones externas (debe fallar)
nc -zv <IP-del-VPS> 6379

# Endpoint health (spatie/laravel-health). /health/json exige el token;
# /health es HTML y pide sesión + rol superadmin.
curl -s -H "X-Secret-Token: $HEALTH_SECRET_TOKEN" https://tu-dominio.com/health/json | jq .
```

---

## Cabeceras de seguridad y CSP

### Quién pone qué

Desde la v1.3.0 las cabeceras las emite **la aplicación**, no el hosting:
`config/security.php` define la política y `App\Http\Middleware\SecurityHeaders`
la añade a toda respuesta del grupo `web`. Así viajan con el código, se prueban
en `tests/Feature/SecurityHeadersTest.php` y funcionan igual en este Docker, en
Forge, en Laravel Cloud o en un `artisan serve`.

| Cabecera | La emite | Dónde se cambia |
|----------|----------|-----------------|
| `Content-Security-Policy` (o `…-Report-Only`) | Sólo la app | `config/security.php` |
| `Strict-Transport-Security` | Sólo la app, y sólo sobre HTTPS | `config/security.php` + `SECURITY_HSTS` |
| `X-Frame-Options` | App + Nginx | `config/security.php` |
| `X-Content-Type-Options` | App + Nginx | `config/security.php` |
| `Referrer-Policy` | App + Nginx | `config/security.php` |
| `Permissions-Policy` | App + Nginx | `config/security.php` |
| `Cross-Origin-Opener-Policy` | Sólo la app | `config/security.php` |
| `X-XSS-Protection` | Sólo Nginx | `docker/nginx/nginx.conf` |

Las cuatro que están duplicadas lo están a propósito: Nginx sirve directamente
`/build/`, las fuentes y las imágenes sin pasar por PHP, y esas respuestas no
ven ningún middleware. Duplicar un valor idéntico no rompe nada —el navegador
recibe la misma cabecera dos veces con el mismo valor—; lo que sí rompería es
duplicar la **CSP**, porque el navegador aplicaría las dos políticas a la vez y
ganaría siempre la intersección más restrictiva. Por eso la CSP de
`nginx.conf` se retiró: ahora sale sólo de la app.

`X-XSS-Protection` se queda únicamente en Nginx: el filtro XSS de los
navegadores está retirado desde Chrome 78 y llegó a abrir sus propios agujeros.
La aplicación no la emite.

### Desplegar la CSP sin romper la aplicación

Una CSP mal calibrada no da un error visible: deja de cargarse un script y la
página parece «rara». La receta es estrenarla en modo informe.

1. **Report-only con destino.** Primer despliegue con
   `CSP_ENABLED=true`, `CSP_REPORT_ONLY=true` y `CSP_REPORT_URI` apuntando a un
   recolector: Sentry lo da hecho en *Settings → Security Headers* del proyecto
   (`https://oXXX.ingest.sentry.io/api/XXX/security/?sentry_key=…`). Sin
   `CSP_REPORT_URI` el modo informe no sirve de nada: el navegador se queja en
   su consola y nadie lo ve.

   ```env
   CSP_ENABLED=true
   CSP_REPORT_ONLY=true
   CSP_REPORT_URI=https://oXXX.ingest.sentry.io/api/XXX/security/?sentry_key=XXX
   ```

2. **Revisar los informes unos días**, con tráfico real y pasando por todas las
   pantallas (login, 2FA, magic link, tablas, subidas). Cada violación dice qué
   directiva y qué origen. Hay dos respuestas posibles: el origen es legítimo y
   se añade a `config/security.php`, o no lo es y acabas de encontrar algo.

   Ojo con el ruido: las extensiones del navegador generan violaciones que no
   son tuyas (`chrome-extension:`, `moz-extension:`). Se ignoran.

3. **Pasar a bloqueo** cuando el informe esté limpio:

   ```env
   CSP_REPORT_ONLY=false
   ```

   `CSP_REPORT_URI` se deja puesto: en modo bloqueo sigue informando de lo que
   bloquea, que es cuando más interesa.

Después de cada cambio, recargar la config (ver «Recargar config tras cambiar
`.env`») y comprobar:

```bash
curl -sI https://tu-dominio.com | grep -i content-security-policy
```

### Añadir un origen nuevo

Se edita **`config/security.php`**, nunca el Nginx. Por ejemplo, para un script
de analítica y su endpoint:

```php
'script-src' => ["'self'", "'unsafe-inline'", "'unsafe-eval'", 'https://cdn.ejemplo.com'],
'connect-src' => ["'self'", 'wss:', 'https://api.ejemplo.com'],
```

Y después:

```bash
docker compose -f docker-compose.prod.yml exec app php artisan config:cache
```

Ventaja de tenerlo en el config: el cambio va en el mismo commit que el código
que lo necesita, pasa por review y lo cubre `SecurityHeadersTest`. Si estuviera
en `nginx.conf`, la política y el código que la necesita viajarían por caminos
distintos y se desincronizarían en el primer despliegue.

`'unsafe-inline'` y `'unsafe-eval'` en `script-src` no son un descuido: Livewire
4 y Alpine los necesitan. Cuando Alpine tenga build compatible con CSP se
quitan, y ese día lo dirá `SecurityHeadersTest`.

---

## Logs

Los contenedores no escriben logs en disco: escriben en **stderr** y los recoge
el runtime de Docker. Un `LOG_STACK=single` dentro del contenedor mandaría los
logs a `storage/logs/laravel.log`, que vive en la capa efímera de la imagen —se
pierde en cada `up -d --build`— y que `docker compose logs` no ve.

Receta de `.env` para producción:

```env
LOG_CHANNEL=stack
LOG_STACK=stderr
LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter
LOG_LEVEL=warning
```

Con `LOG_STACK=stderr,sentry` los mismos registros van además a Sentry a partir
de `LOG_SENTRY_LEVEL` (por defecto `error`).

`LOG_STDERR_FORMATTER` es un **nombre de clase** de Monolog, no un booleano: si
lo dejas vacío el canal sigue funcionando, pero con el `LineFormatter` de texto
plano. Es el fallo silencioso típico —nadie se entera hasta que el agregador de
logs no encuentra un solo campo—, y por eso lo cubre
`tests/Feature/LoggingTest.php`.

### Leerlos

```bash
docker compose -f docker-compose.prod.yml logs -f app
docker compose -f docker-compose.prod.yml logs -f --tail=100 queue scheduler

# Con JsonFormatter, una línea es un objeto JSON:
docker compose -f docker-compose.prod.yml logs --no-log-prefix app | jq -r 'select(.level_name=="ERROR") | .message'
```

`catch_workers_output = yes` en `docker/php/www.conf` hace que los errores del
propio PHP (fatales, warnings de arranque) salgan también por ahí, sin
decorar, en vez de perderse en el log interno de FPM.

### Rotación

Los seis servicios comparten el ancla `x-logging` de `docker-compose.prod.yml`:

```yaml
x-logging: &default-logging
  driver: json-file
  options:
    max-size: "10m"
    max-file: "5"
```

Sin ella, el driver `json-file` de Docker **no rota nada**: el archivo de
`/var/lib/docker/containers/<id>/<id>-json.log` crece hasta llenar el disco del
VPS. Con esta configuración el techo es 50 MB por contenedor (10 MB × 5), 300 MB
para el stack entero. Si necesitas más historial, ese es el sitio: no subas
`LOG_LEVEL`.

---

## Healthchecks

`depends_on: condition: service_healthy` encadena el arranque, así que un
healthcheck que miente es un stack que arranca en el orden equivocado.

| Servicio | Qué ejecuta | Qué demuestra |
|----------|-------------|---------------|
| `db` | `mysqladmin ping` con el root password del secret | MySQL acepta conexiones y las credenciales del secret son las buenas |
| `redis` | `redis-cli -a $REDIS_PASSWORD ping \| grep PONG` | Redis responde **y** la contraseña de `.env` coincide con la del `--requirepass` |
| `app` | `cgi-fcgi` contra `127.0.0.1:9000` pidiendo `/ping` | PHP-FPM está escuchando en el socket y tiene un worker libre que contesta `pong` |
| `nginx` | `wget -qO- http://127.0.0.1/up` | La cadena entera: Nginx → FastCGI → PHP-FPM → Laravel (`/up` es la ruta de salud que declara `bootstrap/app.php`) |

Los dos de la app son nuevos en la v1.3.0:

- **`app`** comprobaba antes `php -r "echo 'OK';"`, que sólo demuestra que el
  binario de PHP existe dentro del contenedor. Con FPM caído o con el pool
  agotado seguía diciendo `healthy`, y `queue`, `scheduler` y `nginx` —que
  dependen de él— arrancaban contra una app muerta. Ahora habla FastCGI de
  verdad:

  ```yaml
  test: ["CMD-SHELL", "SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 | grep -q pong"]
  ```

  `cgi-fcgi` lo trae el paquete `fcgi` de Alpine, que el `Dockerfile` instala; el
  `ping.path = /ping` y el `ping.response = pong` los declara
  `docker/php/www.conf`.

- **`nginx`** no tenía ninguno. El suyo atraviesa toda la pila, así que se pone
  en rojo tanto si se cae Nginx como si se cae la app. Usa el `wget` de busybox,
  que `nginx:alpine` ya trae; `interval: 30s` porque es el más caro de los cuatro
  (arranca PHP) y `start_period: 20s` para no contar los intentos de mientras
  Nginx todavía está leyendo su config.

### Verlos

```bash
docker compose -f docker-compose.prod.yml ps
docker inspect --format '{{json .State.Health}}' kore-laravel-app-1 | jq .
```

### Probarlos a mano

```bash
# app — desde dentro del contenedor
docker compose -f docker-compose.prod.yml exec app \
  sh -c 'SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000'

# nginx — desde dentro del contenedor
docker compose -f docker-compose.prod.yml exec nginx wget -qO- http://127.0.0.1/up

# el mismo /up desde el host
curl -s http://127.0.0.1:8081/up | head -c 100
```

No confundas estos healthchecks con `spatie/laravel-health`: aquéllos son para
Docker («¿puedo mandarle tráfico a este contenedor?») y `/health` es para ti
(«¿está sana la aplicación?»). Ver [`observability.md`](observability.md).

---

## PHP-FPM

El pool está en **`docker/php/www.conf`** y el `Dockerfile` lo copia a
`/usr/local/etc/php-fpm.d/zzz-kore.conf`. El nombre importa: la imagen
`php:8.4-fpm-alpine` incluye `php-fpm.d/*.conf` en orden alfabético y trae ya
`www.conf` (el pool por defecto) y `zz-docker.conf` (que fija `listen = 9000`).
Como `zzz-kore.conf` se lee el último, sus directivas ganan sobre las dos.
Copiarlo *encima* de `www.conf` funcionaría, pero dejaría `zz-docker.conf`
pisando el `listen` por detrás.

```ini
pm = dynamic
pm.max_children = 20
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 6
pm.max_requests = 500
request_terminate_timeout = 60s
clear_env = no
catch_workers_output = yes
```

### Cómo dimensionar `pm.max_children`

Es el número máximo de peticiones PHP simultáneas, y el que decide cuánta RAM
puede consumir el contenedor:

```
pm.max_children ≈ RAM disponible para PHP / RAM por worker (~40 MB)
```

En un VPS de 2 GB con MySQL y Redis al lado, quedan ~1 GB para PHP: 1024 / 40 ≈
25, y se dejan **20** para tener margen. Mídelo en tu caso en vez de heredar el
número:

```bash
docker compose -f docker-compose.prod.yml exec app \
  sh -c "ps -o rss,comm -C php-fpm | awk '{s+=\$1; n++} END {print s/n/1024 \" MB por worker\"}'"
```

Si `pm.max_children` se queda corto, FPM lo dice en el log del contenedor
(«server reached pm.max_children setting, consider raising it») y las peticiones
esperan en la cola del socket. Si te pasas, el kernel empieza a matar procesos:
es peor. Sube antes la RAM del VPS que el número.

### Las otras directivas

- **`pm.max_requests = 500`** — recicla el worker cada 500 peticiones. Corta en
  seco cualquier fuga de memoria de una extensión o de un paquete de vendor.
- **`request_terminate_timeout = 60s`** — una petición que pasa de un minuto en
  producción está colgada, no lenta. Debe ser mayor que el `max_execution_time`
  de PHP para que el error lo reporte PHP (y lo vea Sentry) antes de que FPM
  mate el worker.
- **`clear_env = no`** — los workers heredan el entorno del contenedor
  (`env_file: .env`). La config va cacheada (`config:cache` en el entrypoint),
  pero sin esto cualquier `$_ENV` de un paquete de vendor leería vacío.
- **`ping.path` / `pm.status_path`** — los usa el healthcheck del servicio `app`
  (ver § Healthchecks). `/status` no está expuesto por Nginx: sólo se consulta
  desde dentro del contenedor.

Después de tocar `www.conf` hay que **reconstruir la imagen**, no basta con
reiniciar: el archivo se copia en el build.

```bash
docker compose -f docker-compose.prod.yml build app
docker compose -f docker-compose.prod.yml up -d --no-deps app
```

---

## Backups (spatie/laravel-backup)

Un backup es un zip cifrado con **el dump de la base de datos** y **el contenido
de `storage/app`**. El código no entra: vive en git y en la imagen. Lo
irrecuperable es lo que suben los usuarios y lo que hay en la base.

Todo está detrás del toggle `BACKUP_ENABLED`. Con el toggle apagado —el default
del boilerplate— el provider del paquete ni se registra: no existen los comandos
`backup:*`, ni el check de `/health`, ni las entradas del scheduler.

### Variables de `.env`

```env
# Backups
BACKUP_ENABLED=true

# Destino. Lista separada por comas; los mismos discos que vigila el monitor.
# Deja `local` el primero: es el disco que el check de /health abre al arrancar.
# Para `s3`: composer require league/flysystem-aws-s3-v3 + las AWS_* de config/filesystems.php
BACKUP_DISKS=local
# BACKUP_DISKS=local,s3

# OBLIGATORIA en producción. Sin ella el zip va en claro y cualquiera que llegue
# al volumen o al bucket se lleva la base de datos entera.
BACKUP_ARCHIVE_PASSWORD=<openssl rand -base64 32>

# A dónde van los avisos. Vacío = MAIL_FROM_ADDRESS.
BACKUP_NOTIFICATION_MAIL=ops@tu-dominio.com

# Opcionales
# BACKUP_NAME=kore-laravel          # carpeta dentro de cada disco (default: APP_NAME)
# BACKUP_MAX_AGE_DAYS=1             # días sin backup nuevo antes de declararlo no sano
# BACKUP_DUMP_EXTRA_OPTION=--skip-ssl
```

> ⚠️ **Guarda `BACKUP_ARCHIVE_PASSWORD` fuera del servidor** (gestor de
> contraseñas, no el mismo VPS). Si se pierde, los zips son irrecuperables: el
> cifrado es AES-256 y no hay puerta trasera. Y si cambias la contraseña, los
> backups anteriores siguen pidiendo la vieja.

Tras editar `.env`:

```bash
docker compose -f docker-compose.prod.yml exec app php artisan config:cache
docker compose -f docker-compose.prod.yml restart app queue scheduler
```

### Qué programa el scheduler

Las tres entradas viven en `routes/console.php`, dentro de un `if` sobre
`config('kore-app.backup.enabled')`, y las dispara el servicio `scheduler` de
`docker-compose.prod.yml`.

| Hora    | Comando          | Qué hace |
|---------|------------------|----------|
| `01:00` | `backup:clean`   | aplica la política de retención (7 días completos, 16 diarios, 8 semanales, 4 mensuales, 2 anuales, tope de 5000 MB) |
| `02:00` | `backup:run`     | dump + zip cifrado a cada disco de `BACKUP_DISKS` (`withoutOverlapping`: un dump largo no arranca dos veces) |
| `03:00` | `backup:monitor` | comprueba edad y tamaño del último backup y avisa por correo si no está sano |

Se limpia **antes** de hacer el backup del día, para que quepa.

Verifica que están programados:

```bash
docker compose -f docker-compose.prod.yml exec app php artisan schedule:list | grep backup
```

### Dónde caen los zips

El disco `local` tiene su raíz en `storage/app/private`, y el paquete crea
dentro una carpeta con el nombre del backup:

```
/var/www/html/storage/app/private/<BACKUP_NAME>/2026-09-03-02-00-00.zip
```

Eso está en el volumen **`storage_data`**, montado en `app`, `queue` y
`scheduler`. Es decir: sobrevive a `docker compose down` y a un redeploy, pero
**está en la misma máquina que la base de datos**. Un backup que sólo vive en el
mismo VPS no protege del incendio del VPS: para eso está `BACKUP_DISKS=local,s3`.

Dentro del zip:

```
db-dumps/mysql-<DB_DATABASE>.sql
var/www/html/storage/app/public/...
var/www/html/storage/app/private/...
```

(Rutas absolutas sin la barra inicial; los backups anteriores quedan fuera
gracias al `exclude` de `config/backup.php`.)

### Listar y lanzar a mano

```bash
# Qué hay, en qué disco, de qué fecha y tamaño
docker compose -f docker-compose.prod.yml exec app php artisan backup:list

# Un backup completo ahora mismo
docker compose -f docker-compose.prod.yml exec app php artisan backup:run

# Sólo la base de datos / sólo los archivos
docker compose -f docker-compose.prod.yml exec app php artisan backup:run --only-db
docker compose -f docker-compose.prod.yml exec app php artisan backup:run --only-files

# Comprobar salud sin esperar a las 03:00
docker compose -f docker-compose.prod.yml exec app php artisan backup:monitor
```

---

## Restore paso a paso

Un backup que no se ha restaurado nunca no es un backup, es una carpeta.
**Ensaya esto en staging al menos una vez.** Los pasos asumen que estás en el
directorio del repo en el VPS, con `docker-compose.prod.yml` a mano.

### 1. Elegir el backup y sacarlo del contenedor

```bash
docker compose -f docker-compose.prod.yml exec app php artisan backup:list

# Copiar el zip elegido al host
docker compose -f docker-compose.prod.yml cp \
  app:/var/www/html/storage/app/private/kore-laravel/2026-09-03-02-00-00.zip \
  ./restore.zip
```

Si el backup está en S3, bájalo del bucket en vez de copiarlo del contenedor.

### 2. Descifrar el zip

El zip va cifrado con **AES-256**, y eso descarta `unzip`: Info-ZIP 6.0 no
soporta AES (`skipping: ... need PK compat. v5.1`), así que `unzip -P` **no
sirve**. Dos opciones que sí funcionan:

```bash
# a) Con PHP, dentro del propio contenedor (no requiere instalar nada)
docker compose -f docker-compose.prod.yml cp ./restore.zip app:/tmp/restore.zip
docker compose -f docker-compose.prod.yml exec app php -r '
$z = new ZipArchive;
$z->open("/tmp/restore.zip");
$z->setPassword(getenv("BACKUP_ARCHIVE_PASSWORD"));
var_dump($z->extractTo("/tmp/restore"));
'

# b) Con 7-Zip en el host (apt install p7zip-full)
7z x -p"$BACKUP_ARCHIVE_PASSWORD" restore.zip -o./restore
```

`extractTo` devolviendo `false` casi siempre significa contraseña incorrecta.

### 3. Restaurar la base de datos

El dump lo genera `mariadb-dump` (paquete `mysql-client` de Alpine, instalado en
el `Dockerfile`). **Las versiones 11.4+ escriben como primera línea**

```sql
/*!999999\- enable the sandbox mode */
```

que el cliente `mysql` 8.4 del contenedor `db` **rechaza** con un error de
sintaxis desconcertante. Hay que quitarla antes de importar:

```bash
cd restore/db-dumps
sed -i '1{/enable the sandbox mode/d}' mysql-kore.sql

# Importar (usa el root del secret; -T porque no hay TTY)
docker compose -f docker-compose.prod.yml exec -T db \
  mysql -u root -p"$(cat ../../secrets/db_root_password.txt)" "$DB_DATABASE" \
  < mysql-kore.sql
```

Si prefieres partir de cero, borra y recrea el esquema antes de importar:

```bash
docker compose -f docker-compose.prod.yml exec -T db \
  mysql -u root -p"$(cat secrets/db_root_password.txt)" \
  -e "DROP DATABASE \`$DB_DATABASE\`; CREATE DATABASE \`$DB_DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### 4. Restaurar `storage/app`

Dentro del zip los archivos están en `var/www/html/storage/app/...`. Se copian
de vuelta al volumen `storage_data` a través del contenedor `app`:

```bash
docker compose -f docker-compose.prod.yml cp \
  ./restore/var/www/html/storage/app/. \
  app:/var/www/html/storage/app/

docker compose -f docker-compose.prod.yml exec app \
  chown -R www-data:www-data /var/www/html/storage/app
```

### 5. Levantar y verificar

```bash
docker compose -f docker-compose.prod.yml exec app php artisan config:cache
docker compose -f docker-compose.prod.yml restart app queue scheduler

# ¿Migraciones al día respecto al código desplegado?
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force

# ¿El monitor ve un backup reciente y sano?
docker compose -f docker-compose.prod.yml exec app php artisan backup:monitor

# ¿Y el resto de checks? (BackupsCheck sale en esta lista)
curl -s -H "X-Secret-Token: $HEALTH_SECRET_TOKEN" https://tu-dominio.com/health/json | jq .
```

Por último, borra los restos: `rm -rf restore restore.zip` en el host y
`/tmp/restore*` en el contenedor. Un dump sin cifrar en `/tmp` es exactamente el
agujero que el zip cifrado venía a tapar.

### Troubleshooting del restore

| Síntoma | Causa |
|---------|-------|
| `unzip: need PK compat. v5.1` | Info-ZIP no descifra AES-256. Usa PHP o `7z` (paso 2). |
| `extractTo` devuelve `false` | `BACKUP_ARCHIVE_PASSWORD` no coincide con la del día del backup. |
| `ERROR 1064 ... near '\-'` al importar | No quitaste la línea del *sandbox mode* (paso 3). |
| `The dump process failed` en `backup:run` | Falta `mariadb-dump` en la imagen, o el dump no puede negociar TLS: revisa `BACKUP_DUMP_EXTRA_OPTION`. |
| `Driver [s3] is not supported` al arrancar | `BACKUP_DISKS` incluye `s3` sin `league/flysystem-aws-s3-v3` instalado. |

---

## PDF con Gotenberg (perfil `pdf`)

**TL;DR**: el motor de PDF es un contenedor aparte que sólo arranca con
`--profile pdf`. Sin él, `PDF_ENABLED=true` no genera nada.

```bash
docker compose -f docker-compose.prod.yml --profile pdf up -d
docker compose -f docker-compose.prod.yml exec gotenberg curl -sf http://localhost:3000/health
```

En el `.env` de producción:

```dotenv
PDF_ENABLED=true
GOTENBERG_URL=http://gotenberg:3000   # el NOMBRE del servicio en kore_net
```

Tres cosas que conviene tener claras antes de encenderlo:

- **No publica puertos, y no es un descuido.** Gotenberg convierte el HTML que
  le manden: expuesto a internet es un renderizador de páginas gratis para
  cualquiera, y desde dentro de tu red. Sólo lo alcanzan los servicios de
  `kore_net`.
- **Las imágenes de una hoja van embebidas, no enlazadas.** Gotenberg corre en
  su propio contenedor: un `<img src="https://tu-dominio/logo.png">` lo obliga a
  salir a la red y a volver a entrar, y en local directamente se lo pide a sí
  mismo. Lo resuelve `App\Core\Support\PdfImage`; el detalle está en
  [`../modules/pdf.md`](../modules/pdf.md).
- **El perfil también apaga.** `docker compose up -d` sin `--profile pdf` no
  arranca el servicio y **no lo para** si ya estaba: para retirarlo,
  `docker compose --profile pdf down gotenberg`.

### Convertir DOCX, XLSX y PPTX a PDF

La misma imagen trae LibreOffice, así que el contenedor que ya está levantado
convierte también documentos de Office en `POST /forms/libreoffice/convert`. El
boilerplate **no** lo implementa —no tiene un caso de uso que lo pida y una
Action sin consumidor es código muerto—, pero deja instalado
`gotenberg/gotenberg-php`, que es el cliente oficial. En un proyecto derivado la
Action sería así:

```php
use Gotenberg\Gotenberg;
use Gotenberg\Stream;

// En una Action del módulo dueño del documento, nunca en un controller (R22).
$request = Gotenberg::libreOffice((string) config('laravel-pdf.gotenberg.url'))
    ->convert(Stream::path($rutaDelDocx));

$pdf = Gotenberg::send($request)->getBody()->getContents();
```

`Gotenberg::send()` habla HTTP con el mismo servicio que usa spatie/laravel-pdf,
así que no hay una segunda URL que configurar ni un segundo contenedor que
levantar. Lo que sí cambia es el tiempo: una conversión de LibreOffice tarda
bastante más que una de Chromium, así que va en cola (un Listener
`implements ShouldQueue` que reacciona a un evento del módulo) y no en la
petición.

---

## Activar multi-tenancy en producción

```bash
docker compose -f docker-compose.prod.yml exec app php artisan kore:tenancy:enable
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
docker compose -f docker-compose.prod.yml restart app queue scheduler
```

Editar `.env` para confirmar `TENANCY_ENABLED=true`. El modo single-db / multi-db
no es una variable de entorno: se decide en los `bootstrappers` de `config/tenancy.php`
(ver [`../modules/tenancy.md`](../modules/tenancy.md)).

---

## Troubleshooting

### Comandos artisan dentro del contenedor

```bash
docker compose -f docker-compose.prod.yml exec app php artisan <comando>
```

### Recargar config tras cambiar `.env`

```bash
docker compose -f docker-compose.prod.yml exec app php artisan config:cache
docker compose -f docker-compose.prod.yml exec app php artisan route:cache
docker compose -f docker-compose.prod.yml restart queue scheduler
```

> `restart queue scheduler` hace falta aquí y sólo aquí. En un despliegue normal
> el entrypoint del contenedor `app` ya corre `queue:restart`, y los workers se
> reciclan solos entre job y job.

### Jobs fallidos

```bash
docker compose -f docker-compose.prod.yml exec app php artisan queue:failed
docker compose -f docker-compose.prod.yml exec app php artisan queue:retry all
```

### Reiniciar un servicio

```bash
docker compose -f docker-compose.prod.yml restart app
docker compose -f docker-compose.prod.yml logs -f app
```

### CSP bloquea recursos externos

La CSP la emite la aplicación (`config/security.php` → `SecurityHeaders`), no
Nginx. Si un recurso se bloquea, añade su origen a la directiva que toque en
ese config y vuelve a cachear (ver «Cabeceras de seguridad y CSP»). Si en la
respuesta salen **dos** cabeceras `Content-Security-Policy`, hay una colada en
el Nginx del host: quítala, porque el navegador aplica la intersección.

```bash
docker compose -f docker-compose.prod.yml exec app php artisan config:cache
curl -sI http://127.0.0.1:8081/login | grep -i content-security-policy
```

### MySQL `unhealthy`

El healthcheck usa `CMD-SHELL` para leer el secret con `$(cat ...)`. Si ves `unhealthy`, revisa que `secrets/db_root_password.txt` exista y `mysqladmin` esté disponible en la imagen.

### Vista de contenedores del proyecto

```bash
docker compose -f docker-compose.prod.yml ps
docker ps --filter "label=com.docker.compose.project=kore-laravel"
```
