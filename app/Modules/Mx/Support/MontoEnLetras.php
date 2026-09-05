<?php

declare(strict_types=1);

namespace App\Modules\Mx\Support;

use InvalidArgumentException;

/**
 * El importe en letra, en la forma que exige un documento mexicano.
 *
 * ```php
 * (new MontoEnLetras)->format(1234.56);
 * // UN MIL DOSCIENTOS TREINTA Y CUATRO PESOS 56/100 M.N.
 * ```
 *
 * Es una de las dos piezas públicas del módulo (la otra es `PostalCodes`), y es
 * **pura**: no toca la base de datos, ni la configuración, ni el reloj. Se puede
 * instanciar con `new` en un test sin arrancar la aplicación.
 *
 * ## Las tres decisiones de forma
 *
 * 1. **Los centavos van en cifra, `NN/100`.** Es la convención notarial y
 *    fiscal mexicana: el importe se escribe con letra para que no se pueda
 *    alterar, y los centavos en cifra porque «CINCUENTA Y SEIS CENTAVOS» alarga
 *    la línea sin añadir seguridad. `M.N.` («moneda nacional») cierra la
 *    fórmula.
 * 2. **Apócope: «UN» y no «UNO».** El número acompaña a un sustantivo —«UN
 *    PESO», «VEINTIÚN MIL»—, y en español el apócope es obligatorio ahí. Es
 *    también lo que hacía Notarium, de donde viene esta pieza.
 * 3. **Los millares llevan siempre su «UN»**: 1 000 es `UN MIL`, no `MIL`. En
 *    prosa corriente se diría «mil»; en un documento donde el número tiene que
 *    ser inalterable, dejar el hueco delante de «MIL» es justo lo que se evita.
 *
 * ## Lo que NO hace
 *
 * - **No concuerda en género.** Devuelve siempre la forma masculina, porque lo
 *   que acompaña es una moneda («UN PESO», «UN DÓLAR») y no un sustantivo
 *   femenino. Un derivado que necesite «UNA UNIDAD» tiene que envolverlo.
 * - **No declina la moneda.** `$currency` sale tal cual: si alguien quiere
 *   `UN PESO` en singular, pasa `'PESO'`. Poner la regla aquí obligaría a saber
 *   el plural de cualquier divisa que llegue por parámetro.
 * - **No acepta negativos** ni importes por encima de 999 999 999 999. Los dos
 *   casos lanzan `InvalidArgumentException`: un importe negativo en letra no
 *   tiene forma canónica —«MENOS CIEN PESOS» no se escribe en una escritura— y
 *   el tope es donde se acaba la escala que sabe nombrar.
 */
final class MontoEnLetras
{
    /** El mayor importe entero que sabe nombrar. */
    private const int MAX_UNITS = 999_999_999_999;

    /** @var array<int, string> 0..20, con «UN» apocopado en el 1 */
    private const array UNITS = [
        0 => 'CERO',
        1 => 'UN',
        2 => 'DOS',
        3 => 'TRES',
        4 => 'CUATRO',
        5 => 'CINCO',
        6 => 'SEIS',
        7 => 'SIETE',
        8 => 'OCHO',
        9 => 'NUEVE',
        10 => 'DIEZ',
        11 => 'ONCE',
        12 => 'DOCE',
        13 => 'TRECE',
        14 => 'CATORCE',
        15 => 'QUINCE',
        16 => 'DIECISÉIS',
        17 => 'DIECISIETE',
        18 => 'DIECIOCHO',
        19 => 'DIECINUEVE',
        20 => 'VEINTE',
    ];

    /**
     * 21..29 se escriben en una sola palabra y con tilde, así que no salen de
     * componer «VEINTE Y ...».
     *
     * @var array<int, string>
     */
    private const array TWENTIES = [
        21 => 'VEINTIÚN',
        22 => 'VEINTIDÓS',
        23 => 'VEINTITRÉS',
        24 => 'VEINTICUATRO',
        25 => 'VEINTICINCO',
        26 => 'VEINTISÉIS',
        27 => 'VEINTISIETE',
        28 => 'VEINTIOCHO',
        29 => 'VEINTINUEVE',
    ];

    /** @var array<int, string> decenas redondas a partir de 30 */
    private const array TENS = [
        3 => 'TREINTA',
        4 => 'CUARENTA',
        5 => 'CINCUENTA',
        6 => 'SESENTA',
        7 => 'SETENTA',
        8 => 'OCHENTA',
        9 => 'NOVENTA',
    ];

