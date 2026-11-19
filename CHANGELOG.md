# Changelog

## 0.7.1

### Fixed

- Virtual tutor: the host listened for `tutor.open` / `tutor.closed` while the app sends `rumbos:tutor-open` and
  expects `rumbos:host-tutor-closed`, so the button showed and the click did nothing. The host now uses the app's names.
  Messages from the app that the host does not handle are logged once per type. Purge caches after deploying.

## 0.7.0

### Added

- Virtual tutor overlay (contract v5.10): admin setting `tutor_url` (https, the site's own host; empty = off),
  `capabilities.tutor` in `host-init`, and the app messages `rumbos:tutor-open` and `rumbos:host-tutor-closed`. The tutor opens in a
  full-viewport dialog in the Moodle page, with no camera, microphone or location. See `docs/TUTOR.md`; its
  privacy note is for legal review.

## Unreleased (0.6.1)

### Added

- Result passback: `passback.php` receives a JWS (RS256) statement from the Rumbos service, verifies it, applies it once
  (idempotent on `attempt_id`, replay protected on `jti`), keeps the best percent in a grade item and completes the course on a
  pass. Only students can complete; every statement is audited. Answers 200, 400, 401, 409, 422, 429, 503, 5xx.
- Tables `format_rumbos_result` and `format_rumbos_audit`; settings *Accept results*, issuer, tenant and keys.
- `cli/configure_course.php`: sets a course up (dry run unless `--apply`, idempotent, `--revert`).
- Privacy provider declares, exports and erases the new tables (the text needs legal review). Documents: `RESULT-PASSBACK.md`,
  `RESULT-PASSBACK-AUDIT.md`, `DECISIONS.md`.
- `dev/sign-test-statement.php`, a local signer that plays the backend.

### Notes

- Nothing is configured on upgrade. Purge the caches.

## 0.6.0

### Added

- Welcome flow progress per student, stored in Moodle: table `format_rumbos_flow`, the external functions
  `format_rumbos_get_flow_progress`, `format_rumbos_set_flow_step_done` and `format_rumbos_reset_flow_progress`, and
  the host messages of contract v5.8.
- `format_rumbos_get_quiz_result`: the student's own result in a quiz (attempts, finished attempts, an open
  attempt, percentage, pending grading and the outcome against the course threshold), never more than Moodle shows them.
- Contract v5.9: the quiz result says why it is what it is. `previewAttempts`, `canAttempt` and `reason` (`ok`,
  `no_attempts`, `in_progress`, `preview_only`, `cannot_attempt`, `not_graded_yet`) are added to it; the v5.8 fields are
  unchanged and previews are still never counted as attempts. A teacher, manager or administrator who finishes the check-in
  quiz has a preview, not an attempt, and the app can now be told so. See `docs/QUIZ-PREVIEW-ATTEMPTS.md`.
- `cli/quiz_check.php`: a read-only command line check, for the site administrator, of why a user's quiz result is what it is.
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
