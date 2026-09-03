<?php

declare(strict_types=1);

namespace App\Modules\E2E\Support;

use Illuminate\Support\Facades\File;

/**
 * Buzón de la suite: lee los correos que el mailer `log` escribió en
 * `storage/logs/e2e-mail.log`.
 *
 * Hay flujos que no se pueden probar sin abrir el correo. «Olvidé mi
 * contraseña» manda un enlace con un token que no está en la base —se guarda
 * hasheado— y el «código por email» manda un código de un solo uso que sólo
 * existe en el mensaje. Sin leer el correo, esos dos caminos se quedan fuera
 * de la suite.
 *
 * El canal es propio (`MAIL_LOG_CHANNEL=e2e_mail`, `config/logging.php`) para
 * no ir a pescar entre el ruido de `laravel.log`.
 */
final class MailLog
{
    /** Archivo del canal `e2e_mail`. */
    public static function path(): string
    {
        return storage_path('logs/e2e-mail.log');
    }

    /**
     * Último correo del buzón, ya desarmado.
     *
     * Con `$to` devuelve el último dirigido a esa dirección, y eso importa: la
     * suite corre en paralelo y varios tests mandan correos a la vez, así que
     * «el último» a secas puede ser el de otro worker. Filtrar por
     * destinatario quita esa carrera de en medio.
     *
     * @return array{to: ?string, subject: ?string, body: string, otp: ?string, links: list<string>}|null
     */
    public static function last(?string $to = null): ?array
    {
        $blocks = self::blocks();

        if ($blocks === []) {
            return null;
        }

        if ($to !== null) {
            $blocks = array_values(array_filter(
                $blocks,
                static fn (string $block): bool => stripos($block, 'To: '.$to) !== false,
            ));

            if ($blocks === []) {
                return null;
            }
        }

        $raw = end($blocks);

        // Las cabeceras se leen del bloque CRUDO y el cuerpo del decodificado.
        // No es cosmético: deshacer el quoted-printable borra los saltos de
        // línea «suaves» (`=` al final de línea), y el `?=` con el que termina
        // una cabecera codificada (`=?utf-8?Q?Y_otro_m=C3=A1s?=`) parece
        // exactamente uno de ésos. El resultado era un asunto pegado a la
        // cabecera siguiente: «Y otro =?utf-8?Q?más?MIME-Version: 1.0».
        $decoded = self::decode($raw);

        $subject = self::header($raw, 'Subject');

        preg_match_all('#https?://[^\s"\'<>\]\)]+#i', $decoded, $links);

        return [
            'to' => self::header($raw, 'To'),
            'subject' => $subject,
            'body' => $decoded,
            'otp' => self::otp($decoded, $subject),
            'links' => array_values(array_unique($links[0])),
        ];
    }

    /**
     * Una cabecera del mensaje, desplegada y decodificada.
     *
     * Las cabeceras largas se parten en varias líneas y las continuaciones
     * empiezan por espacio o tabulador (RFC 5322 §2.2.3); se vuelven a unir
     * antes de decodificar, porque una palabra codificada puede quedar partida
     * justo en medio.
     */
    private static function header(string $raw, string $name): ?string
    {
        $pattern = '/^'.preg_quote($name, '/').':[ \t]*(.*(?:\r?\n[ \t]+.*)*)/mi';

        if (preg_match($pattern, $raw, $matches) !== 1) {
            return null;
        }

        $unfolded = (string) preg_replace('/\r?\n[ \t]+/', ' ', $matches[1]);

        return self::decodeHeader(trim($unfolded));
    }

    /** Vacía el buzón: quien espera «el último correo» no quiere el de antes. */
    public static function clear(): void
    {
        File::ensureDirectoryExists(dirname(self::path()));

        File::put(self::path(), '');
    }

    /**
     * Los correos del archivo, uno por bloque y en orden de llegada.
     *
     * Cada mensaje empieza por la línea de log de Laravel
     * (`[2026-09-03 10:00:00] e2e.DEBUG: Message-ID: …`); se parte por ahí.
     *
     * @return list<string>
     */
    private static function blocks(): array
    {
        $path = self::path();

        if (! File::exists($path)) {
            return [];
        }

        $raw = (string) File::get($path);

        if (trim($raw) === '') {
            return [];
        }

        $blocks = preg_split('/^\[\d{4}-\d{2}-\d{2}[^\]]*\]\s+\S+\.\S+:/m', $raw) ?: [];

        return array_values(array_filter($blocks, static fn (string $block): bool => trim($block) !== ''));
    }

    /**
     * Deja el bloque MIME en algo que se pueda leer y devolver como JSON.
     *
     * El transporte `log` escribe el mensaje entero. El cuerpo HTML suele
     * venir en quoted-printable, así que se deshace para que un enlace partido
     * en tres líneas vuelva a ser un enlace.
     */
    private static function decode(string $block): string
    {
        $decoded = quoted_printable_decode(str_replace(["=\r\n", "=\n"], '', $block));

        // Un mensaje multiparte puede traer trozos en base64 (adjuntos, el
        // HTML alternativo). Decodificarlos como quoted-printable deja bytes
        // que no son UTF-8 válido y `response()->json()` revienta con
        // «Malformed UTF-8 characters». Se descartan: lo que la suite necesita
        // del correo son el asunto, los enlaces y el código.
        if (mb_check_encoding($decoded, 'UTF-8')) {
            return $decoded;
        }

        return mb_convert_encoding($decoded, 'UTF-8', 'UTF-8');
    }

    /**
     * Código de un solo uso de seis dígitos, si el correo trae uno.
     *
     * Se busca primero en el cuerpo en texto plano, donde el markdown mail de
     * spatie/laravel-one-time-passwords lo escribe entre asteriscos
     * (`**816155**`), y si no aparece, en el asunto («816155 is your one-time
     * login code»). Es el mismo orden que usa `tests/e2e/support/mail-log.ts`,
     * y a propósito: los dos leen el mismo archivo y tienen que coincidir.
     */
    private static function otp(string $body, ?string $subject): ?string
    {
        if (preg_match('/\*\*(\d{6})\*\*/', $body, $matches) === 1) {
            return $matches[1];
        }

        if ($subject !== null && preg_match('/(\d{6})/', $subject, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Deshace la codificación MIME de una cabecera.
     *
     * Los acentos no viajan tal cual en las cabeceras de un correo: van
     * codificados (`=?utf-8?Q?c=C3=B3digo?=`). Cualquier cliente lo deshace
     * antes de enseñarlo, así que la suite tiene que leer lo mismo que va a
     * leer la persona; si no, un asunto correcto parecería roto.
     */
    private static function decodeHeader(string $value): string
    {
        if (! str_contains($value, '=?')) {
            return $value;
        }

        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return $decoded === false ? $value : $decoded;
    }
}
