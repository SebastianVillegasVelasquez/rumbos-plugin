# Lifecycle: install, upgrade, uninstall, deletion

The plugin stores **no personal data and no maps** in Moodle's database. Its only
persistent state is plugin configuration (`config_plugins`, component
`format_rumbos`):

| Name | Contents | Sensitive |
| --- | --- | --- |
| `enabled`, `appurl`, `audience` | Admin settings | No |
| `publickey`, `kid` | Public half of the signing key | No (meant to be shared) |
| `privatekey` | Signing key, **encrypted** with `\core\encryption` | Yes |
| `hmacsecret` | Per-site secret for pseudonymous ids, **encrypted** | Yes |

## Install

`db/install.php` generates the key pair and the HMAC secret. If that fails they
are generated on first use, so a failed install hook is not fatal.

## Upgrade

No schema, no data migration. Keys are kept as they are.

## Uninstall

Moodle removes the plugin configuration (including the encrypted keys), the
capability and the language strings. Nothing else was created. Maps are **not**
deleted: they live in the Rumbos service, which keeps them until its operator
removes them. Courses that used the format fall back to Moodle's default format.

## Course deletion

Nothing is stored locally, so nothing is deleted. The map of a deleted course
stays in the Rumbos service until its operator removes it. (Notifying the service
on deletion is out of scope for this phase.)

## Backup and restore

Maps are not part of the Moodle backup in this phase. A restored or duplicated
course keeps the format and starts with no map; an editor sees "Crear el primer
nivel" in the app. See [DESIGN-backup-restore.md](DESIGN-backup-restore.md).

## Losing Moodle's encryption key

The private key and the HMAC secret are encrypted with the key file in
`$CFG->dataroot/secret`. If that file is lost or replaced (for example a database
restore without the data directory, or recreating a container without a
persistent dataroot), the secrets can no longer be decrypted:

- launches fail and pages fall back to the standard content;
- the plugin settings page shows an alert with a **Reset keys** action;
- a reset replaces the key pair **and** the HMAC secret, so every pseudonymous id
  changes and the Rumbos service sees all users as new. The new public key must
  be registered in the backend.

**Back up `dataroot/secret` together with the database.** Ordinary key rotation
keeps the HMAC secret, so ids stay stable.
