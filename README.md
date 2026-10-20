# Rumbos course format for Moodle

`format_rumbos` is a Moodle course format that presents a course as an interactive
map. It is a thin client for the external Rumbos service: the plugin shows the
service's app inside the course page, tells it who is asking and what that user can
see in Moodle, and keeps the standard course content available as a fallback. It
holds no map data and no game logic.

| | |
| --- | --- |
| Component | `format_rumbos` |
| Release | 0.1.2 (alpha) |
| Moodle | 5.2 to 5.3 |
| PHP | 8.3 or later |
| Licence | GPL-3.0-or-later |

## How it works

The course page contains the Rumbos app in an iframe, above the standard course
content. A small JavaScript module on the page relays messages between the iframe and
Moodle:

1. When the app announces that it is ready, the page asks Moodle for the current
   user's course state (which activities they can see, what is restricted, what is
   complete) and for a launch assertion, and sends both to the app.
2. The launch assertion is a short-lived JWS signed with a key that belongs to the
   Moodle site. It tells the Rumbos service who is asking and with which role. The
   service verifies it with the site's public key.
3. When the app asks to open an activity, the page opens it in the same tab. The page
   only follows URLs found in its own copy of the course state, never a URL supplied
   by the app.

If the app does not answer within 8 seconds, or reports an error, the iframe is
removed and the standard course content is shown, so users never lose access to their
activities. Teachers also see a notice that explains what happened.

The course state is always computed for the user who is logged in. Nothing in the
plugin accepts a user id.

### Launch assertion

Signed with RS256. The header carries the key id (`kid`). The claims are:

| Claim | Value |
| --- | --- |
| `iss` | Moodle `wwwroot` without a trailing slash |
| `aud` | The configured audience (default `rumbos`) |
| `sub` | Pseudonymous user id: HMAC-SHA256 of the Moodle user id with a per-site secret, as 32 hexadecimal characters |
| `course` | Moodle course id |
| `role` | `editor` if the user has `format/rumbos:editmap` in the course, otherwise `student` |
| `iat`, `exp` | Issue time and expiry, 60 seconds apart |
| `jti` | 128-bit random identifier, new for every assertion |

The assertion contains no name, email address or other personal data.

## Requirements

- Moodle 5.2 or 5.3. Since Moodle 5.1 plugins live under the `public/` directory; the
  instructions below use that layout.
- PHP 8.3 or later with the OpenSSL and Sodium extensions (both are required by Moodle
  itself).
- A Rumbos service reachable over HTTPS from the users' browsers, on a different origin
  than Moodle.

## Installation

### From a ZIP file

Build the ZIP from a checkout of this repository:

```sh
scripts/build-zip.sh
```

This writes `dist/format_rumbos-<release>.zip`. The archive has a single top-level
folder named `rumbos` and contains only the files that belong to the plugin.

Then, as an administrator, open **Site administration > Plugins > Install plugins**,
upload the ZIP, and follow the prompts to complete the upgrade.

### Manually

1. Place the plugin so that `version.php` is at `public/course/format/rumbos/version.php`
   under the Moodle directory. The folder must be named `rumbos`.
2. Complete the installation from **Site administration > Notifications**, or run:

   ```sh
   php admin/cli/upgrade.php
   ```

Installation generates the site's signing key pair and the secret used for
pseudonymous ids. Both are stored encrypted with Moodle's encryption key.

### Spanish strings

The plugin includes English and Spanish strings. The Spanish ones are used when the
`es` language pack is installed on the site.

## Configuration

Open **Site administration > Plugins > Course formats > Rumbos**.

