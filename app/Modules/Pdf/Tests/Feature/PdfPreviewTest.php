<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Database\Seeders\ModulesSeeder;
use App\Modules\Auth\Models\Role;
use Spatie\LaravelPdf\Facades\Pdf;

/*
|--------------------------------------------------------------------------
| La vista previa del tema de PDF
|--------------------------------------------------------------------------
|
| Dos rutas sobre la MISMA hoja: `/pdf/preview` la sirve como HTML y
| `/pdf/preview/download` la convierte. Aquí se prueba lo que las distingue —el
| tipo de respuesta— y sobre todo lo que las une: que el cromo (logo embebido,
| pie, código del documento, marca de agua) sale en las dos.
|
| Nada de esto habla con Gotenberg. La conversión la intercepta `Pdf::fake()`,
| que devuelve un PDF de mentira y guarda con qué vista se le llamó, así que la
| suite corre igual sin ningún contenedor levantado.
|
| El módulo va apagado en la suite (`phpunit.xml`), así que todo pasa por
| `withEnvironment()` — docs/patterns/test-con-otro-entorno.md.
|
*/

beforeEach(function (): void {
    $this->seed(ModulesSeeder::class);
});

/** Arranca la aplicación con el módulo Pdf encendido. */
function withPdfEnabled(Closure $callback): void
{
    withEnvironment(['PDF_ENABLED' => 'true'], $callback);
}

/** Un usuario que pasa el gate `viewPdfPreview`. */
function pdfMaquetador(): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::ADMIN);

    return $user;
}

/*
|--------------------------------------------------------------------------
| Quién entra
|--------------------------------------------------------------------------
*/

it('sends a guest to the login', function (): void {
    withPdfEnabled(function (): void {
        $this->get('/pdf/preview')->assertRedirect('/login');
        $this->get('/pdf/preview/download')->assertRedirect('/login');
    });
});

it('rejects a user without the role', function (): void {
    $user = User::factory()->create();
    $user->assignRole(Role::USER);

    withPdfEnabled(function () use ($user): void {
        $this->actingAs($user)->get('/pdf/preview')->assertForbidden();
        $this->actingAs($user)->get('/pdf/preview/download')->assertForbidden();
    });
});

it('lets the superadmin and the administrator in', function (): void {
    $superadmin = User::factory()->create();
    $superadmin->assignRole(Role::SUPERADMIN);

    $admin = User::factory()->create();
    $admin->assignRole(Role::ADMIN);

    withPdfEnabled(function () use ($superadmin, $admin): void {
        $this->actingAs($superadmin)->get('/pdf/preview')->assertOk();
        $this->actingAs($admin)->get('/pdf/preview')->assertOk();
    });
});

/*
|--------------------------------------------------------------------------
| La hoja
|--------------------------------------------------------------------------
*/

it('renders the sample document with the base theme', function (): void {
    $admin = pdfMaquetador();

    withPdfEnabled(function () use ($admin): void {
        $this->actingAs($admin)->get('/pdf/preview')
            ->assertOk()
            ->assertViewIs('pdf::examples.sample')
            ->assertSee('Documento de ejemplo')
            ->assertSee('Conceptos')
            // El código del documento va en el margin box de `@page`, que es
            // donde Chromium lo repite hoja a hoja.
            ->assertSee('KORE-PDF-001')
            // El papel simulado del navegador: la única diferencia entre lo que
            // se revisa y lo que se entrega.
            ->assertSee('class="hoja"', escape: false);
    });
});

it('embeds the configured logo in the sheet instead of linking it', function (): void {
    $admin = pdfMaquetador();
    $relative = 'kore-pdf-preview-'.bin2hex(random_bytes(6)).'.png';

    file_put_contents(public_path($relative), 'png-de-mentira');

    try {
        withPdfEnabled(function () use ($admin, $relative): void {
            config()->set('kore-pdf.logo', $relative);

            $this->actingAs($admin)->get('/pdf/preview')
                ->assertOk()
                ->assertSee('src="data:image/png;base64,'.base64_encode('png-de-mentira'), escape: false)
                // Lo que NO puede aparecer: una URL que Gotenberg tendría que ir
                // a buscar y que le llegaría rota, en silencio.
                ->assertDontSee('src="/'.$relative, escape: false);
        });
    } finally {
        @unlink(public_path($relative));
    }
});

it('prints the footer and the watermark only when they are asked for', function (): void {
    $admin = pdfMaquetador();

    withPdfEnabled(function () use ($admin): void {
        config()->set('kore-pdf.footer_lines', ['Razón Social S.A. de C.V.']);
        config()->set('kore-pdf.watermark', 'COPIA NO CONTROLADA');

        // Sin pedirla, no hay marca de agua; el pie sí va siempre.
        $this->actingAs($admin)->get('/pdf/preview')
            ->assertOk()
            ->assertSee('Razón Social S.A. de C.V.')
            ->assertDontSee('COPIA NO CONTROLADA');

        $this->actingAs($admin)->get('/pdf/preview?watermark=1')
            ->assertOk()
            ->assertSee('COPIA NO CONTROLADA');
    });
});

/*
|--------------------------------------------------------------------------
| La descarga
|--------------------------------------------------------------------------
*/

it('serves the same sheet as an inline pdf', function (): void {
    $admin = pdfMaquetador();

    withPdfEnabled(function () use ($admin): void {
        $fake = Pdf::fake();

        $response = $this->actingAs($admin)->get('/pdf/preview/download');

        $response->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', 'inline; filename="kore-pdf-ejemplo.pdf"');

        // La misma vista que la vista previa: en cuanto sean dos, lo que se
        // revisa en pantalla deja de ser lo que se entrega.
        $fake->assertViewIs('pdf::examples.sample');
        $fake->assertViewHas('brand');
    });
});

/*
 * `paged` es lo único que cambia entre las dos salidas, y lo pone la Action.
 * Si alguien lo dejara en `true` al convertir, el PDF saldría con el fondo gris
 * y el marco del visor dentro.
 */
it('turns off the browser chrome when it is the pdf that is being generated', function (): void {
    $admin = pdfMaquetador();

    withPdfEnabled(function () use ($admin): void {
        $fake = Pdf::fake();

        $this->actingAs($admin)->get('/pdf/preview/download')->assertOk();

        $fake->assertViewHas('paged', false);
    });
});
