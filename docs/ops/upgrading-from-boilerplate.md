# Actualizar un proyecto derivado desde el boilerplate

**TL;DR**: el boilerplate se añade como un remoto más (`kore`) y cada release se
trae con `git merge vX.Y.Z`. Lo que manda no es el diff sino la **«Migración
desde X.Y.Z»** del [`CHANGELOG.md`](../../CHANGELOG.md): ahí está lo que hay que
aplicar a mano. Al terminar, `composer ci` y `npm run e2e` dicen si quedó bien.

Este doc es para el proyecto **hijo** (el que se creó a partir de kore-laravel).
Si lo que buscas es cómo devolver una mejora al padre, salta al último apartado
y a [`../patterns/README.md`](../patterns/README.md).

## 1 · Conectar el remoto (una sola vez)

```bash
git remote add kore https://github.com/koreui/kore-laravel.git
git fetch kore --tags
git tag -l 'v*'          # qué releases hay
```

`kore` es sólo lectura: nadie empuja al boilerplate desde un derivado. Lo que
sube al padre va por PR (§6).

## 2 · Traer una release

Dos caminos, y el segundo no es un atajo del primero:

```bash
# a) Merge de la release completa — lo normal.
git checkout -b upgrade/v1.4.0
git merge v1.4.0

# b) Cherry-pick de commits sueltos — sólo si tu proyecto divergió tanto que el
#    merge es inmanejable. Pierdes la base común, así que el siguiente merge
#    volverá a traer esos cambios como conflictos.
git cherry-pick <sha>
```

**Nunca saltes versiones sin leerlas.** Si vas de la v1.1.0 a la v1.4.0, lee las
notas de migración de la v1.2.0, la v1.3.0 **y** la v1.4.0, en ese orden: cada
una da por hecho el estado que dejó la anterior. Las secciones se llaman
«Migración desde X.Y.Z» y están al final de cada entrada del
[`CHANGELOG.md`](../../CHANGELOG.md).

Para leer sólo la que te toca, sin abrir el archivo entero:

```bash
php artisan kore:changelog:section v1.4.0
```

## 3 · Los archivos que siempre dan conflicto

Un derivado toca casi siempre los mismos ocho archivos. No hay forma de que el
merge los resuelva solo: **son configuración, no código**, y la versión buena es
una mezcla de las dos.

| Archivo | Cómo se reconcilia |
|---------|--------------------|
| `.env.example` | Une: quédate con **todas** las claves de las dos partes. Las nuevas del boilerplate suelen tener default seguro; las tuyas no las toca nadie. Después compara con tu `.env` real (`diff <(cut -d= -f1 .env.example \| sort) <(cut -d= -f1 .env \| sort)`) y añade lo que falte en el `.env` de cada entorno. |
| `config/kore-app.php` | Une por claves. Una clave nueva del boilerplate viene con su lector; si la borras, R11 te lo dice, y si la dejas sin lector, también. |
| `bootstrap/providers.php` | Une la lista. Los providers del boilerplate van **en su orden original** (por ejemplo `BackupServiceProvider` después de `HealthServiceProvider`) y los tuyos detrás. |
| `composer.json` | Une `require` / `require-dev` y, con cuidado, `scripts` y `extra.laravel.dont-discover`. Si tu proyecto cambió un script de `composer ci`, quédate con el tuyo pero **añade** los pasos nuevos. Después, `composer update --lock` para regenerar el `composer.lock` sin cambiar versiones. |
| `CLAUDE.md` | Es tuyo: describe **tu** proyecto. Trae las secciones nuevas (reglas de oro, toggles, comandos) y deja fuera lo que no aplique. Luego regenera el gemelo: `php artisan kore:agents:sync` (R50) — no lo edites a mano. |
| `docs/architecture/rules.md` | Es tuyo también, pero es el que más caro sale ignorar: si una release añade reglas y no las copias, `composer arch` falla por R40 en cuanto el código nuevo las cite. Copia las entradas nuevas enteras (enunciado, `> Enforcement:`, `> Escape:`, «Por qué» y «Cicatriz») y actualiza el rango del título y los recuentos del final. Tus reglas propias van con números por encima del último del boilerplate para que no choquen. |
| `resources/views/welcome.blade.php` | Casi siempre está reescrito en el hijo. Quédate con la tuya y mira el diff sólo por si el boilerplate arregló algo estructural (una etiqueta de accesibilidad, un `@vite` que cambió de nombre). |
| `docker-compose.prod.yml` | Une por servicios. Lo que suele venir nuevo son anclas YAML y `healthcheck:`; lo que suele ser tuyo son puertos, volúmenes y variables. Valida antes de desplegar: `docker compose -f docker-compose.prod.yml config --quiet`. |

