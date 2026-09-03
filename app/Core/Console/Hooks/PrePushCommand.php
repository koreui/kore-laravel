<?php

declare(strict_types=1);

namespace App\Core\Console\Hooks;

use Igorsgm\GitHooks\Console\Commands\PrePush;

/**
 * Sustituye a `git-hooks:pre-push` de igorsgm/laravel-git-hooks 2.1.
 *
 * Git invoca el script `.git/hooks/pre-push` con dos argumentos (`remote` y
 * `url`), y el stub del paquete los reenvía tal cual con `$@`. Pero el comando
 * original declara la firma sin argumentos, así que cada `git push` moría con
 * «No arguments expected for "git-hooks:pre-push" command, got "origin"» y el
 * hook de la capa 2 nunca llegaba a correr. El stub no es configurable; lo que
 * sí podemos es registrar un comando con el mismo nombre desde el provider de
 * la app, que arranca después que el del paquete y lo reemplaza.
 *
 * Ver docs/quality/pipeline.md §Hooks.
 */
final class PrePushCommand extends PrePush
{
    /** @var string */
    protected $signature = 'git-hooks:pre-push {remote?} {url?}';
}
