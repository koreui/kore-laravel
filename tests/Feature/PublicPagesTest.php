<?php

declare(strict_types=1);

it('renders the landing page', function (): void {
    $this->get('/')->assertOk();
});

it('links the landing page to the published docs, not to a 404', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertSee('https://github.com/koreui/kore-laravel/tree/main/docs')
        ->assertDontSee('href="/docs"', escape: false);
});

/*
 * `GET /robots.txt` devuelve 404 en la suite: el kernel HTTP de Laravel sólo
 * resuelve rutas, y `public/` lo sirve el servidor web (Nginx o `artisan
 * serve`), que aquí no interviene. Así que se comprueba el archivo tal cual,
 * que es exactamente lo que el hosting entregará.
 */
it('serves a robots.txt that hides the operational panels', function (): void {
    expect(file_get_contents(public_path('robots.txt')))
        ->toContain('Disallow: /pulse')
        ->toContain('Disallow: /health')
        ->toContain('Disallow: /users');
});
