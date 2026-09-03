<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Validator;

/*
|--------------------------------------------------------------------------
| Cobertura de traducciones
|--------------------------------------------------------------------------
|
| kore-laravel usa el ESPAÑOL como idioma fuente: las vistas escriben
| `__('Iniciar sesión')` y con `APP_LOCALE=es` esa clave se devuelve tal cual.
| El inglés vive en JSON. Este test evita que la traducción se quede atrás.
|
| ¿Añadiste un texto nuevo y este test falla? Añade la clave a UNO de estos:
|
|   - `app/Modules/{Modulo}/Resources/lang/en.json` → texto de ese módulo
|   - `lang/en.json`                                → texto compartido
|     (layouts, landing, componentes de `resources/views`)
|
| Si la clave literal está en INGLÉS (pasa con lo que emiten Fortify y los
| correos del framework, p. ej. `__('The provided password does not match
| your current password.')` o `Verify Email Address`), entonces lo que falta
| es su traducción al español: añádela a `lang/es.json`.
|
| Detalles y decisiones: `docs/guides/i18n.md`.
|
*/

/**
 * Extrae todas las claves `__('...')` / `__("...")` del código de la app.
 *
 * Se ignoran `vendor/`, `node_modules/`, `tests/e2e/` y los tests de módulo:
 * ahí las cadenas son datos de prueba, no UI.
 *
 * @return array<string, list<string>> clave => archivos donde aparece
 */
