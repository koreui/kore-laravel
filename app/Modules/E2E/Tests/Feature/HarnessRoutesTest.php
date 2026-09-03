<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Database\Seeders\ModulesSeeder;
use App\Modules\E2E\Providers\E2EModuleServiceProvider;
use App\Modules\E2E\Support\MailLog;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Las rutas /__e2e__/*
|--------------------------------------------------------------------------
|
| La suite arranca con el harness apagado (`phpunit.xml` fuerza
| `E2E_HARNESS=false`), así que la mitad de arriba comprueba lo que R10 pide de
| un módulo opt-in: con el toggle apagado la ruta no existe — no es que
| responda 403, es que no está.
|
| La mitad de abajo lo enciende. Se hace con `Config::set()` +
| `register(force: true)` y no con `withEnvironment()` a propósito: el helper
| rearranca la aplicación y con ella la SQLite `:memory:`, así que dentro de su
| callback no hay base de datos y estos tests crean usuarios. El arranque de
| verdad —el que demuestra que la variable de entorno llega hasta las rutas— se
| prueba una vez, en el test que no toca la base.
|
| Ver docs/patterns/test-con-otro-entorno.md para las dos formas.
|
*/

/** Nombres de todas las rutas del harness. */
function harnessRouteNames(): array
{
    return [
        'e2e.ping',
        'e2e.login-as',
        'e2e.logout',
        'e2e.users.store',
        'e2e.users.destroy',
        'e2e.mail.last',
        'e2e.mail.clear',
        'e2e.artisan',
        'e2e.throttle.clear',
    ];
}

/**
 * Enciende el flag y vuelve a arrancar el provider del módulo, que es lo que
 * registra las rutas. La base de datos sobrevive, a diferencia de
 * `withEnvironment()`.
 */
function withHarness(Closure $callback): void
{
    Config::set('kore-app.e2e.harness', true);

    app()->register(E2EModuleServiceProvider::class, force: true);

    $callback();
}

/*
|--------------------------------------------------------------------------
| Apagado
|--------------------------------------------------------------------------
*/

it('keeps the harness off by default in the suite', function (): void {
    expect(config('kore-app.e2e.harness'))->toBeFalse();
});

it('registers no harness route with the toggle off', function (): void {
    foreach (harnessRouteNames() as $name) {
        expect(Route::has($name))->toBeFalse("La ruta {$name} no debería existir.");
    }
});

it('answers 404 on the harness urls with the toggle off', function (): void {
    $this->get('/__e2e__/ping')->assertNotFound();
    $this->post('/__e2e__/login-as', ['email' => 'quien@sea.test'])->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Encendido
|--------------------------------------------------------------------------
*/

/*
 * El único test que arranca la aplicación de verdad con `E2E_HARNESS=true`:
 * demuestra que la variable de entorno llega hasta `config/kore-app.php` y de
 * ahí a las rutas. No toca la base porque dentro del callback no hay
 * (`withEnvironment()` rearranca y la `:memory:` nace vacía).
 */
it('registers the harness routes when the environment turns it on', function (): void {
    withEnvironment(['E2E_HARNESS' => 'true'], function (): void {
        expect(config('kore-app.e2e.harness'))->toBeTrue();

        foreach (harnessRouteNames() as $name) {
            expect(Route::has($name))->toBeTrue("Falta la ruta {$name}.");
        }
    });
});

it('answers the ping with the environment it is hitting', function (): void {
    withHarness(function (): void {
        User::factory()->count(2)->create();

        $this->getJson('/__e2e__/ping')
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'environment' => 'testing',
                'database' => ':memory:',
                'users' => 2,
            ]);
    });
});

it('logs in as an existing user and logs back out', function (): void {
    withHarness(function (): void {
        $this->seed(ModulesSeeder::class);

        $user = User::factory()->create(['email' => 'entra@e2e.test']);
        $user->syncRoles(['Usuario']);

        $this->postJson('/__e2e__/login-as', ['email' => 'entra@e2e.test'])
            ->assertOk()
            ->assertJson([
                'id' => $user->id,
                'email' => 'entra@e2e.test',
                'roles' => ['Usuario'],
            ]);

        $this->assertAuthenticatedAs($user);

        $this->postJson('/__e2e__/logout')->assertOk()->assertJson(['ok' => true]);

        $this->assertGuest();
    });
});

it('answers 404 when the user to log in as does not exist', function (): void {
    withHarness(function (): void {
        $this->postJson('/__e2e__/login-as', ['email' => 'fantasma@e2e.test'])
            ->assertNotFound()
            ->assertJsonPath('error', 'No existe el usuario «fantasma@e2e.test».');

        $this->assertGuest();
    });
});

it('creates a user with its role and its direct permissions', function (): void {
    withHarness(function (): void {
        $this->seed(ModulesSeeder::class);

        $response = $this->postJson('/__e2e__/users', [
            'role' => 'Usuario',
            'email' => 'creado@e2e.test',
            'name' => 'Creado por el harness',
            'permissions' => ['users.view', 'users.edit'],
        ])->assertCreated();

        $response->assertJson([
            'email' => 'creado@e2e.test',
            'roles' => ['Usuario'],
            'permissions' => ['users.view', 'users.edit'],
        ]);

        $user = User::query()->where('email', 'creado@e2e.test')->firstOrFail();

        expect($user->name)->toBe('Creado por el harness')
            ->and($user->email_verified_at)->not->toBeNull()
            // La contraseña por defecto es `password`, la misma que siembra
            // E2eSeeder: un test puede volver a entrar por la UI real.
            ->and(Hash::check('password', (string) $user->password))->toBeTrue();
    });
});

