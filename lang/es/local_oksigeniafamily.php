<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Spanish strings for local_oksigeniafamily.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['cachedef_claimattempts'] = 'Intentos fallidos de código por visitante';
$string['claim:badphone'] = 'Esto no parece un número de teléfono.';
$string['claim:code'] = 'Código';
$string['claim:done'] = 'Ya eres familia de {$a->child} en {$a->site}.';
$string['claim:email'] = 'Correo (opcional)';
$string['claim:intro'] = 'Escribe el código que te ha dado el centro y tus datos. No necesitas cuenta.';
$string['claim:links'] = 'Lo que tienes ahora:';
$string['claim:nolinks'] = 'El centro te dirá cómo seguir.';
$string['claim:phone'] = 'Móvil (opcional)';
$string['claim:pin'] = 'PIN (guárdalo: solo se enseña ahora)';
$string['claim:submit'] = 'Unirme';
$string['claim:title'] = 'Únete a la familia de un alumno';
$string['claim:toomany'] = 'Demasiados intentos. Espera un rato y vuelve a probar.';
$string['claiminvalid'] = 'Este código no funciona: puede que esté mal escrito, que haya caducado o que el centro lo haya retirado.';
$string['event:adultadded'] = 'Un adulto se une a una familia';
$string['event:adultremoved'] = 'Un adulto deja una familia';
$string['event:adultupdated'] = 'Cambian los datos de un adulto';
$string['event:childlinked'] = 'Un hijo se une a una familia';
$string['event:childunlinked'] = 'Un hijo deja una familia';
$string['event:familyclosed'] = 'Familia cerrada';
$string['messageprovider:newadult'] = 'Un adulto nuevo se une a la familia de un alumno';
$string['notice:newadult'] = 'Alguien con el código de {$a->child} se ha unido a su familia como {$a->adult}. Si no lo conoces, avisa al centro para que lo quite.';
$string['notice:newadult:subject'] = '{$a->adult} se ha unido como familia de {$a->child}';
$string['notice:someone'] = 'un adulto';
$string['oksigeniafamily:manage'] = 'Gestionar las fichas de familias';
$string['pluginname'] = 'Oksigenia Family';
$string['privacy:metadata:adult'] = 'Los adultos de cada hogar, con o sin cuenta de Moodle.';
$string['privacy:metadata:adult:email'] = 'El correo del adulto, si lo dieron el centro o el propio adulto.';
$string['privacy:metadata:adult:firstname'] = 'El nombre del adulto.';
$string['privacy:metadata:adult:lastname'] = 'Los apellidos del adulto.';
$string['privacy:metadata:adult:phone'] = 'El teléfono del adulto, si lo dieron el centro o el propio adulto.';
$string['privacy:metadata:adult:userid'] = 'La cuenta de Moodle del adulto, si la tiene.';
$string['privacy:metadata:child'] = 'Los alumnos que son hijos de cada hogar.';
$string['privacy:metadata:child:status'] = 'Si el alumno sigue en la familia.';
$string['privacy:metadata:child:timeleft'] = 'Cuándo dejó el alumno la familia.';
$string['privacy:metadata:child:userid'] = 'La cuenta de Moodle del alumno.';
$string['privacy:metadata:claim'] = 'Los códigos que unen una familia a un alumno (solo se guarda su huella).';
$string['privacy:metadata:claim:userid'] = 'El alumno del código.';
$string['privacy:metadata:claim:usermodified'] = 'Quién sacó el código.';
$string['privacy:metadata:sourcekey'] = 'La clave de la persona en los datos del propio centro (como su programa de gestión).';
$string['privacy:metadata:timecreated'] = 'Cuándo se creó el registro.';
$string['settings:claimdays'] = 'Duración de los códigos (días)';
$string['settings:claimdays_desc'] = 'Cuántos días sirve el código de un alumno para unir familias desde que se saca.';
$string['settings:gracedays'] = 'Margen antes de cerrar (días)';
$string['settings:gracedays_desc'] = 'Cuánto espera una familia sin hijos antes de cerrarse. Un alumno que vuelve dentro de ese plazo encuentra su familia como estaba.';
$string['settings:parentroleid'] = 'Rol para los adultos con cuenta';
$string['settings:parentroleid_desc'] = 'El rol que se da a cada adulto con cuenta de Moodle sobre cada uno de sus hijos, como el rol de padre o de mentor de Moodle. Se quita solo cuando cualquiera de los dos se va. Ninguno: no se da rol.';
$string['settings:phoneprefix'] = 'Prefijo del país para los teléfonos';
$string['settings:phoneprefix_desc'] = 'Se añade a los números que se escriben sin él, como +34.';
$string['settings:retentiondays'] = 'Conservar las familias cerradas (días)';
$string['settings:retentiondays_desc'] = 'Cuánto se guarda una familia cerrada antes de borrarla con sus adultos.';
$string['task:sync'] = 'Mantener al día las fichas de familias';
