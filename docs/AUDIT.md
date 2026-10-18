# Audit of the Moodle 5.2 course format API

Audited on 2026-10-18 against `MOODLE_502_STABLE` (release `5.2.4+`, version
`2026042004.02`, branch `502`) and `MOODLE_503_STABLE` (version `2026100500.02`,
branch `503`), plus the developer docs at <https://moodledev.io/docs/5.2>.
The reference format is `public/course/format/topics`.

Every statement below was checked in the core code unless marked
**(docs only)** or **(not verified)**.

## 1. Things in the original brief that were wrong or imprecise

| Brief said | Reality |
| --- | --- |
| "supported/incompatible for 5.x" form unknown | `$plugin->supported = [502, 503];` (two ints, **branch numbers**, inclusive range, lowest first). `$plugin->incompatible = 504;` is a single int: the first branch that is *not* supported. Malformed values throw a `coding_exception` (`plugininfo\base`). |
| JWT library "if there is one" | Yes. `firebase/php-jwt` 6.11.1 is bundled at `public/lib/php-jwt` and autoloaded as `Firebase\JWT` (`core\component`). It is declared in `thirdpartylibs.xml`. No custom signer is needed. |
| "Moodle 5.2.0 = `2026042000`" | Correct for the 5.2 branch (`topics` ships `version 2026042000`), but `topics` itself says `requires = 2026041000`. We keep `requires = 2026042000` as requested. |
| Node for grunt | Moodle 5.2 requires Node `>=22.11 <23` (`.nvmrc`: `lts/jod`). The development machine has Node 24, so JavaScript is built **inside the container**, never with the host Node. |
| External functions "declared" generically | They live in `db/services.php`, each pointing at a class that extends `core_external\external_api` (see `core_courseformat\external\get_state`). |

## 2. File layout of a course format (5.2)

Required (docs + code):

- `version.php`
- `lib.php`: class `format_<name> extends \core_courseformat\base`
- `format.php`: included from `course/view.php`; should only call the renderer
- `lang/en/format_<name>.php` (must define `pluginname`)
- `classes/output/renderer.php`: **mandatory** for formats, unlike other
  plugin types; namespace `format_<name>\output`, extends
  `core_courseformat\output\section_renderer`, and must implement
  `section_title()` / `section_title_without_link()` if in-place editing is used.

Used by the reference format and relevant to us:

- `classes/output/courseformat/content.php` (extends
  `core_courseformat\output\local\content`; where JS is required via
  `$PAGE->requires->js_call_amd()`) and `.../content/section.php`
- `classes/privacy/provider.php` (`null_provider` in `topics`)
- `db/upgrade.php`, `settings.php`, `amd/src` + committed `amd/build`
- `tests/` (PHPUnit in `tests/`, Behat in `tests/behat/`)

`supports_components()` returning `true` enables the reactive course editor;
`uses_course_index()` and `uses_sections()` are plain booleans.

## 3. `version.php`

```php
$plugin->component = 'format_rumbos';
$plugin->version   = 2026101800;  // YYYYMMDDXX
$plugin->requires  = 2026042000;  // Moodle 5.2.0
$plugin->supported = [502, 503];
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.1.0';
```

## 4. Libraries and encryption

- **JWT:** `Firebase\JWT\JWT::encode($payload, $privatekey, 'RS256', $kid)` is
  available with no `vendor/` directory (the `vendor/` of a core checkout only
  holds dev tooling). Algorithm must be pinned by the caller. A verifier in
  tests can use `JWT::decode($jwt, new Key($publickey, 'RS256'))`.
- **`\core\encryption`:** available. Sodium secretbox, key file in
  `$CFG->dataroot/secret/`, API: `create_key()`, `key_exists()`, `get_key_file()`,
  `encrypt(string $data, ?string $method = null): string`,
  `decrypt(string $data): string`. We use it for the RSA private key and the
  HMAC secret at rest. Limitation: anyone who can read the data directory
  (key file + database) can decrypt, which is the same trust boundary as the
  rest of Moodle's secrets.

## 5. External functions

Declared in `db/services.php` with `classname`, `description`, `type`
(`read`), `ajax => true`, `loginrequired => true`. The class defines
`execute_parameters()`, `execute()` and `execute_returns()`, validates input
with `external_api::validate_parameters()` and context with
`self::validate_context()`. `core_courseformat\external\get_state` is the model.

## 6. JavaScript build

Sources in `amd/src/*.js` (ES modules), output in `amd/build/*.min.js` plus
`.min.js.map`, produced by `grunt amd`. The build output **is committed**
(`topics` does so) and `moodle-plugin-ci grunt` fails if it is stale. Because
the host has Node 24, builds run inside the Moodle container (see
`dev/` scripts).

## 7. Language strings

`string_manager_standard` loads `$plugin/lang/<code>/<component>.php` for each
language in the dependency chain (code comment: "legacy location - used by
contrib only"), and then the language-pack copy, which overrides it. So
bundling `lang/es/format_rumbos.php` works for a private plugin as long as the
`es` language pack is installed on the site. Only strings present in `en` are
kept. Decision: ship `es`. **Verified on the dev site:** `newsection` renders as
"New section" before the `es` language pack is installed and as "Nueva sección"
after installing it, so the pack is required on the site.

## 8. Development environment

- `moodle-docker` (PHP 8.3, PostgreSQL by default, MariaDB with
  `MOODLE_DOCKER_DB=mariadb`).
- The plugin repository is mounted at `public/course/format/rumbos` through
  moodle-docker's `local.yml` hook (`dev/docker/local.yml`).
- Moodle core is cloned outside this repository (`../moodle-dev`).
- Host port 8085 (8000 is commonly taken).
- On Windows with Docker Desktop, the bind-mounted Moodle tree makes
  `install_database.php` very slow (tens of minutes). This is an environment
  cost only.

## 9. CI

`moodle-plugin-ci` latest release at audit time: 4.5.11. Stable branches that
exist upstream: `MOODLE_502_STABLE` and `MOODLE_503_STABLE`. PHP 8.3 is the
minimum for both (`composer.json`). **(not verified)**: whether 5.3 officially
supports PHP 8.5.