| Setting | Description |
| --- | --- |
| Enable Rumbos | Master switch, off by default. When off, Rumbos courses show only the standard Moodle content. |
| Rumbos app URL | HTTPS address of the Rumbos app, for example `https://rumbos.example.com`. The value is stored as an origin plus an optional path with no trailing slash. Anything that is not HTTPS is rejected, as are credentials, query strings and fragments. The URL must not be on the same origin as Moodle. |
| Audience | Value of the `aud` claim. It must match the value configured in the Rumbos service. Default `rumbos`. |
| Registration | Read-only panel with what the Rumbos service needs to know about this site: issuer, host origin, audience, key id, public key and its fingerprint, a registration command, and a diagnostic. See [Registering the site](#registering-the-site-in-the-rumbos-service). |

To use the format, a teacher selects **Rumbos** as the course format in the course
settings.

The capability `format/rumbos:editmap` decides who is an editor in the app. By default
it is granted to the editing teacher and manager roles.

### Rotating the key

**Rotate key** (in the registration panel) generates a new key pair after a confirmation.
The pseudonymous ids do not change. The previous public key stops working immediately,
so add the new one in the Rumbos service straight away; launches fail until you do.
The site is already registered, so the first-time `add` command would be refused: use the
service's `add-key` command with the new key file, the issuer and the new key id, all
shown in the panel (`python -m app.scripts.tenants add-key --issuer ISSUER --kid KID
--public-key-file moodle.pub.pem`; check the flags with `--help`).

## Registering the site in the Rumbos service

The Rumbos service must know the site before it accepts its assertions, and it compares
what it knows with each assertion character by character. One wrong character makes
every launch fail with the same generic error, so the plugin shows everything in one
panel, read from the code that signs the assertions rather than worked out by hand.

1. Open **Site administration > Plugins > Course formats > Rumbos** and find the
   **Registration in the Rumbos service** panel. It shows:

   | Value | Meaning |
   | --- | --- |
   | Issuer (`iss`) | Moodle's `wwwroot` in the form the plugin signs: lower-case host, no default port, no trailing slash. It can differ from the address in your browser, for example behind a proxy. |
   | Host origin | The origin of this site, which the service allows to embed the app. |
   | Audience (`aud`) | The service must be configured with the same one. |
   | Key id (`kid`) | Identifies the key in the assertion header. |
   | Fingerprint | SHA-256 of the public key, to compare with what the service reports. |
   | Public key | The PEM block, with a copy button. |

2. Copy the **registration command**, replace `<site name>` with a name for the site, and
   run it where the Rumbos backend is installed. It writes the public key to
   `moodle.pub.pem` and calls the backend's tenants command:

   ```sh
   python -m app.scripts.tenants add --name "<site name>" \
     --issuer https://moodle.example.com \
     --host-origin https://moodle.example.com \
     --kid 0123456789abcdef \
     --public-key-file moodle.pub.pem
   ```

   The command comes from the backend, not from the plugin: check its flags with
   `python -m app.scripts.tenants add --help`, because they can change until that
   command line is final.

3. Always pass `--kid`, as the panel does. Without it the backend derives a key id from
   the DER encoding of the key, which is **not** the id the plugin puts in the assertion
   header, and every launch fails because the key is unknown.

4. Make sure the backend runs with the same audience as the panel shows.

5. Compare the fingerprint, then check the registration with
   `python -m app.scripts.tenants list`.

If launches still fail, **View sample assertion (unsigned)** in the panel shows the
decoded header and claims of a fresh assertion for you, so you can compare the `iss`,
`aud` and `kid` with what you registered. It never shows the token or its signature.
The backend's `python -m app.scripts.tenants diagnose` command reads a real assertion on
standard input and says which check it fails; an assertion expires after 60 seconds.

## Network and Content Security Policy

The browser loads the app directly, so two things must allow it.

**Moodle's side.** If the Moodle site, its reverse proxy or a web application firewall
sends a `Content-Security-Policy` header that restricts frames, add the origin of the
app to `frame-src` (or to `child-src` or `default-src` if `frame-src` is not set). For
example:

```
Content-Security-Policy: frame-src 'self' https://rumbos.example.com
```

Merge this with the directives you already have.

**The app's side.** The response for the app's `/embed` page must allow Moodle to frame
it, using the origin of the Moodle site:

```
Content-Security-Policy: frame-ancestors https://moodle.example.com
```

and it must not send `X-Frame-Options: DENY` or `SAMEORIGIN`. If you operate the Rumbos
backend, `python -m app.scripts.tenants frame-ancestors` prints the header value for
every registered site.

The iframe is sandboxed with `allow-scripts allow-same-origin allow-forms` and sends no
referrer. The app needs the forms because its editor is built on them. It cannot navigate
the Moodle page, open pop-ups, show modal dialogs or download files. The page takes care
of opening activities.

## Troubleshooting

**The course shows the standard content instead of the map.** Moodle gives up on the
app after 8 seconds or when the app reports an error. Teachers see a notice with the
reason. The reason is also available on the page, as `data-state` and `data-reason` on
the element `[data-region="rumbos-container"]`:

| `data-state` | Meaning |
| --- | --- |
| `waiting` | The page is waiting for the app to announce itself |
| `ready` | The app is running |
| `degraded` | The page fell back. `data-reason` is `timeout`, `error` (a Moodle call failed), or the error code the app reported |

**A blank or refused iframe.** Open the browser's developer tools. A message about
`frame-ancestors`, `X-Frame-Options` or `frame-src` means one of the two policies
above. Also check that the app URL is reachable from the user's browser and that its
certificate is valid.

**Launches are rejected after rotating the key.** The Rumbos service still has the old
public key. Add the current one as described under [Rotating the key](#rotating-the-key).

**Launches fail with `launch_failed` right after registering the site.** Use **View
sample assertion (unsigned)** and compare its `iss`, `aud` and `kid` with the
registration. The usual causes are a site registered without `--kid`, an issuer that
differs by one character (`http` instead of `https`, `www`, a trailing slash, a
sub-path), a different audience, and clocks on the two servers that differ by more than a
few seconds, since an assertion lasts 60 seconds.

**The settings page reports that the keys cannot be decrypted.** Moodle's encryption
key file, in `dataroot/secret`, was lost or replaced, for example by restoring a
database without it. The stored private key and the secret for pseudonymous ids can no
longer be read. Use **Reset keys**. This replaces the key pair and the secret, so every
user gets a new pseudonymous id and the Rumbos service sees them as new users. Back up
`dataroot/secret` together with the database to avoid this.

A second cause needs no reset: ownership. The key file is created readable only by the
user that first needed it, so installing or upgrading the plugin from the command line as
another user than the web server's (for example `root`) leaves a key the web server
cannot read. Fix the ownership of `dataroot/secret` so the web server user owns it, and
run such commands as that user from then on, for example
`sudo -u www-data php admin/cli/upgrade.php`.

**An error about the app URL.** It must be HTTPS and on a different origin than Moodle.

## Privacy

The plugin stores no personal data in Moodle. When a user opens a Rumbos course page,
the Rumbos service receives the pseudonymous id, the course id and the role, and the
user's browser connects to it directly, so it also sees the network address and
browser details of that request. This is declared through Moodle's privacy API.
Requests to export or delete data held by the service go to its operator. See
[docs/LIFECYCLE.md](docs/LIFECYCLE.md) for what happens on install, uninstall and
course deletion, and [SECURITY.md](SECURITY.md) for the threat model and how to
report a vulnerability.

## Limitations

- **The Moodle mobile app is not supported.** It shows the default course format.
  Mobile browsers work.
- **Maps are not part of Moodle backups.** A restored or duplicated course keeps the
  format and starts without a map, because maps live in the Rumbos service. The design
  for carrying them across is in
  [docs/DESIGN-backup-restore.md](docs/DESIGN-backup-restore.md).
- **Escape leaves full screen only when the focus is on the Moodle page.** Browsers do
  not deliver key presses from inside an iframe to the page that contains it. The
  visible **Exit full screen** button always works.
- Course-level options are not available in this release.

## Development

The repository contains the plugin at its root. Moodle itself is not included.

- `dev/` holds a moodle-docker setup that mounts this repository as the plugin. Start
  with `dev/setup.sh`.
- `pnpm test` runs the JavaScript protocol tests with plain Node. PHPUnit and Behat
  run inside the moodle-docker container.
- The compiled JavaScript in `amd/build` is committed. Rebuild it after changing
  `amd/src`, with Node 22 as Moodle requires.
- [docs/CI.md](docs/CI.md) describes the continuous integration workflow and how to
  run the code checks locally. [docs/AUDIT.md](docs/AUDIT.md) records what was checked
  against the Moodle 5.2 source.

Commits follow Conventional Commits.

## Licence

Copyright 2026 OBJ Digital. Licensed under the GNU General Public License, version 3
or later. See [LICENSE](LICENSE).
