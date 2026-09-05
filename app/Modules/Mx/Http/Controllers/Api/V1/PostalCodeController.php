<?php

declare(strict_types=1);

namespace App\Modules\Mx\Http\Controllers\Api\V1;

use App\Core\Http\Api\Controllers\ApiController;
use App\Modules\Mx\Data\PostalCodeData;
use App\Modules\Mx\Http\Requests\Api\V1\PostalCodeLookupRequest;
use App\Modules\Mx\Http\Resources\Api\V1\PostalCodeResource;
use App\Modules\Mx\Models\PostalCode;
use App\Modules\Mx\Support\PostalCodes;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;

/**
 * El catálogo de códigos postales de SEPOMEX.
 *
 * **Sin autenticación, y a propósito.** Es un catálogo público del Servicio
 * Postal Mexicano: pedir un token para consultarlo no protegería ningún dato
 * —cualquiera puede descargarse el archivo entero— y sí rompería el caso de uso
 * que lo justifica, que es autocompletar la dirección en un formulario de alta,
 * antes de que exista una sesión. Lo que sí lleva es el `throttle:api` del grupo
 * y `api.cache`, que es lo que hace que un bot recorriendo códigos postales
 * salga caro y un formulario legítimo, gratis.
 *
 * @see docs/modules/mx.md
 * @see docs/guides/api.md
 */
#[Group('México')]
final class PostalCodeController extends ApiController
{
    /**
     * Colonias de un código postal.
     *
     * Devuelve las colonias del código postal con su municipio y su entidad. Un
     * código que no esté en el catálogo es un 404 (`not_found`); uno que no sean
     * cinco dígitos, un 422 (`validation_failed`).
     */
    #[ApiResponse(200, type: PostalCodeResource::class)]
    public function show(PostalCodeLookupRequest $request, PostalCodes $postalCodes): JsonResponse
    {
        $data = $postalCodes->lookup($request->postalCode());

        if (! $data instanceof PostalCodeData) {
            /*
             * Se lanza en vez de `abort(404)`: el renderer traduce esta
             * excepción al `not_found` del contrato, y así el endpoint no
             * conoce el status HTTP de nada. Mismo criterio que Devices.
             */
            throw (new ModelNotFoundException)->setModel(PostalCode::class);
        }

        return $this->respond(PostalCodeResource::make($data));
    }
}
