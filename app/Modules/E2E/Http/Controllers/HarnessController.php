<?php

declare(strict_types=1);

namespace App\Modules\E2E\Http\Controllers;

use App\Core\Enums\SystemRole;
use App\Models\User;
use App\Modules\E2E\Support\HarnessGuard;
use App\Modules\E2E\Support\MailLog;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Puerta de servicio de la suite E2E.
 *
 * Cada endpoint existe para ahorrarle a un test un recorrido que ya está
 * probado en otro sitio: crear un usuario con un rol, entrar sin pasar por el
 * formulario, leer el correo que se acaba de mandar. Lo que el test *quiere
 * probar* se hace siempre por la UI; esto es el atrezzo.
 *
 * Nada de esto existe si {@see HarnessGuard::allows()} dice que no: el
 * provider ni siquiera registra las rutas, así que `/__e2e__/ping` es un 404
 * como cualquier otra URL inventada.
 *
 * Por qué la escritura vive aquí y no en Actions (R4): el harness es
 * infraestructura de pruebas, no un caso de uso del producto. Un
 * `E2EUserCreateAction` sería una Action que nadie llama desde la aplicación y
 * que además tendría que duplicar lo que ya hace `Users`. Y no puede llamar a
 * `Users` (R5): el módulo E2E no importa a nadie — trabaja sobre
 * `App\Models\User`, que es global, y sobre `App\Core\Enums\SystemRole` para
 * los roles.
 */
final class HarnessController
{
    /**
     * Comandos de artisan que el harness acepta correr.
     *
     * Lista blanca corta y explícita: un endpoint HTTP sin autenticación que
     * ejecute artisan arbitrario es una shell remota, aunque viva detrás de
     * tres candados.
     *
     * @var list<string>
     */
    private const array ALLOWED_COMMANDS = [
        'kore:regenerate-permissions',
        'cache:clear',
    ];