function koreTranslationKeysInSource(): array
{
    $pattern = '/__\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")/';

    $keys = [];

    foreach (['app', 'resources', 'routes'] as $root) {
        $directory = base_path($root);

        if (! is_dir($directory)) {
            continue;
        }

        /** @var iterable<string, SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());

            if ($file->getExtension() !== 'php') {
                continue;
            }

            if (preg_match('#(^|/)(vendor|node_modules|Tests)/|^tests/e2e/#', $relative) === 1) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            if (preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER) === 0) {
                continue;
            }

            foreach ($matches as $match) {
                $single = $match[1] ?? '';
                $double = $match[2] ?? '';

                if ($single === '' && $double === '') {
                    continue;
                }

                $key = $single !== ''
                    ? str_replace(["\\'", '\\\\'], ["'", '\\'], $single)
                    : stripcslashes($double);

                $keys[$key][] = $relative;
            }
        }
    }

    ksort($keys);

    return array_map(
        static fn (array $files): array => array_values(array_unique($files)),
        $keys
    );
}

/**
 * Rutas de todos los `{locale}.json` del proyecto: el compartido de `lang/`
 * y el de cada módulo (`loadJsonTranslationsFrom` en su ServiceProvider).
 *
 * @return list<string>
 */
function koreJsonTranslationFiles(string $locale): array
{
    return array_values(array_filter(array_merge(
        [base_path("lang/{$locale}.json")],
        glob(base_path("app/Modules/*/Resources/lang/{$locale}.json")) ?: [],
    ), is_file(...)));
}

/**
 * Contenido combinado de todos los JSON de un locale.
 *
 * @return array<string, string>
 */
function koreJsonTranslations(string $locale): array
{
    $translations = [];

    foreach (koreJsonTranslationFiles($locale) as $file) {
        /** @var array<string, string> $decoded */
        $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

        $translations = array_merge($translations, $decoded);
    }

    return $translations;
}

/**
 * Aplana un array de traducciones a claves con notación de punto.
 *
 * @param array<array-key, mixed> $array
 * @return list<string>
 */
function koreFlattenKeys(array $array, string $prefix = ''): array
{
    $keys = [];

    foreach ($array as $key => $value) {
        $full = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
        $keys[] = $full;

        if (is_array($value)) {
            $keys = array_merge($keys, koreFlattenKeys($value, $full));
        }
    }

    sort($keys);

    return $keys;
}

it('traduce al inglés todas las claves __() del código', function (): void {
    $english = koreJsonTranslations('en');
    $spanish = koreJsonTranslations('es');

    $missing = [];

    foreach (koreTranslationKeysInSource() as $key => $files) {
        // Una clave está cubierta si tiene traducción al inglés (caso normal,
        // clave literal en español) o al español (clave literal en inglés,
        // como los mensajes heredados de Fortify).
        if (array_key_exists($key, $english) || array_key_exists($key, $spanish)) {
            continue;
        }

        $missing[] = sprintf('  · "%s"  ← %s', $key, implode(', ', $files));
    }

    expect($missing)->toBe([], sprintf(
        "Hay %d clave(s) __() sin traducir.\n%s\n\n".
        "Añade cada una al en.json de su módulo (app/Modules/{Modulo}/Resources/lang/en.json)\n".
        'o a lang/en.json si es un texto compartido. Ver docs/guides/i18n.md.',
        count($missing),
        implode("\n", $missing)
    ));
});

it('no deja claves duplicadas con traducciones distintas entre los json', function (): void {
    $seen = [];
    $conflicts = [];

    foreach (koreJsonTranslationFiles('en') as $file) {
        /** @var array<string, string> $decoded */
        $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

        foreach ($decoded as $key => $value) {
            if (isset($seen[$key]) && $seen[$key]['value'] !== $value) {
                $conflicts[] = sprintf(
                    '  · "%s": "%s" (%s) vs "%s" (%s)',
                    $key,
                    $seen[$key]['value'],
                    $seen[$key]['file'],
                    $value,
                    basename(dirname($file, 3)).'/'.basename($file)
                );

                continue;
            }

            $seen[$key] = [
                'value' => $value,
                'file' => basename(dirname($file, 3)).'/'.basename($file),
            ];
        }
    }

    expect($conflicts)->toBe([], sprintf(
        "Una misma clave se traduce de dos formas distintas:\n%s",
        implode("\n", $conflicts)
    ));
});

it('mantiene lang/es/validation.php con las mismas claves que lang/en/validation.php', function (): void {
    /** @var array<array-key, mixed> $en */
    $en = require base_path('lang/en/validation.php');
    /** @var array<array-key, mixed> $es */
    $es = require base_path('lang/es/validation.php');

    $enKeys = koreFlattenKeys($en);
    $esKeys = koreFlattenKeys($es);

    expect(array_values(array_diff($enKeys, $esKeys)))->toBe([], 'Faltan claves en lang/es/validation.php');
    expect(array_values(array_diff($esKeys, $enKeys)))->toBe([], 'Sobran claves en lang/es/validation.php');
});

it('mantiene lang/es en paridad con lang/en para auth, passwords y pagination', function (string $group): void {
    /** @var array<array-key, mixed> $en */
    $en = require base_path("lang/en/{$group}.php");
    /** @var array<array-key, mixed> $es */
    $es = require base_path("lang/es/{$group}.php");

    expect(koreFlattenKeys($es))->toBe(koreFlattenKeys($en));
})->with(['auth', 'passwords', 'pagination']);

it('devuelve la clave en español y su traducción en inglés', function (): void {
    App::setLocale('es');
    expect(__('Iniciar sesión'))->toBe('Iniciar sesión')
        ->and(__('Usuario guardado correctamente.'))->toBe('Usuario guardado correctamente.')
        ->and(__('Todo lo que necesitas'))->toBe('Todo lo que necesitas');

    App::setLocale('en');
    expect(__('Iniciar sesión'))->toBe('Sign in')                                   // lang/en.json
        ->and(__('Bienvenido de vuelta'))->toBe('Welcome back')                     // módulo Auth
        ->and(__('Usuario guardado correctamente.'))->toBe('User saved successfully.') // módulo Users
        ->and(__('Hola, :name', ['name' => 'Ada']))->toBe('Hi, Ada');               // con placeholder
});

it('traduce al español los mensajes de validación y de auth', function (): void {
    App::setLocale('es');

    expect(__('auth.failed'))->toBe('Estas credenciales no coinciden con nuestros registros.')
        ->and(__('passwords.sent'))->toBe('Te hemos enviado por correo el enlace para restablecer tu contraseña.');

    $errors = Validator::make(
        ['name' => '', 'email' => 'no-es-un-email'],
        ['name' => ['required'], 'email' => ['required', 'email'], 'password' => ['required']]
    )->errors();

    expect($errors->first('name'))->toBe('El campo nombre es obligatorio.')
        ->and($errors->first('email'))->toBe('El campo correo electrónico debe ser una dirección de correo electrónico válida.')
        ->and($errors->first('password'))->toBe('El campo contraseña es obligatorio.');
});

it('traduce al español las claves cuyo literal está en inglés', function (): void {
    App::setLocale('es');

    // Del Action de Fortify del propio boilerplate…
    expect(__('The provided password does not match your current password.'))
        ->toBe('La contraseña actual no es correcta.')
        // …de Fortify…
        ->and(__('The provided two factor authentication code was invalid.'))
        ->toBe('El código de verificación en dos pasos no es válido.')
        // …y del framework (correos de notificación).
        ->and(__('Verify Email Address'))->toBe('Verifica tu correo electrónico');
});

it('manda en español los correos de notificación del framework', function (): void {
    App::setLocale('es');

    $mail = (new VerifyEmail)->toMail(User::factory()->create());

    expect($mail->subject)->toBe('Verifica tu correo electrónico');

    $rendered = (string) $mail->render();

    expect($rendered)
        ->toContain('¡Hola!')
        ->toContain('Pulsa el botón de abajo para verificar tu correo electrónico.')
        ->toContain('Un saludo,')
        ->toContain('copia y pega esta URL')
        ->toContain('Todos los derechos reservados.')
        ->not->toContain('Hello!')
        ->not->toContain('All rights reserved.');
});