it('invents a unique email when the test does not give one', function (): void {
    withHarness(function (): void {
        $this->seed(ModulesSeeder::class);

        $first = $this->postJson('/__e2e__/users', ['role' => 'Usuario'])->assertCreated();
        $second = $this->postJson('/__e2e__/users', ['role' => 'Usuario'])->assertCreated();

        expect($first->json('email'))->toEndWith('@e2e.test')
            ->and($second->json('email'))->not->toBe($first->json('email'));
    });
});

it('refuses a role that is not one of the system ones', function (): void {
    withHarness(function (): void {
        $this->postJson('/__e2e__/users', ['role' => 'Emperador'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'El rol «Emperador» no existe.');

        expect(User::query()->count())->toBe(0);
    });
});

it('deletes a user by email', function (): void {
    withHarness(function (): void {
        User::factory()->create(['email' => 'sobra@e2e.test']);

        $this->deleteJson('/__e2e__/users', ['email' => 'sobra@e2e.test'])
            ->assertOk()
            ->assertJson(['deleted' => 1]);

        expect(User::query()->where('email', 'sobra@e2e.test')->exists())->toBeFalse();

        // Borrar lo que ya no está no es un error: es cero.
        $this->deleteJson('/__e2e__/users', ['email' => 'sobra@e2e.test'])
            ->assertOk()
            ->assertJson(['deleted' => 0]);
    });
});

it('runs an artisan command from the whitelist and refuses the rest', function (): void {
    withHarness(function (): void {
        $this->postJson('/__e2e__/artisan', ['command' => 'cache:clear'])
            ->assertOk()
            ->assertJsonPath('exit_code', 0);

        $this->postJson('/__e2e__/artisan', ['command' => 'migrate:fresh'])
            ->assertStatus(422)
            ->assertJsonPath('error', '«migrate:fresh» no está en la lista blanca del harness.')
            ->assertJsonPath('allowed', ['kore:regenerate-permissions', 'cache:clear']);
    });
});

it('clears the rate limiter buckets', function (): void {
    withHarness(function (): void {
        $this->postJson('/__e2e__/throttle/clear', ['keys' => ['login|1.2.3.4']])
            ->assertOk()
            ->assertJson(['ok' => true]);
    });
});

/*
|--------------------------------------------------------------------------
| El buzón
|--------------------------------------------------------------------------
|
| El correo se manda de verdad por el mailer `log` sobre el canal `e2e_mail`,
| que es la combinación exacta de `.env.e2e`. Nada de `Mail::fake()`: lo que se
| prueba es que el archivo se escribe y que MailLog lo sabe leer.
|
*/

/** Manda un correo real al canal del harness. */
function harnessSendMail(string $to, string $subject, string $html): void
{
    Config::set('mail.default', 'log');
    Config::set('mail.mailers.log.channel', 'e2e_mail');
    Mail::purge('log');

    $mailable = new class extends Mailable {};

    Mail::to($to)->send($mailable->subject($subject)->html($html));
}

it('answers 404 when the mailbox is empty', function (): void {
    withHarness(function (): void {
        MailLog::clear();

        $this->getJson('/__e2e__/mail/last')
            ->assertNotFound()
            ->assertJsonPath('error', 'El buzón está vacío.');

        $this->getJson('/__e2e__/mail/last?to=nadie@e2e.test')
            ->assertNotFound()
            ->assertJsonPath('error', 'No hay ningún correo para «nadie@e2e.test».');
    });
});

it('returns the last mail of a recipient with its otp', function (): void {
    withHarness(function (): void {
        MailLog::clear();

        harnessSendMail('otra@e2e.test', 'Para otra persona', '<p>**999999**</p>');
        harnessSendMail('buzon@e2e.test', 'Tu código de acceso', '<p>Tu código es **481516**.</p>');
        harnessSendMail('otra@e2e.test', 'Y otro más', '<p>**111111**</p>');

        // Sin filtro, el último del archivo.
        $this->getJson('/__e2e__/mail/last')
            ->assertOk()
            ->assertJsonPath('subject', 'Y otro más');

        // Con filtro, el último de ese destinatario aunque haya otros después:
        // es lo que hace que la suite pueda correr en paralelo.
        $this->getJson('/__e2e__/mail/last?to=buzon@e2e.test')
            ->assertOk()
            ->assertJsonPath('to', 'buzon@e2e.test')
            ->assertJsonPath('subject', 'Tu código de acceso')
            ->assertJsonPath('otp', '481516');
    });
});

it('empties the mailbox', function (): void {
    withHarness(function (): void {
        harnessSendMail('vaciar@e2e.test', 'Algo', '<p>hola</p>');

        $this->deleteJson('/__e2e__/mail')->assertOk()->assertJson(['ok' => true]);

        expect(trim((string) file_get_contents(MailLog::path())))->toBe('');

        $this->getJson('/__e2e__/mail/last')->assertNotFound();
    });
});
