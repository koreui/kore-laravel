# Despliegue en VPS con Docker

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

LOG_LEVEL=error
TRUSTED_PROXIES=*

# Toggles del boilerplate (kore-app)
API_ENABLED=true
TENANCY_ENABLED=false

# Auth
AUTH_2FA_ENABLED=true
AUTH_MAGIC_LINKS=true
AUTH_SOCIAL_LOGIN=false

# Observabilidad
SENTRY_LARAVEL_DSN=
SENTRY_TRACES_SAMPLE_RATE=0.1
PULSE_ENABLED=true

# Health: /health/json exige este token en la cabecera X-Secret-Token.
# Si lo dejas vacío, el endpoint queda ABIERTO.
HEALTH_SECRET_TOKEN=<openssl rand -hex 32>
```

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

Las migraciones se ejecutan automáticamente en el arranque del contenedor `app`.

---

## 7. Verificación post-despliegue

```bash
# Headers de seguridad
curl -sI https://tu-dominio.com | grep -iE "x-frame|strict-transport|x-content-type|content-security"

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

El header CSP se inyecta en `docker/nginx/nginx.conf` Y puede ser sobrescrito por el Nginx del host. Si tras editar el primero no se refleja, revisa el server block del host. Después de cambiar `nginx.conf` interno:

```bash
docker compose -f docker-compose.prod.yml restart nginx
curl -sI http://127.0.0.1:8081/login | grep -i content-security-policy
```

### MySQL `unhealthy`

El healthcheck usa `CMD-SHELL` para leer el secret con `$(cat ...)`. Si ves `unhealthy`, revisa que `secrets/db_root_password.txt` exista y `mysqladmin` esté disponible en la imagen.

### Vista de contenedores del proyecto

```bash
docker compose -f docker-compose.prod.yml ps
docker ps --filter "label=com.docker.compose.project=kore-laravel"
```