Regla práctica para todos: `git checkout --ours` / `--theirs` **no** sirve aquí.
Ábrelos y decide clave por clave.

## 4 · Migraciones

Las migraciones del boilerplate se dividen en dos y se tratan distinto:

- **Las del repositorio** (`database/migrations/`, `app/Modules/*/Database/Migrations/`)
  llegan con el merge como archivos nuevos. Corre `php artisan migrate` y ya. No
  las renombres: el nombre es la clave de la tabla `migrations`, y renombrarla
  la vuelve a ejecutar.
- **Las publicadas por un paquete** (las que `vendor:publish` dejó en tu
  `database/migrations/` cuando montaste el proyecto) pueden llegar **también**
  desde el boilerplate y quedar duplicadas con otro timestamp. Antes de migrar:
  `php artisan migrate --pretend` y mira si alguna crea una tabla que ya tienes.
  Si la duplicada es tuya y la del boilerplate trae un `down()` mejor, quédate
  con una sola.

Y en las dos: **el `down()` no es decorativo** (R29). Después de migrar, ensaya
la vuelta en local con `php artisan migrate:rollback --step=N`. El boilerplate
lo verifica con `tests/Feature/MigrationsAreReversibleTest.php`; cópialo si
todavía no lo tienes.

Si tu proyecto añadió módulos, revisa además los conteos que ese test y
`CleanInstallTest` dan por buenos: esperan los módulos, roles y permisos del
boilerplate, no los tuyos.

## 5 · Verificar

En este orden, porque cada uno tarda más que el anterior:

```bash
php artisan kore:arch:check     # 0,2 s — reglas textuales, incluidas las nuevas
php artisan kore:agents:sync --check
composer ci                     # lint + phpstan + arch + rector + pest
php artisan about --only=kore   # el estado real de los toggles tras el merge
npm run e2e                     # si el merge tocó rutas, vistas, Livewire o permisos
```

`php artisan about --only=kore` es el que más veces salva el despliegue: enseña
si un toggle quedó encendido o apagado después de unir los `.env`, que es
exactamente el error que ningún test ve porque los tests corren con su propio
entorno.

Si `kore:arch:check` falla por R40 citando reglas que no existen, es el punto de
§3 sobre `docs/architecture/rules.md`: te faltan entradas del catálogo.

## 6 · El camino inverso: del hijo al padre

Una mejora que nace en un proyecto derivado —un patrón que resolvió un problema
de verdad, un check nuevo, un test que descubrió un fallo del boilerplate—
**vuelve al padre por PR**, no por copia manual entre repos.

El criterio de cuándo vale la pena está en
[`../patterns/README.md`](../patterns/README.md): la **regla de tres**. Una vez
es un caso, dos es una coincidencia, tres es un patrón — y sólo entonces sube.

Cómo se hace:

```bash
git clone https://github.com/koreui/kore-laravel.git
git checkout -b feat/lo-que-sea
# porta el cambio, en genérico: sin nombres de tu dominio ni de tus tablas
composer ci
```

Y en el PR, tres cosas que el review va a pedir igual:

1. **La regla que toca.** Si el cambio afecta a una regla del catálogo, cítala
   (`R11`, `R23`…). Si crea una nueva, va con su enunciado, su enforcement, su
   válvula y su cicatriz — la cicatriz es el incidente real de tu proyecto, que
   es justo lo que la hace creíble.
2. **Su verificador.** Una regla sin verificador es una sugerencia. Si no hay
   forma barata de comprobarla, dilo y márcala **Manual**.
3. **Su entrada en el CHANGELOG** (R42), en `[Unreleased]`, con la nota de
   migración para los demás derivados.
