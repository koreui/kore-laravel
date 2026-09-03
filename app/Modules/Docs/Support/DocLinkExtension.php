<?php

declare(strict_types=1);

namespace App\Modules\Docs\Support;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\ExtensionInterface;
use League\CommonMark\Node\StringContainerHelper;

/**
 * La extensión de CommonMark que hace navegable la documentación dentro de la app.
 *
 * Los `.md` de `docs/` están escritos para GitHub: se enlazan entre ellos con
 * rutas relativas (`../architecture/rules.md`, `rules.md#anclas`). Servidos tal
 * cual desde `/docs/...` esos enlaces apuntan a ninguna parte, así que aquí se
 * reescriben:
 *
 * | En el Markdown             | En el visor                                        |
 * |----------------------------|----------------------------------------------------|
 * | `../architecture/rules.md` | `/docs/architecture/rules`                          |
 * | `rules.md#r11`             | `/docs/architecture/rules#r11`                      |
 * | `../../CHANGELOG.md`       | `https://github.com/…/blob/main/CHANGELOG.md`       |
 * | `https://laravel.com`      | intacto                                             |
 *
 * **Por qué una extensión y no un `preg_replace` sobre el Markdown.** Un
 * `](...)` también aparece dentro de un bloque o de un span de código —
 * `docs/audit/…` cita literalmente `` `[koreUi](../koreUi)` `` — y una regex
 * sobre el texto lo reescribiría, cambiando lo que el documento dice. Aquí se
 * trabaja sobre el árbol ya parseado, donde un enlace es un `Link` y el código
 * es código.
 *
 * De paso pone el `id` de cada encabezado (CommonMark no los genera) con el
 * mismo esquema de GitHub, que es lo que hace que las anclas entre documentos
 * —`deployment.md#logs`— sigan funcionando, y recoge los `##` para el índice
 * lateral. El slug se calcula una sola vez y se usa para las dos cosas: si se
 * calculara aparte, el índice apuntaría a anclas que no existen.
 */
final class DocLinkExtension implements ExtensionInterface
{
    /**
     * Encabezados de nivel 2, en orden de aparición.
     *
     * @var list<array{id: string, text: string}>
     */
    private array $headings = [];

    /**
     * @param string $path ruta del documento actual relativa a `docs/` y sin `.md`
     */
    public function __construct(private readonly string $path) {}

    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addEventListener(DocumentParsedEvent::class, $this->onDocumentParsed(...));
    }

    /**
     * @return list<array{id: string, text: string}>
     */
    public function headings(): array
    {
        return $this->headings;
    }

    /**
     * El destino de un enlace del Markdown, tal como debe quedar en el visor.
     */
    public function rewrite(string $target): string
    {
        $trimmed = trim($target);

        // Absolutos (`https:`, `mailto:`), protocol-relative (`//`), anclas de
        // la misma página (`#`) y rutas de la app (`/users`) se quedan como están.
        if ($trimmed === '' || preg_match('#^([a-z][a-z0-9+.\-]*:|//|\#|/)#i', $trimmed) === 1) {
            return $target;
        }

        $hash = strpos($trimmed, '#');
        $link = $hash === false ? $trimmed : substr($trimmed, 0, $hash);
        $anchor = $hash === false ? '' : substr($trimmed, $hash);

        $repositoryPath = $this->resolve($link);

        if ($repositoryPath === null) {
            return $target;
        }

        $document = $this->insideDocs($repositoryPath);

        if ($document === null) {
            // Fuera de `docs/` (el CHANGELOG, un archivo de `app/`) no hay nada
            // que servir: se manda a GitHub, que es donde vive.
            return MarkdownRenderer::githubUrl($repositoryPath).$anchor;
        }

        return ($document === 'README' ? '/docs' : '/docs/'.$document).$anchor;
    }

    /**
     * El `id` de un encabezado, con el mismo esquema que usa GitHub.
     *
     * Se conservan las letras acentuadas (`#autorización` es un ancla real de
     * `docs/modules/auth.md`) y los guiones bajos; lo demás se cae.
     */
    public static function slug(string $text): string
    {
        $slug = mb_strtolower(trim($text));
        $slug = (string) preg_replace('/[^\p{L}\p{N}\s_-]+/u', '', $slug);
        // Un espacio, un guion: GitHub no los colapsa, y «R11 · Un toggle» deja
        // dos guiones donde estaba el separador. Copiar un ancla desde GitHub
        // tiene que seguir funcionando aquí.
        $slug = (string) preg_replace('/\s/u', '-', $slug);

        return trim($slug, '-');
    }

    private function onDocumentParsed(DocumentParsedEvent $event): void
    {
        $this->headings = [];

        /** @var array<string, int> $taken */
        $taken = [];

        foreach ($event->getDocument()->iterator() as $node) {
            if ($node instanceof Link) {
                $node->setUrl($this->rewrite($node->getUrl()));

                continue;
            }

            if (! $node instanceof Heading) {
                continue;
            }

            $text = StringContainerHelper::getChildText($node);
            $slug = self::slug($text);

            if ($slug === '') {
                continue;
            }

            // Dos encabezados con el mismo texto darían la misma ancla; GitHub
            // desempata con un sufijo numérico y aquí se hace igual.
            $repeats = $taken[$slug] ?? 0;
            $taken[$slug] = $repeats + 1;
            $id = $repeats === 0 ? $slug : $slug.'-'.$repeats;

            $node->data->set('attributes/id', $id);

            if ($node->getLevel() === 2) {
                $this->headings[] = ['id' => $id, 'text' => $text];
            }
        }
    }

    /**
     * La ruta del enlace, resuelta contra el documento actual y normalizada
     * respecto a la raíz del repositorio.
     *
     * Devuelve `null` si el enlace se sale del repositorio: entonces no hay
     * reescritura posible y se deja tal cual.
     */
    private function resolve(string $link): ?string
    {
        $segments = [];

        foreach (explode('/', dirname('docs/'.$this->path).'/'.$link) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment !== '..') {
                $segments[] = $segment;

                continue;
            }

            if ($segments === []) {
                return null;
            }

            array_pop($segments);
        }

        return $segments === [] ? null : implode('/', $segments);
    }

    /**
     * El documento que el visor sabe servir, o `null` si la ruta no es un `.md`
     * de `docs/`.
     */
    private function insideDocs(string $repositoryPath): ?string
    {
        if (! str_starts_with($repositoryPath, 'docs/') || ! str_ends_with($repositoryPath, '.md')) {
            return null;
        }

        return substr($repositoryPath, strlen('docs/'), -strlen('.md'));
    }
}
