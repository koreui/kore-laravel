#!/usr/bin/env bash
# e2e.sh — La suite E2E de punta a punta, en un comando.
#
#   1) comprueba que el puerto de E2E esté libre (o mata el `artisan serve`
#      huérfano que lo ocupe)
#   2) compila los assets si falta el manifest de Vite
#   3) siembra la base con scripts/e2e-seed.sh, si existe
#   4) corre Playwright (los argumentos extra se pasan tal cual)
#
# Uso:
#   npm run e2e                            # lo normal
#   bash scripts/e2e.sh --headed           # con ventana
#   bash scripts/e2e.sh --ui               # panel interactivo
#   bash scripts/e2e.sh tests/e2e/specs/users
#   bash scripts/e2e.sh --repeat-each=2    # caza de flakiness
#
#   E2E_SKIP_SEED=1 bash scripts/e2e.sh    # sin resembrar (iterar rápido)
#   E2E_BUILD=1     bash scripts/e2e.sh    # fuerza `npm run build`
#   E2E_PORT=8011   bash scripts/e2e.sh    # otro puerto (ajusta .env.e2e también)
#
# El puerto sale de APP_URL en .env.e2e, que es la misma fuente que lee
# `tests/e2e/support/env.ts`: una sola verdad, y no dos que se desincronizan.
set -eu

cd "$(cd "$(dirname "$0")/.." && pwd)"

if [ ! -f .env.e2e ]; then
  echo "✋ No existe .env.e2e. Es un archivo commiteado: recupéralo con \`git checkout .env.e2e\`." >&2
  exit 1
fi

APP_URL_E2E="$(grep -E '^APP_URL=' .env.e2e | tail -1 | cut -d= -f2- | tr -d '\r"'"'" | xargs)"
PORT="${E2E_PORT:-$(printf '%s' "$APP_URL_E2E" | sed -n 's#.*:\([0-9][0-9]*\)/*$#\1#p')}"
PORT="${PORT:-8010}"

# ── 1) Puerto ───────────────────────────────────────────────────────────────
# Playwright reutiliza el servidor que encuentre (`reuseExistingServer`), y eso
# sólo sirve si el que hay es EL nuestro. Un `artisan serve` huérfano de una
# corrida anterior arrastra la conexión a una SQLite que este script está a
# punto de borrar; cualquier otro proceso daría fallos que no se parecen en
# nada a su causa.
#
# `lsof` no está en todas las imágenes de CI: si falta, se salta la
# comprobación en vez de fallar por ella.
#
# Ojo con QUIÉN escucha: `artisan serve` no abre el socket él, lo abre el
# servidor embebido de PHP que lanza como hijo. En `ps` eso se ve así:
#
#   php -S localhost:8010 …/Illuminate/Foundation/Console/../resources/server.php
#
# Así que el patrón tiene que reconocer ese `server.php`, no la palabra
# «serve». Se baja también el padre, o `artisan serve` levantaría otro hijo.
if command -v lsof >/dev/null 2>&1; then
  OCUPANTES="$(lsof -nP -iTCP:"$PORT" -sTCP:LISTEN -t 2>/dev/null || true)"

  for PID in $OCUPANTES; do
    COMANDO="$(ps -o command= -p "$PID" 2>/dev/null || true)"

    case "$COMANDO" in
      *artisan*serve*|*Foundation/Console*resources/server.php*)
        PADRE="$(ps -o ppid= -p "$PID" 2>/dev/null | tr -d ' ' || true)"
        PADRE_CMD="$(ps -o command= -p "${PADRE:-0}" 2>/dev/null || true)"

        echo "▶ Puerto $PORT ocupado por un servidor de Laravel huérfano (pid $PID): lo bajo."

        case "$PADRE_CMD" in
          *artisan*serve*) kill "$PADRE" 2>/dev/null || true ;;
        esac

        kill "$PID" 2>/dev/null || true
        ;;
      *)
        echo "✋ Algo ocupa el puerto $PORT y no es el servidor de E2E (pid $PID):" >&2
        echo "   $COMANDO" >&2
        echo "   Bájalo, o usa otro puerto: E2E_PORT=8011 (y APP_URL en .env.e2e)." >&2
        exit 1
        ;;
    esac
  done

  # `kill` sólo pide que se cierre; el socket tarda un momento en soltarse y
  # Playwright arrancaría el suyo encima. Se espera a que el puerto esté libre
  # de verdad, con tope: si no cae, mejor decirlo que colgarse.
  ESPERA=0
  while [ -n "$OCUPANTES" ] && [ "$ESPERA" -lt 20 ] &&
    lsof -nP -iTCP:"$PORT" -sTCP:LISTEN -t >/dev/null 2>&1; do
    sleep 0.25
    ESPERA=$((ESPERA + 1))
  done
fi

# ── 2) Assets ───────────────────────────────────────────────────────────────
# La aplicación carga los assets por el manifest de Vite. Sin él, cada pantalla
# con `@vite(...)` revienta con ViteException y la suite entera se pone roja por
# una razón tonta. `globalSetup` hace esta misma comprobación; aquí se adelanta
# para que el fallo se vea antes de arrancar el navegador.
if [ ! -f public/build/manifest.json ] || [ "${E2E_BUILD:-0}" = "1" ]; then
  echo "▶ Compilando assets con \`npm run build\`…"
  npm run build
fi

# ── 3) Datos ────────────────────────────────────────────────────────────────
# `scripts/e2e-seed.sh` es opcional: si no está, quien recrea y siembra la base
# es `tests/e2e/global-setup.ts`, que además vacía el log de correo y los
# `storageState`. Nunca se saltan los dos: uno de los dos siembra siempre.
if [ "${E2E_SKIP_SEED:-0}" = "1" ]; then
  echo "▶ Salto el sembrado (E2E_SKIP_SEED=1); globalSetup lo hará igual."
elif [ -x scripts/e2e-seed.sh ]; then
  bash scripts/e2e-seed.sh
elif [ -f scripts/e2e-seed.sh ]; then
  bash scripts/e2e-seed.sh
else
  echo "▶ Sin scripts/e2e-seed.sh: siembra globalSetup."
fi

# ── 4) Playwright ───────────────────────────────────────────────────────────
echo "▶ Corriendo Playwright…"
exec npx playwright test "$@"
