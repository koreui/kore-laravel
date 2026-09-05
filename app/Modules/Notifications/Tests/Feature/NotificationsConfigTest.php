<?php

declare(strict_types=1);

use App\Core\Enums\NotificationCategory;
use App\Modules\Notifications\Support\NotificationCategories;

/*
|--------------------------------------------------------------------------
| config/kore-notifications.php
|--------------------------------------------------------------------------
|
| `kore-notifications` NO es `kore-app`: no declara capacidades sino
| parámetros, así que el check R11 no lo mira. Este archivo es su equivalente,
| igual que `DevicesConfigTest` para `config/devices.php`.
|
| Lo que se vigila es la única costura del diseño: el catálogo real vive en el
| config (para que un derivado pueda ampliarlo) y el enum de Core lista las tres
| del núcleo (para que el código pueda citarlas sin literales sueltos). Si esas
| dos listas se separan, el enum empieza a mentir.
|
*/

it('lista en el config todas las categorías del enum de Core', function (): void {
    $configured = resolve(NotificationCategories::class)->keys();

    $missing = array_values(array_diff(
        array_map(fn (NotificationCategory $category): string => $category->value, NotificationCategory::cases()),
        $configured,
    ));

    expect($missing)->toBe([], implode("\n", [
        ...$missing,
        'Estas categorías están en App\Core\Enums\NotificationCategory y no en kore-notifications.categories: el enum estaría mintiendo sobre el catálogo.',
    ]));
});

it('etiqueta cada categoría del núcleo igual que el enum', function (): void {
    $categories = resolve(NotificationCategories::class);

    foreach (NotificationCategory::cases() as $category) {
        expect($categories->label($category->value))->toBe($category->label());
    }
});

it('da a cada categoría del catálogo sus tres canales por defecto', function (): void {
    $categories = resolve(NotificationCategories::class);

    foreach ($categories->keys() as $category) {
        expect($categories->defaults($category))->toHaveKeys(['in_app', 'mail', 'push']);
    }
});

it('deja la bandeja encendida por defecto en todas las categorías', function (): void {
    // `in_app` es el canal base: apagado de fábrica, el aviso ni se guardaría y
    // la bandeja de un usuario recién creado estaría vacía para siempre.
    $categories = resolve(NotificationCategories::class);

    foreach ($categories->keys() as $category) {
        expect($categories->defaults($category)['in_app'])->toBeTrue();
    }
});

it('devuelve la clave y unos defaults conservadores para una categoría desconocida', function (): void {
    // Un aviso con la categoría mal escrita tiene que llegar igual a la
    // bandeja: perderlo por un typo sería peor que enseñarlo sin etiqueta.
    $categories = resolve(NotificationCategories::class);

    expect($categories->has('inventada'))->toBeFalse()
        ->and($categories->label('inventada'))->toBe('inventada')
        ->and($categories->defaults('inventada'))->toBe(['in_app' => true, 'mail' => false, 'push' => false]);
});

it('no se carga con un plazo de poda que borre lo de hoy', function (): void {
    expect((int) config('kore-notifications.prune_days'))->toBeGreaterThanOrEqual(1);
});

it('refresca la campana sin convertirla en un cron', function (): void {
    // Cero apaga el polling (un derivado con websockets); por debajo de diez
    // segundos son más consultas que avisos.
    $seconds = (int) config('kore-notifications.bell.poll_seconds');

    expect($seconds === 0 || $seconds >= 10)->toBeTrue();
});

it('publica las opciones del catálogo con valor y etiqueta', function (): void {
    $options = resolve(NotificationCategories::class)->options();

    expect($options)->not->toBeEmpty();

    foreach ($options as $option) {
        expect($option)->toHaveKeys(['value', 'label']);
    }
});