    /** @var array<int, string> centenas; el 100 exacto es CIEN y se resuelve aparte */
    private const array HUNDREDS = [
        1 => 'CIENTO',
        2 => 'DOSCIENTOS',
        3 => 'TRESCIENTOS',
        4 => 'CUATROCIENTOS',
        5 => 'QUINIENTOS',
        6 => 'SEISCIENTOS',
        7 => 'SETECIENTOS',
        8 => 'OCHOCIENTOS',
        9 => 'NOVECIENTOS',
    ];

    /**
     * El importe, en letra y con sus centavos en cifra.
     *
     * @param float $amount importe no negativo; se redondea a dos decimales
     * @param string $currency nombre de la moneda, tal cual va a salir
     * @param string $suffix cierre de la fórmula; vacío para omitirlo
     *
     * @throws InvalidArgumentException si el importe es negativo o se sale de escala
     */
    public function format(float $amount, string $currency = 'PESOS', string $suffix = 'M.N.'): string
    {
        /*
         * Todo el cálculo se hace en centavos enteros. Restar la parte entera
         * de un float —lo que haría `($amount - floor($amount)) * 100`— arrastra
         * el error de representación: 1234.56 - 1234 son 0.55999999999995 y no
         * 0.56, así que un truncado devolvería 55 centavos.
         */
        $cents = (int) round($amount * 100);

        if ($cents < 0) {
            throw new InvalidArgumentException('MontoEnLetras no escribe importes negativos: '.$amount);
        }

        $units = intdiv($cents, 100);

        if ($units > self::MAX_UNITS) {
            throw new InvalidArgumentException('MontoEnLetras llega hasta '.self::MAX_UNITS.' y recibió '.$amount);
        }

        $words = sprintf(
            '%s %s %02d/100 %s',
            $this->spell($units),
            $currency,
            $cents % 100,
            $suffix,
        );

        // Sin `$suffix` la fórmula se queda con un espacio de más al final.
        return trim($words);
    }

    /**
     * Un entero de 0 a 999 999 999 999, en letra.
     */
    private function spell(int $number): string
    {
        if ($number === 0) {
            return self::UNITS[0];
        }

        $millions = intdiv($number, 1_000_000);
        $rest = $number % 1_000_000;

        $parts = [];

        if ($millions > 0) {
            $parts[] = $this->spellUpToSixDigits($millions).($millions === 1 ? ' MILLÓN' : ' MILLONES');
        }

        if ($rest > 0) {
            $parts[] = $this->spellUpToSixDigits($rest);
        }

        return implode(' ', $parts);
    }

    /**
     * De 1 a 999 999: los millares y lo que queda.
     *
     * El millar siempre lleva su cuantificador delante (`UN MIL`, `VEINTIÚN
     * MIL`), que es la tercera decisión de forma del docblock de la clase.
     */
    private function spellUpToSixDigits(int $number): string
    {
        $thousands = intdiv($number, 1000);
        $rest = $number % 1000;

        $parts = [];

        if ($thousands > 0) {
            $parts[] = $this->spellUpToThreeDigits($thousands).' MIL';
        }

        if ($rest > 0) {
            $parts[] = $this->spellUpToThreeDigits($rest);
        }

        return implode(' ', $parts);
    }

    /**
     * De 1 a 999.
     */
    private function spellUpToThreeDigits(int $number): string
    {
        if ($number === 100) {
            // «CIEN» sólo cuando está solo: 101 ya es «CIENTO UN».
            return 'CIEN';
        }

        $hundreds = intdiv($number, 100);
        $rest = $number % 100;

        $parts = [];

        if ($hundreds > 0) {
            $parts[] = self::HUNDREDS[$hundreds];
        }

        if ($rest > 0) {
            $parts[] = $this->spellUpToTwoDigits($rest);
        }

        return implode(' ', $parts);
    }

    /**
     * De 1 a 99.
     */
    private function spellUpToTwoDigits(int $number): string
    {
        if ($number <= 20) {
            return self::UNITS[$number];
        }

        if ($number < 30) {
            return self::TWENTIES[$number];
        }

        $tens = self::TENS[intdiv($number, 10)];
        $unit = $number % 10;

        return $unit === 0 ? $tens : $tens.' Y '.self::UNITS[$unit];
    }
}
