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
$string['copied'] = 'Copiado';
$string['copyvalue'] = 'Copiar';
$string['enabled'] = 'Habilitar Rumbos';
$string['enabled_desc'] = 'Interruptor general. Si está desactivado, las páginas de curso Rumbos muestran solo el contenido estándar de Moodle.';
$string['errorappurl'] = 'Introduzca una dirección https:// válida, sin credenciales, parámetros de consulta ni fragmento.';
$string['errorappurlsameorigin'] = 'La aplicación Rumbos debe servirse desde un origen distinto al de Moodle.';
$string['errorkeydecrypt'] = 'Las claves de firma de Rumbos no se pueden descifrar con la clave de cifrado de este sitio. Probablemente se perdió o se reemplazó el archivo de clave de cifrado (dataroot/secret). Un administrador debe restablecer las claves de Rumbos.';
$string['errorkeygeneration'] = 'No se pudo generar el par de claves de firma. Compruebe que la extensión OpenSSL de PHP está disponible.';
$string['errornewtabmodules'] = 'Introduce nombres de módulo como bigbluebuttonbn, lti separados por comas.';
$string['errornotenabled'] = 'Esta acción de Rumbos no está disponible: Rumbos está desactivado o no se ha configurado la URL de la aplicación.';
$string['errornotrumbos'] = 'Este curso no usa el formato Rumbos.';
$string['exitfullscreen'] = 'Salir de pantalla completa';
$string['fallback_apperror'] = 'Rumbos informó de un error (código: {$a}). Los estudiantes ven el contenido estándar del curso.';
$string['fallback_error'] = 'No se pudo cargar Rumbos en este curso porque el servicio devolvió un error. Los estudiantes ven el contenido estándar del curso.';
$string['fallback_timeout'] = 'Rumbos no respondió a tiempo. Los estudiantes ven el contenido estándar del curso. Revise la URL de la aplicación y la configuración del proxy o de la CSP de este sitio.';
$string['frametitle'] = 'Rumbos: mapa interactivo del curso';
$string['keyreset'] = 'Se regeneraron el par de claves de firma y el secreto de los identificadores. Registre la nueva clave pública en el backend de Rumbos. Todos los usuarios tienen ahora un nuevo identificador seudónimo.';
$string['keyrotated'] = 'Se generó una nueva clave de firma. Registre la nueva clave pública en el backend de Rumbos; los accesos fallarán hasta que lo haga.';
$string['keyunhealthy'] = 'Las claves de Rumbos almacenadas no se pueden descifrar con la clave de cifrado de este sitio (probablemente se perdió o se reemplazó el archivo de clave en dataroot/secret). Rumbos no puede firmar accesos hasta que se restablezcan las claves.';
$string['newsection'] = 'Nueva sección';
$string['newtabmodules'] = 'Módulos que se abren en pestaña nueva';
$string['newtabmodules_desc'] = 'Las actividades se abren en una ventana emergente sobre el mapa. Lista aquí, separados por comas, los módulos (por ejemplo bigbluebuttonbn, lti) cuyas actividades funcionan mal dentro de un marco y deben abrirse en una pestaña nueva. Déjalo vacío para abrirlo todo en la ventana emergente.';
$string['pluginname'] = 'Rumbos';
$string['previewassertion'] = 'Ver aserción de ejemplo (sin firma)';
$string['previewexpires'] = 'Caduca (UTC)';
$string['previewissued'] = 'Emitida (UTC)';
$string['previewwarning'] = 'Solo para diagnóstico. Es la cabecera y los claims decodificados de una aserción nueva para usted. El token y su firma no se muestran ni se conservan.';
$string['privacy:metadata:rumbos'] = 'El servicio Rumbos, un sistema externo operado fuera de Moodle, recibe los datos siguientes cuando un usuario abre una página de curso Rumbos. Las solicitudes de exportación o eliminación de esos datos deben dirigirse al operador del servicio; Moodle no conserva ninguna copia.';
$string['privacy:metadata:rumbos:courseid'] = 'El id del curso que se abre.';
$string['privacy:metadata:rumbos:ipaddress'] = 'El navegador del usuario se conecta directamente al servicio Rumbos para cargar la aplicación incrustada, por lo que el servicio también recibe la dirección de red y los datos del navegador de esa petición.';
$string['privacy:metadata:rumbos:role'] = 'Si el usuario puede editar el mapa de Rumbos (editor) o no (estudiante).';
$string['privacy:metadata:rumbos:userid'] = 'Un identificador seudónimo derivado del id de usuario de Moodle con un secreto exclusivo de este sitio (HMAC-SHA256). No contiene nombre, correo electrónico ni el id de usuario de Moodle.';
$string['regaudience'] = 'Audiencia (aud)';
$string['regaudience_note'] = 'El servicio Rumbos debe estar configurado con la misma audiencia.';
$string['regcommand'] = 'Comando de registro';
$string['regcommand_note'] = 'Ejecútelo donde está instalado el backend de Rumbos y sustituya el nombre del sitio. Los parámetros siguen la línea de comandos del backend en el momento de escribirlo: verifíquelos con --help, porque pueden cambiar hasta que sea definitiva.';
$string['regfingerprint'] = 'Huella de la clave pública (SHA-256)';
$string['regfingerprint_note'] = 'Compárela con la que el servicio informa para la clave. Para calcularla desde el archivo de la clave: openssl pkey -pubin -in moodle.pub.pem -outform DER | openssl dgst -sha256';
$string['reghostorigin'] = 'Origen del anfitrión';
$string['reghostorigin_note'] = 'El origen de este sitio, al que el servicio permite incrustar la aplicación.';
$string['regissuer'] = 'Emisor (iss)';
$string['regissuer_note'] = 'El wwwroot de Moodle tal como está configurado, en la forma que firma el plugin. Puede diferir de la dirección de la barra del navegador, por ejemplo detrás de un proxy inverso. El servicio lo compara carácter por carácter.';
$string['registration'] = 'Registro en el servicio Rumbos';
$string['registration_desc'] = 'Registre este sitio en el servicio Rumbos con los valores que se muestran aquí. Todo lo que aparece aquí es de solo lectura y nada es secreto: la clave privada nunca sale de Moodle.';
$string['regkid'] = 'ID de clave (kid)';
$string['regkid_note'] = 'Páselo de forma explícita al registrar. Sin él, el servicio deriva de la clave un id distinto y los accesos fallan por clave desconocida.';
$string['regpublickey'] = 'Clave pública (PEM)';
$string['resetkeys'] = 'Restablecer claves';
$string['resetkeys_confirm'] = '¿Regenerar el par de claves de firma Y el secreto de los identificadores seudónimos? Úselo solo porque las claves almacenadas no se pueden descifrar. Todos los usuarios recibirán un nuevo identificador seudónimo, por lo que el servicio Rumbos los tratará como nuevos, y la nueva clave pública debe registrarse en el backend.';
$string['rotatekey'] = 'Rotar clave';
$string['rotatekey_confirm'] = '¿Generar un nuevo par de claves de firma? La clave pública actual deja de funcionar de inmediato y Rumbos rechazará los accesos de este sitio hasta que registre la nueva clave pública en el backend.';
$string['rumbos:editmap'] = 'Editar el mapa de Rumbos';
$string['section0name'] = 'General';
$string['showactivitylist'] = 'Ver la lista de actividades';
$string['skipmap'] = 'Saltar el mapa de Rumbos e ir a la lista de actividades';
$string['turneditingon'] = 'Activa la edición del curso para modificar el mapa.';
