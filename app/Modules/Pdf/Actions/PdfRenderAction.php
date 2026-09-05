<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Actions;

use App\Core\Actions\Action;
use App\Core\Contracts\PdfRenderer;
use App\Core\Data\PdfBrandData;
use App\Core\Data\PdfDocumentData;
use App\Core\Data\PdfOptionsData;

/**
 * Generar un PDF a partir de una hoja Blade.
 *
 * Es el caso de uso que llaman los módulos consumidores: «este documento, con
 * esta marca, sobre este papel». Que además exista el contrato
 * {@see PdfRenderer} no la hace redundante — la Action es la que fija la
 * convención del boilerplate:
 *
 *   - la marca (`$brand`) viaja SIEMPRE en la vista bajo la clave `brand`, así
 *     que `pdf::layouts.base` la encuentra sin que cada módulo se acuerde;
 *   - `paged` va en `false`, porque lo que se está generando es el PDF y no la
 *     vista previa del navegador (la misma hoja sirve para las dos, y ésa es
 *     justo la idea);
 *   - un módulo que quiera cambiar de motor no toca nada: cambia el binding.
 *
 * La Action **no** decide si el documento se descarga, se guarda o se adjunta a
 * un correo: devuelve los bytes y quien la llamó hace lo suyo.
 */
final class PdfRenderAction extends Action
{
    public function __construct(private readonly PdfRenderer $renderer) {}

    /**
     * @param string $view Vista Blade con su namespace; normalmente extiende `pdf::layouts.base`.
     * @param array<string, mixed> $data Datos de la hoja. DTOs y arrays, nunca modelos Eloquent (R30).
     * @param PdfBrandData $brand Logos, pie, código y marca de agua. Llega a la vista como `$brand`.
     * @param PdfOptionsData $options Nombre del archivo, papel, orientación y márgenes.
     */
    public function handle(
        string $view,
        array $data,
        PdfBrandData $brand,
        PdfOptionsData $options,
    ): PdfDocumentData {
        return $this->renderer->fromView(
            $view,
            [...$data, 'brand' => $brand, 'paged' => false],
            $options,
        );
    }
}
