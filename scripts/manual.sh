#!/usr/bin/env bash
# manual.sh — Regenera el manual de usuario entero, en un comando.
#
#   1) comprueba que el puerto del manual esté libre (o que ya sea el nuestro)
#   2) compila los assets si falta el manifest de Vite
#   3) recrea y siembra la base de pruebas (scripts/e2e-seed.sh)
#   4) corre los recorridos, que dejan docs/manual/ escrito
#   5) arma el PDF si hay un Gotenberg escuchando
#
# Que regenerarlo cueste un comando no es comodidad: si cuesta, nadie lo
# regenera y el manual envejece — que es justo lo que se quiere evitar.
#
# El servidor lo levanta y lo baja **Playwright** (`webServer` de
# playwright.manual.config.ts), en el puerto del manual y no en el 8010 de la
# suite. Así el manual y la suite pueden convivir, y no hay un `artisan serve`
# que se quede huérfano si esto se interrumpe con Ctrl-C.
#
# Uso:
#   bash scripts/manual.sh                                  # todo
#   bash scripts/manual.sh --headed                         # viendo el navegador
#   bash scripts/manual.sh tests/e2e/manual/01-*.guia.ts    # un recorrido suelto
#   MANUAL_SKIP_SEED=1 bash scripts/manual.sh               # sin resembrar (iterar rápido)
#   E2E_MANUAL_PORT=8111 bash scripts/manual.sh             # otro puerto
set -eu

cd "$(cd "$(dirname "$0")/.." && pwd)"

if [ ! -f .env.e2e ]; then
  echo "✋ No existe .env.e2e. Es un archivo commiteado: recupéralo con \`git checkout .env.e2e\`." >&2
  exit 1
fi

PORT="${E2E_MANUAL_PORT:-8110}"
export E2E_MANUAL_PORT="$PORT"
export E2E_MANUAL_URL="${E2E_MANUAL_URL:-http://localhost:$PORT}"

# ── 1) Puerto ───────────────────────────────────────────────────────────────
# Playwright reutiliza el servidor que encuentre (`reuseExistingServer`), y eso
# sólo sirve si el que hay es EL nuestro: cualquier otro proceso daría fallos
# que no se parecen en nada a su causa. `lsof` no está en todas las imágenes de
# CI, así que si falta se salta la comprobación en vez de fallar por ella.
if command -v lsof >/dev/null 2>&1 &&
  lsof -nP -iTCP:"$PORT" -sTCP:LISTEN >/dev/null 2>&1; then
  if curl -sf -m 3 "$E2E_MANUAL_URL/up" >/dev/null 2>&1; then
    echo "▶ Reutilizando el servidor del manual que ya está en el $PORT."
  else
    echo "✋ Algo ocupa el puerto $PORT y no es el servidor del manual." >&2
    lsof -nP -iTCP:"$PORT" -sTCP:LISTEN >&2 || true
    echo "   Bájalo, o usa otro: E2E_MANUAL_PORT=8111 bash scripts/manual.sh" >&2
    exit 1
  fi
fi

# ── 2) Assets ───────────────────────────────────────────────────────────────
# Sin el manifest, cualquier pantalla con `@vite(...)` revienta con
# ViteException y el manual saldría con capturas de una traza de error.
if [ ! -f public/build/manifest.json ] || [ "${E2E_BUILD:-0}" = "1" ]; then
  echo "▶ Compilando assets con \`npm run build\`…"
  npm run build
fi

# ── 3) Datos ────────────────────────────────────────────────────────────────
# La base se recrea de cero a propósito: los recorridos **dan de alta** cosas,
# así que cada corrida dejaría una fila más y las capturas del listado irían
# creciendo con un usuario de más cada vez.
if [ "${MANUAL_SKIP_SEED:-0}" = "1" ]; then
  echo "▶ Salto el sembrado (MANUAL_SKIP_SEED=1)."
else
  bash scripts/e2e-seed.sh
fi

# ── 4) Recorridos ───────────────────────────────────────────────────────────
echo "▶ Recorriendo y fotografiando…"
npx playwright test --config=playwright.manual.config.ts "$@"

# ── 5) PDF ──────────────────────────────────────────────────────────────────
# Sólo si Gotenberg está escuchando. No se levanta desde aquí a propósito: el
# manual en Markdown ya está completo sin él, y arrancar contenedores no es
# asunto de este script.
GOTENBERG="${GOTENBERG_URL:-http://127.0.0.1:3000}"

if curl -sf -m 3 "$GOTENBERG/health" >/dev/null 2>&1; then
  echo "▶ Armando el PDF…"
  node scripts/manual-pdf.mjs
else
  echo "▶ Gotenberg no responde en $GOTENBERG: me salto el PDF."
  echo "   Para generarlo: levanta Gotenberg y corre \`npm run manual:pdf\`."
fi

echo "✔ Manual en docs/manual/ — empieza por docs/manual/README.md"
