<?php

declare(strict_types=1);

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Cabeceras fijas
|--------------------------------------------------------------------------
|
| El dataset se lee del propio `config/security.php` (con `require`, porque
| PHPUnit resuelve los data providers antes de arrancar la aplicación y ahí
| `config()` todavía no existe). Así, añadir una cabecera al config añade su
| caso de prueba sin tocar este archivo.
|
*/

dataset('cabeceras fijas', function (): array {
    /** @var array{headers: array<string, string>} $security */
    $security = require dirname(__DIR__, 2).'/config/security.php';

    $cases = [];

    foreach ($security['headers'] as $name => $value) {
        $cases[$name] = [$name, $value];
    }

    return $cases;
});

it('sends every fixed security header on a web response', function (string $name, string $value): void {
    $this->get('/')
        ->assertOk()
        ->assertHeader($name, $value);
})->with('cabeceras fijas');

it('sends the content security policy in report-only mode by default', function (): void {
    $response = $this->get('/')
        ->assertOk()
        ->assertHeader('Content-Security-Policy-Report-Only')
        ->assertHeaderMissing('Content-Security-Policy');

    expect($response->headers->get('Content-Security-Policy-Report-Only'))
        ->toContain("default-src 'self'")
        ->toContain("frame-ancestors 'self'");
});

it('enforces the content security policy when report_only is off', function (): void {
    Config::set('security.csp.report_only', false);

    $this->get('/')
        ->assertOk()
        ->assertHeader('Content-Security-Policy')
        ->assertHeaderMissing('Content-Security-Policy-Report-Only');
});

it('sends no policy at all when the csp toggle is off', function (): void {
    Config::set('security.csp.enabled', false);

    $this->get('/')
        ->assertOk()
        ->assertHeaderMissing('Content-Security-Policy')
        ->assertHeaderMissing('Content-Security-Policy-Report-Only');
});

it('appends the report-uri at the end of the policy', function (): void {
    Config::set('security.csp.report_uri', 'https://example.test/csp');

    $response = $this->get('/')->assertOk();

    expect($response->headers->get('Content-Security-Policy-Report-Only'))
        ->toEndWith('report-uri https://example.test/csp');
});

it('sends hsts over https', function (): void {
    $this->get('https://localhost/')
        ->assertOk()
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

it('does not send hsts over plain http', function (): void {
    $this->get('http://localhost/')
        ->assertOk()
        ->assertHeaderMissing('Strict-Transport-Security');
});

it('does not send hsts when the toggle is off', function (): void {
    Config::set('security.hsts.enabled', false);

    $this->get('https://localhost/')
        ->assertOk()
        ->assertHeaderMissing('Strict-Transport-Security');
});

it('never overwrites a header the response already carries', function (): void {
    Route::get('/_test-headers', fn (): Response => response('ok')->header('X-Frame-Options', 'DENY'))
        ->middleware('web');

    $this->get('/_test-headers')
        ->assertOk()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('keeps the json health endpoint working', function (): void {
    Config::set('health.secret_token', 'un-token-secreto');

    $this->withHeaders(['X-Secret-Token' => 'un-token-secreto'])
        ->get('/health/json?fresh=1')
        ->assertOk();
});
