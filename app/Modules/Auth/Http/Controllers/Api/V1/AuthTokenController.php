<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers\Api\V1;

use App\Core\Http\Api\Controllers\ApiController;
use App\Exceptions\TwoFactorRequiredException;
use App\Models\User;
use App\Modules\Auth\Actions\AuthApiTokenIssueAction;
use App\Modules\Auth\Actions\AuthApiTokenRevokeAction;
use App\Modules\Auth\Data\ApiDeviceData;
use App\Modules\Auth\Data\ApiLoginData;
use App\Modules\Auth\Http\Requests\Api\V1\LoginRequest;
use App\Modules\Auth\Http\Resources\Api\V1\ApiTokenResource;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Features;
use Laravel\Sanctum\PersonalAccessToken;
use RuntimeException;

/**
 * Emisión y retirada de tokens de API (`api/v1/auth/*`).
 *
 * El ciclo completo de una sesión de cliente: `login` la abre, `refresh` la
 * renueva sin volver a pedir la contraseña, `logout` cierra este dispositivo y
 * `logout-all` cierra todos.
 *
 * Reparto de responsabilidades (R4): aquí se decide **quién** pregunta
 * —comprobar la contraseña es autenticación, no negocio, y por eso vive en la
 * capa de entrega igual que en el `LoginRequest` de Fortify—; el caso de uso
 * —emitir un token con estas abilities y esta caducidad, retirarlo, publicar el
 * evento— vive en `AuthApiTokenIssueAction` y `AuthApiTokenRevokeAction`.
 *
 * @see docs/guides/api.md
 * @see docs/modules/auth.md
 */
#[Group('Auth')]
final class AuthTokenController extends ApiController
{
    /**
     * Iniciar sesión y obtener un token.
     *
     * Devuelve un token Bearer de Sanctum cuyas **abilities son los permisos
     * efectivos del usuario**, junto con el propio usuario para que el cliente
     * pinte su menú sin una segunda petición. El token caduca según
     * `kore-api.tokens.expires_minutes`.
     *
     * Responde 422 `validation_failed` si las credenciales no coinciden, con el
     * mismo mensaje tanto si el email no existe como si la contraseña es otra,
     * y 403 `two_factor_required` si la cuenta tiene verificación en dos pasos.
     */
    #[ApiResponse(201, type: ApiTokenResource::class)]
    public function login(LoginRequest $request, AuthApiTokenIssueAction $issue): JsonResponse
    {
        $data = $request->toData();

        $user = $this->authenticate($data);

        return $this->respond(
            ApiTokenResource::make($issue->handle($user, $this->deviceFrom($data)), $user),
            status: 201,
        );
    }

    /**
     * Renovar el token actual.
     *
     * Emite uno nuevo con los permisos **de ahora** —no los de cuando se hizo
     * login— y retira el que se usó para pedirlo, conservando el nombre del
     * dispositivo. Es la vía para que un cliente rote credenciales sin volver a
     * pedirle la contraseña a nadie.
     */
    #[ApiResponse(201, type: ApiTokenResource::class)]
    public function refresh(Request $request, AuthApiTokenIssueAction $issue, AuthApiTokenRevokeAction $revoke): JsonResponse
    {
        $user = $this->currentUser($request);
        $current = $this->currentToken($request);

        $issued = $issue->handle($user, new ApiDeviceData(name: (string) $current->name));

        $revoke->handle($user, reason: 'refresh', tokenId: (int) $current->getKey());

        return $this->respond(ApiTokenResource::make($issued, $user), status: 201);
    }

    /**
     * Cerrar la sesión de este dispositivo.
     *
     * Retira el token con el que llega la petición y deja intactos los demás.
     */
    #[ApiResponse(204)]
    public function logout(Request $request, AuthApiTokenRevokeAction $revoke): Response
    {
        $revoke->handle(
            $this->currentUser($request),
            reason: 'logout',
            tokenId: (int) $this->currentToken($request)->getKey(),
        );

        return $this->respondNoContent();
    }

    /**
     * Cerrar la sesión en todos los dispositivos.
     *
     * Retira todos los tokens del usuario, **incluido el que manda la
     * petición**: es lo que se espera de un «he perdido el teléfono».
     */
    #[ApiResponse(204)]
    public function logoutAll(Request $request, AuthApiTokenRevokeAction $revoke): Response
    {
        $revoke->handle($this->currentUser($request), reason: 'logout_all');

        return $this->respondNoContent();
    }

