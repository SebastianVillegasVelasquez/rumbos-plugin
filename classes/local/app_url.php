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

namespace format_rumbos\local;

/**
 * Validation and normalisation of the Rumbos app URL.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class app_url {
    /**
     * Normalises a user-supplied URL to "origin + path" without trailing slash.
     *
     * Only https is accepted. User info, query strings and fragments are
     * rejected rather than silently dropped, so the stored value is exactly
     * what the administrator meant.
     *
     * @param string $url Raw input.
     * @return string|null The normalised URL, or null when the input is not acceptable.
     */
    public static function normalize(string $url): ?string {
        $url = trim($url);
        if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        if (strtolower($parts['scheme']) !== 'https') {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        $host = strtolower($parts['host']);
        if (!preg_match('/^(\[[0-9a-f:.]+\]|[a-z0-9]([a-z0-9.-]*[a-z0-9])?)$/', $host)) {
            return null;
        }
        $port = $parts['port'] ?? 443;
        if ($port < 1 || $port > 65535) {
            return null;
        }
        $path = rtrim($parts['path'] ?? '', '/');
        if ($path !== '' && !preg_match('#^(/[A-Za-z0-9._~%!$&\'()*+,;=:@-]+)+$#', $path)) {
            return null;
        }
        return 'https://' . $host . ($port === 443 ? '' : ':' . $port) . $path;
    }

    /**
     * Returns the origin (scheme://host[:port]) of a normalised URL.
     *
     * @param string $url A value returned by {@see self::normalize()}.
     * @return string
     */
    public static function origin(string $url): string {
        $parts = parse_url($url);
        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * Builds the iframe source: "{APP_URL}/embed?host={hostOrigin}".
     *
     * @param string $appurl Normalised app URL.
     * @param string $hostorigin Origin of this Moodle site.
     * @return string
     */
    public static function embed_url(string $appurl, string $hostorigin): string {
        return $appurl . '/embed?host=' . rawurlencode($hostorigin);
    }
}
