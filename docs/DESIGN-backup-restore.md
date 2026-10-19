# Design: backup and restore of Rumbos maps

Status: proposal, **not implemented**. This phase ships no backup or restore of
maps.

## What happens today

Maps (and everything about them) live in the external Rumbos service, keyed by
`(site, course id)`. Moodle backs up the course and knows nothing about the map.

When a course is restored or duplicated (duplication is backup plus restore of
the same site):

- the course keeps the `rumbos` format (verified in `tests/restore_test.php`);
- sections and activities get **new ids**, and `format_rumbos_get_course_state`
  reports the new ones correctly;
- the launch assertion carries the **new course id**, so the Rumbos service
  sees a course it has never seen;
- the app therefore starts empty, and an editor sees the "Crear el primer
  nivel" call to action (this screen belongs to the app, not to the plugin).

So the failure mode is safe and honest: nothing breaks, the copy simply has no
map. What is missing is the ability to carry a map across.

## The problem to solve

A map refers to activities by `cmid`. After a restore every `cmid` is different,
so any copy of a map must be **remapped** `old cmid -> new cmid`, otherwise it
points at activities of the original course or at nothing. Moodle exposes that
mapping only during the restore (`restore_plugin::get_mappingid('course_module',
$oldid)`).

## Options

### A. Store the map in the course format options

Keep the map in Moodle (`course_format_options`, which backup and restore
already carry).

- (+) Zero external calls at backup and restore time; works offline.
- (-) **Stored ids are not remapped.** `course_format_options` are restored as
  plain values; any `cmid` inside them is stale unless a restore plugin rewrites
  it, which means the plugin must understand the map's structure.
- (-) Puts business data and logic in a public GPL plugin, against the thin-plugin
  decision, and the editor would have to write to Moodle's database.
- (-) The map would exist twice (service and Moodle) and drift.

Rejected.

### B. Clone inside the service, driven by an event

After a restore, the plugin tells the service "course N was copied from course
M, here is the `cmid` mapping" and the service clones the map.

- (+) Nothing in the backup file; small.
- (-) Only works when the source course still exists **on the same site and the
  same service**. A backup restored on another site, or after the source was
  deleted, has nothing to clone from.
- (-) The restore must know the source course id and call the service with a
  server-to-server credential, which the contract does not have today.
- (-) A backup is no longer self-contained, which surprises administrators.

Useful as an optimisation, insufficient on its own.

### C. Export the map as an opaque blob inside the backup

At backup time the plugin asks the service for the course's map and stores the
response, untouched, in the backup file (`backup_plugin` structure). At restore
time it sends the blob back together with the `cmid` mapping, and the service
creates the map for the new course, rewriting ids.

- (+) The backup is **complete and portable**: cross-site restores, restores
  after deleting the source, and duplication all use one path.
- (+) The plugin never interprets the blob, so it stays thin and holds no
  business logic. The service owns the format and can version it.
- (+) Remapping is the service's job; the plugin only passes the mapping table.
- (-) Needs a server-to-server channel (see below) and the service being
  reachable when the backup runs, including from cron.
- (-) The blob travels through Moodle's backup storage, so its content must be
  treated as course content (it contains no user data by design; to be confirmed
  with the service).

## Recommendation

**Option C**, built so that same-site duplication goes through the same path
(it is backup plus restore anyway), with option B kept in mind as a later
optimisation for large maps.

### What C needs

1. **A server-to-server credential.** Today the plugin only signs short assertions
   for browsers. C needs the plugin to call the service from PHP. The least new
   machinery: reuse the signing key and add a claim, for example
   `role: "system"` with `scope: "backup" | "restore"`, a longer but still short
   `exp`, and the same `jti` rules. This is a **contract change** and needs the
   backend and frontend prompts to agree. It adds no secret to the plugin.
2. **Two service endpoints** (names illustrative): `GET /sites/{iss}/courses/{id}/export`
   and `POST /sites/{iss}/courses/{id}/import` taking `{blob, cmidMap}`.
3. **Plugin pieces**: `backup/moodle2/backup_format_rumbos_plugin.class.php`
   (export on backup, honouring a "include Rumbos map" setting), and the matching
   `restore_format_rumbos_plugin.class.php` (collect `cmid` mappings with
   `get_mappingid`, import in `after_restore_course`).
4. **Failure handling.** A failed export or import must not fail the whole
   backup or restore: log it, warn the user, and leave the course unmapped
   (today's behaviour).

## Open questions

- **User data.** If a course is restored *with* user data, student progress in
  the service is keyed by pseudonymous ids that depend on the Moodle `userid`.
  New users get new ids, so progress cannot be carried over without a user
  mapping. Proposal: maps only, never progress.
- **Same course restored over itself** (restore into an existing course): the
  service must decide between merge and replace; proposal is replace, with a
  warning.
- **Backup size and time** for large maps, and whether the export belongs in the
  synchronous backup or in an adhoc task.
- **Privacy.** The export is a new data flow to the external service and must be
  declared in the privacy provider when implemented.

## Decision requested

Approve option C and the `role: "system"` contract change, so the backend and
frontend work can be scheduled together with the plugin pieces above.