    /**
     * De «lo que mandó el cliente» a «lo que necesita el caso de uso».
     *
     * Los dos DTOs son planos y ninguno depende del otro (R8): traducir uno en
     * el otro es trabajo de la capa de entrega, que es la única que sabe que
     * `device_name` del cuerpo y `name` del dispositivo son la misma cosa.
     */
    private function deviceFrom(ApiLoginData $data): ApiDeviceData
    {
        return new ApiDeviceData(
            name: $data->deviceName,
            id: $data->deviceId,
            platform: $data->platform,
            appVersion: $data->appVersion,
        );
    }

    /**
     * Quién es el dueño de estas credenciales.
     *
     * El mensaje es idéntico para un email que no existe y para una contraseña
     * equivocada (R28): decir «ese correo no está registrado» convierte el login
     * en un enumerador de cuentas. El `Hash::make` de descarte cierra la misma
     * puerta por el reloj — sin él, la respuesta a un email inexistente vuelve
     * en microsegundos y el tiempo delata lo que el mensaje calla.
     *
     * El intento fallido cuenta igual en el limiter: `throttle:api-auth` corre
     * por delante del controller y suma en cada petición, salga como salga.
     */
    private function authenticate(ApiLoginData $data): User
    {
        $user = User::query()->where('email', '=', $data->email)->first();

        if (! $user instanceof User) {
            Hash::make($data->password);

            throw $this->invalidCredentials();
        }

        if (! Hash::check($data->password, (string) $user->getAuthPassword())) {
            throw $this->invalidCredentials();
        }

        $this->refuseWhenTwoFactorIsEnabled($user);

        return $user;
    }

    /**
     * `auth.failed` y no un literal propio: es la misma frase que ve quien
     * falla el login en el navegador, ya traducida en `lang/{es,en}/auth.php`.
     * Dos textos distintos para el mismo error sólo sirven para que el usuario
     * crea que le pasan dos cosas distintas.
     */
    private function invalidCredentials(): ValidationException
    {
        return ValidationException::withMessages([
            'email' => [trans('auth.failed')],
        ]);
    }

    /**
     * Una cuenta con 2FA confirmado **no** entra por la API.
     *
     * El reto por API no existe todavía (llegará con su propio endpoint), y
     * mientras no exista, aceptar email + contraseña sería publicar una puerta
     * que se salta el segundo factor que esa persona activó a propósito: la
     * API dejaría de ser una vista de la aplicación para pasar a ser su punto
     * más débil. Negarlo con un código propio —y no con un `forbidden`
     * genérico— es lo que le permite al cliente reaccionar bien: mandar a esa
     * persona al navegador en vez de reintentar.
     *
     * Se mira también el toggle: con `AUTH_2FA_ENABLED=false` no hay segundo
     * factor que respetar y un `two_factor_secret` viejo en la tabla no puede
     * dejar a nadie fuera.
     */
    private function refuseWhenTwoFactorIsEnabled(User $user): void
    {
        if (! Features::enabled(Features::twoFactorAuthentication())) {
            return;
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            throw new TwoFactorRequiredException;
        }
    }

    /**
     * Las cuatro rutas van detrás de `auth:sanctum`, así que llegar aquí sin
     * usuario es un error de cableado y no un caso de uso. Se lanza en vez de
     * abortar: el renderer lo convierte en el 500 del contrato.
     */
    private function currentUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new RuntimeException('Las rutas de api/v1/auth requieren el middleware auth:sanctum.');
        }

        return $user;
    }

    /**
     * El token con el que llega la petición.
     *
     * El `instanceof` no es defensivo por gusto: `EnsureFrontendRequestsAreStateful`
     * está en el grupo `api`, así que una petición con la cookie de sesión llega
     * autenticada y `currentAccessToken()` devuelve un `TransientToken` —que no
     * tiene id ni se puede borrar—. Sin este guard, un logout desde el propio
     * navegador reventaría con un error ilegible.
     */
    private function currentToken(Request $request): PersonalAccessToken
    {
        $token = $this->currentUser($request)->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            throw new RuntimeException('Esta ruta necesita un token de API; la sesión del navegador no tiene ninguno que revocar.');
        }

        return $token;
    }
}
