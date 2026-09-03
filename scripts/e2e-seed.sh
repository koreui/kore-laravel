#!/usr/bin/env bash
# e2e-seed.sh — Deja la base de pruebas como recién salida de fábrica.
#
#   migrate:fresh  →  E2eSeeder  →  buzón de correo vacío
#
# Se puede correr solo (para reparar la base a media sesión de trabajo) o como
# primer paso de la suite. Ver docs/modules/e2e.md.
set -eu

cd "$(dirname "$0")/.."

ENV_FILE=".env.e2e"

if [ ! -f "$ENV_FILE" ]; then
  echo "✋ No encuentro ${ENV_FILE}. Es un archivo commiteado: recupéralo con \`git checkout ${ENV_FILE}\`." >&2
  exit 1
fi

# El nombre de la base sale de .env.e2e y no de artisan: la salida de tinker
# trae códigos de color y adornos que ensucian la comparación de abajo, y esta
# comprobación es justo la que no puede fallar.
DB="$(grep -E '^DB_DATABASE=' "$ENV_FILE" | tail -1 | cut -d= -f2- | tr -d '\r' | tr -d '"' | tr -d "'" | xargs)"

if [ -z "${DB:-}" ]; then
  echo "✋ No encuentro DB_DATABASE en ${ENV_FILE}." >&2
  exit 1
fi

# El mismo candado que HarnessGuard, aquí arriba y en bash: si alguien cambia
# .env.e2e y apunta a la base de desarrollo, `migrate:fresh` se la lleva por
# delante antes de que ningún PHP tenga ocasión de opinar.
case "$(basename "$DB")" in
  *e2e*|*test*|:memory:) ;;
  *)
    echo "✋ La base de e2e es «${DB}» y no parece de pruebas." >&2
    echo "   Revisa DB_DATABASE en ${ENV_FILE} antes de seguir: migrate:fresh BORRA todo." >&2
    exit 1
    ;;
esac

# SQLite necesita que el archivo exista antes de conectarse. La ruta de
# .env.e2e es relativa a la raíz del proyecto, que es donde estamos.
case "$DB" in
  *.sqlite) mkdir -p "$(dirname "$DB")" && touch "$DB" ;;
esac

echo "▶ Recreando «${DB}»…"
php artisan migrate:fresh \
  --seed \
  --seeder='Database\Seeders\E2eSeeder' \
  --env=e2e \
  --force \
  --no-interaction

echo "▶ Vaciando el buzón de correo…"
mkdir -p storage/logs
: > storage/logs/e2e-mail.log

echo "✔ Base «${DB}» lista."
