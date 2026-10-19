<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Strings for the Rumbos course format, language 'es'.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['appurl'] = 'URL de la aplicación Rumbos';
$string['appurl_desc'] = 'Dirección HTTPS de la aplicación Rumbos, por ejemplo https://rumbos.example.com. La página del curso incrusta su página /embed en un iframe. No se aceptan parámetros de consulta ni credenciales.';
$string['audience'] = 'Audiencia';
$string['audience_desc'] = 'Valor del claim aud en las aserciones de lanzamiento. El backend de Rumbos debe estar configurado con el mismo valor.';
$string['containerlabel'] = 'Mapa del curso Rumbos';
$string['copykey'] = 'Copiar clave pública';
$string['enabled'] = 'Habilitar Rumbos';
$string['enabled_desc'] = 'Interruptor general. Si está desactivado, las páginas de curso Rumbos muestran solo el contenido estándar de Moodle.';
$string['errorappurl'] = 'Introduzca una dirección https:// válida, sin credenciales, parámetros de consulta ni fragmento.';
$string['errorappurlsameorigin'] = 'La aplicación Rumbos debe servirse desde un origen distinto al de Moodle.';
$string['errorkeydecrypt'] = 'Las claves de firma de Rumbos no se pueden descifrar con la clave de cifrado de este sitio. Probablemente se perdió o se reemplazó el archivo de clave de cifrado (dataroot/secret). Un administrador debe restablecer las claves de Rumbos.';
$string['errorkeygeneration'] = 'No se pudo generar el par de claves de firma. Compruebe que la extensión OpenSSL de PHP está disponible.';
$string['errornotenabled'] = 'Esta acción de Rumbos no está disponible: Rumbos está desactivado o no se ha configurado la URL de la aplicación.';
$string['errornotrumbos'] = 'Este curso no usa el formato Rumbos.';
$string['exitfullscreen'] = 'Salir de pantalla completa';
$string['fallback_apperror'] = 'Rumbos informó de un error (código: {$a}). Los estudiantes ven el contenido estándar del curso.';
$string['fallback_error'] = 'No se pudo cargar Rumbos en este curso porque el servicio devolvió un error. Los estudiantes ven el contenido estándar del curso.';
$string['fallback_timeout'] = 'Rumbos no respondió a tiempo. Los estudiantes ven el contenido estándar del curso. Revise la URL de la aplicación y la configuración del proxy o de la CSP de este sitio.';
$string['frametitle'] = 'Rumbos: mapa interactivo del curso';
$string['keycopied'] = 'Clave pública copiada';
$string['keyrotated'] = 'Se generó una nueva clave de firma. Registre la nueva clave pública en el backend de Rumbos; los accesos fallarán hasta que lo haga.';
$string['kid'] = 'ID de clave';
$string['newsection'] = 'Nueva sección';
$string['pluginname'] = 'Rumbos';
$string['privacy:metadata'] = 'El plugin de formato de curso Rumbos no almacena datos personales.';
$string['publickey'] = 'Clave pública';
$string['publickey_desc'] = 'Registre esta clave en el backend de Rumbos para confiar en las aserciones de lanzamiento de este sitio. Es seguro compartirla; la clave privada nunca sale de Moodle.';
$string['rotatekey'] = 'Rotar clave';
$string['rotatekey_confirm'] = '¿Generar un nuevo par de claves de firma? La clave pública actual deja de funcionar de inmediato y Rumbos rechazará los accesos de este sitio hasta que registre la nueva clave pública en el backend.';
$string['rumbos:editmap'] = 'Editar el mapa de Rumbos';
$string['section0name'] = 'General';
$string['showactivitylist'] = 'Ver la lista de actividades';
$string['skipmap'] = 'Saltar el mapa de Rumbos e ir a la lista de actividades';
