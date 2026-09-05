<?php

declare(strict_types=1);

namespace App\Modules\Mx\Database\Seeders;

use App\Modules\Mx\Models\State;
use Illuminate\Database\Seeder;

/**
 * Las 32 entidades federativas con su clave SAT/INEGI.
 *
 * Ésta sí va en el repositorio, al revés que el catálogo de códigos postales:
 * son treinta y dos filas, son dominio público y no cambian de una versión a
 * otra del CSV de SEPOMEX. Meterlas aquí evita que `mx_postal_codes` —cuya FK
 * apunta a `mx_states.code`— dependa de que alguien se acuerde de sembrar
 * primero: `mx:sepomex:import` llama a este seeder antes de escribir nada.
 *
 * `updateOrCreate` y no `insert`: correrlo dos veces tiene que dejar la misma
 * tabla, y así una corrección de nombre (Ciudad de México dejó de ser Distrito
 * Federal en 2016) llega con volver a sembrar.
 */
final class MxStatesSeeder extends Seeder
{
    /**
     * Clave SAT/INEGI, nombre oficial y abreviatura.
     *
     * El orden es el de la clave, que es el alfabético del nombre salvo por
     * Chiapas y Chihuahua: la lista es la del INEGI y no se reordena.
     *
     * @var list<array{code: string, name: string, abbreviation: string}>
     */
    private const array STATES = [
        ['code' => '01', 'name' => 'Aguascalientes', 'abbreviation' => 'AGS'],
        ['code' => '02', 'name' => 'Baja California', 'abbreviation' => 'BC'],
        ['code' => '03', 'name' => 'Baja California Sur', 'abbreviation' => 'BCS'],
        ['code' => '04', 'name' => 'Campeche', 'abbreviation' => 'CAMP'],
        ['code' => '05', 'name' => 'Coahuila de Zaragoza', 'abbreviation' => 'COAH'],
        ['code' => '06', 'name' => 'Colima', 'abbreviation' => 'COL'],
        ['code' => '07', 'name' => 'Chiapas', 'abbreviation' => 'CHIS'],
        ['code' => '08', 'name' => 'Chihuahua', 'abbreviation' => 'CHIH'],
        ['code' => '09', 'name' => 'Ciudad de México', 'abbreviation' => 'CDMX'],
        ['code' => '10', 'name' => 'Durango', 'abbreviation' => 'DGO'],
        ['code' => '11', 'name' => 'Guanajuato', 'abbreviation' => 'GTO'],
        ['code' => '12', 'name' => 'Guerrero', 'abbreviation' => 'GRO'],
        ['code' => '13', 'name' => 'Hidalgo', 'abbreviation' => 'HGO'],
        ['code' => '14', 'name' => 'Jalisco', 'abbreviation' => 'JAL'],
        ['code' => '15', 'name' => 'México', 'abbreviation' => 'MEX'],
        ['code' => '16', 'name' => 'Michoacán de Ocampo', 'abbreviation' => 'MICH'],
        ['code' => '17', 'name' => 'Morelos', 'abbreviation' => 'MOR'],
        ['code' => '18', 'name' => 'Nayarit', 'abbreviation' => 'NAY'],
        ['code' => '19', 'name' => 'Nuevo León', 'abbreviation' => 'NL'],
        ['code' => '20', 'name' => 'Oaxaca', 'abbreviation' => 'OAX'],
        ['code' => '21', 'name' => 'Puebla', 'abbreviation' => 'PUE'],
        ['code' => '22', 'name' => 'Querétaro', 'abbreviation' => 'QRO'],
        ['code' => '23', 'name' => 'Quintana Roo', 'abbreviation' => 'QROO'],
        ['code' => '24', 'name' => 'San Luis Potosí', 'abbreviation' => 'SLP'],
        ['code' => '25', 'name' => 'Sinaloa', 'abbreviation' => 'SIN'],
        ['code' => '26', 'name' => 'Sonora', 'abbreviation' => 'SON'],
        ['code' => '27', 'name' => 'Tabasco', 'abbreviation' => 'TAB'],
        ['code' => '28', 'name' => 'Tamaulipas', 'abbreviation' => 'TAMPS'],
        ['code' => '29', 'name' => 'Tlaxcala', 'abbreviation' => 'TLAX'],
        ['code' => '30', 'name' => 'Veracruz de Ignacio de la Llave', 'abbreviation' => 'VER'],
        ['code' => '31', 'name' => 'Yucatán', 'abbreviation' => 'YUC'],
        ['code' => '32', 'name' => 'Zacatecas', 'abbreviation' => 'ZAC'],
    ];

    public function run(): void
    {
        foreach (self::STATES as $state) {
            State::query()->updateOrCreate(['code' => $state['code']], $state);
        }
    }
}
