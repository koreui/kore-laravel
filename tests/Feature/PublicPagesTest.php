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
