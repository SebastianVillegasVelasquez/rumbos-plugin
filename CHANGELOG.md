# Changelog

## Unreleased (0.6.0)

### Added

- Welcome flow progress per student, stored in Moodle: table `format_rumbos_flow`, the external functions
  `format_rumbos_get_flow_progress`, `format_rumbos_set_flow_step_done` and `format_rumbos_reset_flow_progress`, and
  the host messages of contract v5.8.
- `format_rumbos_get_quiz_result`: the student's own result in a quiz (attempts, finished attempts, an open
  attempt, percentage, pending grading and the outcome against the course threshold), never more than Moodle shows them.
- Capability `format/rumbos:resetflow` (editing teacher and manager).
- Course option "Entry quiz result threshold (%)" (0 to 100, default 80).
- Privacy provider with metadata, export and deletion, and observers that delete the progress with its course or user.

### Changed

- The plugin now stores personal data in Moodle; see the README and SECURITY.md.

### Not included

- Progress is not part of course backup and restore.

### Upgrading

1. Copy the new version and run the Moodle upgrade (`php admin/cli/upgrade.php` or the notifications page). It creates
   the table, registers the capability, the functions, the cache and the event observers.
2. Purge the caches (Site administration > Development > Purge caches) so the new strings, the JavaScript and the
   course option appear.
3. Existing courses read the threshold as 80 until it is edited in the course settings.
4. Review your privacy notice: the plugin now keeps the progress of each user in the site's database.
