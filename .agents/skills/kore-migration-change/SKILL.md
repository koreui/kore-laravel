---
name: kore-migration-change
description: Escribir una migración que MODIFICA una tabla existente en kore-laravel (cambiar el tipo, la longitud, el nullable o el default de una columna, o renombrarla). Úsalo cuando el usuario pida "cambia la columna X", "haz nullable Y", "amplía el tamaño de Z", "modifica la tabla T" o cualquier migración con `->change()`.
compatibility: "kore-laravel (Laravel 13, Livewire 4, Pest 5). Claude Code, Codex y cualquier cliente Agent Skills."
---

# Modificar una columna en kore-laravel

## Cuándo usar

- La migración toca una columna que **ya existe**: `->change()`, `renameColumn()`, cambiar un índice sobre ella.
- El usuario pide "hazla nullable", "que acepte más caracteres", "cámbiale el default", "pásala a decimal".

**No lo uses** para crear una tabla nueva ni para añadir una columna: ahí no hay
nada que perder. Para eso basta `php artisan make:migration` y R29.

## La trampa (R53)

Desde Laravel 11 —y sigue igual en la 13— **`->change()` reescribe la
definición completa de la columna**. Todo atributo que no repitas se pierde, sin
aviso y sin error:

```php
// La columna era: string(100), nullable, default 'N/A', con comentario.

// ❌ Sólo querías ampliarla a 150 y acabas de tirar nullable, default y comentario.
$table->string('nombre', 150)->change();

// ✅ Se repite TODO lo que tenía, y sólo cambia lo que se quería cambiar.
$table->string('nombre', 150)->nullable()->default('N/A')->comment('...')->change();
```

Lo que se pierde no revienta la migración: revienta el `INSERT` de la semana
siguiente, cuando alguien guarda un registro sin ese campo y la columna ya no
admite null. Por eso R53 es **Manual**: no hay forma barata de que una
herramienta sepa qué atributos tenía la columna antes; lo que sí se puede es
mirar el esquema **antes** de escribir, y de eso va este skill.

## Pasos

### 1 · Lee el estado actual de la tabla — SIEMPRE, antes de escribir nada

Tres fuentes, y las tres aportan algo distinto:

```bash
# a) El esquema vivo: tipo, nullable, default, comentario, autoincrement.
php artisan db:table {tabla}
```

Con Laravel Boost conectado, `database-schema` con `include_column_details: true`
da lo mismo en un solo paso. En código, `Schema::getColumns('{tabla}')`.

```bash
# b) Las migraciones que tocaron esa tabla, por si el esquema vivo no está
#    sincronizado con lo que se va a desplegar.
ls database/migrations app/Modules/*/Database/Migrations | grep {tabla}
```

```bash
# c) El modelo: casts, #[Fillable], relaciones. Un `casts()` a `decimal:2`
#    sobre una columna que vas a pasar a integer es un bug esperando.
```

Si el esquema vivo y la migración de creación no coinciden, **para y
pregunta**: alguien cambió la tabla a mano.

### 2 · Crea el archivo

```bash
php artisan make:migration modify_{columna}_in_{tabla}_table --no-interaction
```

Si la tabla pertenece a un módulo, la migración va en
`app/Modules/{Domain}/Database/Migrations/` (R3), no en `database/migrations/`.

### 3 · Escribe el `up()` repitiendo todos los atributos

```php
Schema::table('{tabla}', function (Blueprint $table): void {
    // Antes: string(100) · nullable · default 'N/A'
    // Cambia: sólo la longitud.
    $table->string('nombre', 150)->nullable()->default('N/A')->change();
});
```

Lista de atributos que hay que mirar uno por uno en la salida del paso 1:
tipo y longitud/precisión, `nullable()`, `default()`, `unsigned()`,
`comment()`, `charset()` / `collation()`, `useCurrent()` / `useCurrentOnUpdate()`
en timestamps, y `autoIncrement()`.

Los índices y las foreign keys **no** los toca `->change()`: van en su propia
línea (`$table->index(...)`, `$table->dropForeign(...)`).

### 4 · Escribe el `down()` con los valores ORIGINALES (R29)

Es el inverso exacto, no «algo parecido»:

```php
public function down(): void
{
    Schema::table('{tabla}', function (Blueprint $table): void {
        // Los valores de antes del up(), tal cual salieron del paso 1.
        $table->string('nombre', 100)->nullable()->default('N/A')->change();
    });
}
```

R29 tiene dos verificadores: `kore:arch:check` mira que el método exista y
`MigrationsAreReversibleTest` lo **ejecuta**. Un `down()` que no revierte de
verdad se cae en ese test, no en producción.

### 5 · Enseña el cambio antes de correrlo

Antes de `php artisan migrate`, dile al usuario:

1. **Qué cambia** — columna, de qué a qué.
2. **Qué atributos se conservan** — la lista del paso 3, para que se vea que
   ninguno se quedó fuera.
3. **Qué pasa con los datos existentes** — estrechar un `string`, quitar un
   `nullable` de una columna con nulls o cambiar de tipo puede fallar o truncar.
   Si hay riesgo, la migración de datos va **antes**, en su propia migración.

Pide confirmación. Una migración destructiva no se corre por iniciativa propia.

### 6 · Comprueba

```bash
php artisan migrate --no-interaction
php artisan db:table {tabla}          # el resultado es el esperado, atributo a atributo
vendor/bin/pint --dirty --format agent
composer arch                          # R29: la migración define down()
./vendor/bin/pest --filter=MigrationsAreReversible
```

## Reglas que aplican

- **R53** · al modificar una columna se repiten todos sus atributos. Es la razón
  de existir de este skill; es **Manual** porque sólo el esquema real sabe qué
  tenía la columna antes.
- **R29** · toda migración define `down()`, y ese `down()` funciona.
- **R3** · la migración de un módulo vive en
  `app/Modules/{Domain}/Database/Migrations/`.
- **R21** · `DB::table()` sí se puede usar aquí: las migraciones y los seeders
  son los dos únicos sitios donde está permitido.
- **R44** · si algo obliga a saltarse una regla, **para y pregunta**: la válvula
  la firma una persona.

## Origen

Notarium —un proyecto derivado— acabó escribiendo un agente propio
(`.claude/agents/notarium-migration.md`) después de perder atributos en un
`->change()`. Este skill es ese aprendizaje devuelto al boilerplate; el catálogo
lo cuenta en la cicatriz de R53 (`docs/architecture/rules.md`).