    /** Latido: confirma que la suite le está pegando al entorno correcto. */
    public function ping(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'app' => config('app.name'),
            'environment' => app()->environment(),
            'database' => HarnessGuard::databaseName(),
            'users' => User::query()->count(),
        ]);
    }

    /**
     * Inicia sesión como un usuario existente, sin pasar por el formulario.
     *
     * El login por formulario se prueba en `specs/auth/login.spec.ts`; el
     * resto de la suite no necesita repetirlo cien veces.
     *
     * Body: `{ email }`.
     */
    public function loginAs(Request $request): JsonResponse
    {
        $email = (string) $request->input('email');

        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User) {
            return response()->json(['error' => "No existe el usuario «{$email}»."], 404);
        }

        auth()->login($user);
        $request->session()->regenerate();

        return response()->json([
            'id' => $user->id,
            'email' => $user->email,
            'roles' => $user->getRoleNames()->all(),
        ]);
    }

    /** Cierra la sesión actual sin depender del botón de la UI. */
    public function logout(Request $request): JsonResponse
    {
        auth()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }

    /**
     * Crea (o actualiza) un usuario con su rol y sus permisos directos.
     *
     * Body: `{ role, email?, name?, password?, permissions? }`. El email por
     * defecto es único (`e2e-<uniqid>@e2e.test`) para que dos tests en
     * paralelo no se pisen, y el rol es uno de `App\Core\Enums\SystemRole`
     * —los que siembra `ModulesSeeder`—.
     */
    public function createUser(Request $request): JsonResponse
    {
        $role = (string) $request->input('role');

        if (! $this->isKnownRole($role)) {
            return response()->json([
                'error' => "El rol «{$role}» no existe.",
                'allowed' => $this->roles(),
            ], 422);
        }

        // `Str::random()` y no `uniqid()`: el preset de seguridad de Pest
        // prohíbe el segundo (no es aleatorio de verdad, va del reloj) y en una
        // suite en paralelo dos workers pueden pedir el mismo microsegundo.
        $email = (string) $request->input('email', 'e2e-'.Str::lower(Str::random(16)).'@e2e.test');

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => (string) $request->input('name', 'Usuario E2E'),
                'password' => Hash::make((string) $request->input('password', 'password')),
                'email_verified_at' => now(),
            ],
        );

        $user->syncRoles([$role]);

        // Permisos directos, además de los que trae el rol. Es lo que
        // distingue al «editor» del «viewer» de `E2eSeeder` sin inventarse un
        // rol nuevo por cada matiz de autorización que un spec quiera probar.
        if ($request->has('permissions')) {
            $user->syncPermissions((array) $request->input('permissions', []));
        }

        return response()->json([
            'id' => $user->id,
            'email' => $user->email,
            'roles' => $user->getRoleNames()->all(),
            'permissions' => $user->getPermissionNames()->all(),
        ], 201);
    }

    /**
     * Borra un usuario sembrado por un test.
     *
     * Body: `{ email }`. Responde `{ deleted }` con el número de filas: cero
     * no es un error, es «ya no estaba».
     */
    public function deleteUser(Request $request): JsonResponse
    {
        $deleted = User::query()->where('email', (string) $request->input('email'))->delete();

        return response()->json(['deleted' => $deleted]);
    }

    /**
     * Último correo del buzón (`?to=` para filtrar por destinatario).
     *
     * 404 cuando no hay ninguno: un test que espera un correo prefiere un
     * fallo claro a un `null` que se cuela hasta la aserción siguiente.
     */
    public function lastMail(Request $request): JsonResponse
    {
        $to = $request->query('to');
        $recipient = is_string($to) && $to !== '' ? $to : null;

        $mail = MailLog::last($recipient);

        if ($mail === null) {
            return response()->json([
                'error' => $recipient !== null
                    ? "No hay ningún correo para «{$recipient}»."
                    : 'El buzón está vacío.',
            ], 404);
        }

        return response()->json($mail);
    }

    /** Vacía el buzón. */
    public function clearMail(): JsonResponse
    {
        MailLog::clear();

        return response()->json(['ok' => true]);
    }

    /**
     * Corre un comando de artisan de la lista blanca.
     *
     * Body: `{ command, arguments? }`. Sirve para los flujos que dependen de
     * una corrida fuera de la petición: regenerar permisos después de sembrar
     * un módulo, tirar la caché de config entre escenarios.
     */
    public function artisan(Request $request): JsonResponse
    {
        $command = (string) $request->input('command');

        if (! in_array($command, self::ALLOWED_COMMANDS, true)) {
            return response()->json([
                'error' => "«{$command}» no está en la lista blanca del harness.",
                'allowed' => self::ALLOWED_COMMANDS,
            ], 422);
        }

        $exit = Artisan::call($command, (array) $request->input('arguments', []));

        return response()->json([
            'exit_code' => $exit,
            'output' => Artisan::output(),
        ]);
    }

    /**
     * Olvida los intentos acumulados del limitador de peticiones.
     *
     * Body: `{ keys?: string[] }`.
     *
     * El login está limitado a 5 intentos por minuto y por `email|ip`, y con
     * razón. Pero la suite entera sale de una sola IP: sin esto, a partir de
     * cierto punto los tests fallarían con 429 y el fallo no tendría nada que
     * ver con lo que probaban. Que el límite existe se comprueba en su propio
     * test, a propósito y limpiando después.
     *
     * Se vacía además el almacén de caché completo: el limitador de Fortify
     * combina correo e IP y no hay forma de enumerar sus claves. Es una base
     * de pruebas; no hay nada ahí que valga la pena conservar.
     */
    public function clearThrottle(Request $request): JsonResponse
    {
        $limiter = resolve(RateLimiter::class);

        foreach ((array) $request->input('keys', []) as $key) {
            $limiter->clear((string) $key);
        }

        Cache::store(config('cache.default'))->flush();

        return response()->json(['ok' => true]);
    }

    /** @return list<string> */
    private function roles(): array
    {
        return array_map(
            static fn (SystemRole $role): string => $role->value,
            SystemRole::cases(),
        );
    }

    private function isKnownRole(string $role): bool
    {
        return in_array($role, $this->roles(), true);
    }
}
