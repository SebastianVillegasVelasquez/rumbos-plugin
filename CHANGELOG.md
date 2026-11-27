# Changelog

## Unreleased

### Changed

- **Behaviour change:** the welcome flow's entry quiz now passes only **strictly above** the course's threshold
  (`passedThreshold` is `percent > quizThreshold`, it was `>=`), the product rule and the service's. A student with exactly
  the threshold no longer passes, and with a threshold of 100 nobody passes (the setting takes whole numbers). Nothing
  stored changes; the value is computed when it is read.

### Added

- Auto-complete (off by default, setting `autocompletecontent`): a verified passing result (check-in or final) marks the SCORM
  activities the student can see as complete, through Moodle's override, with an event per activity and an ad-hoc retry task.
  Only the result receiver can trigger it. The privacy declaration is updated and marked for legal review. See
  `docs/CONTENT-AUTOCOMPLETE.md`.
- `docs/DESIGN-restart-from-zero.md`: an audit of restarting a student after three failed finals. Not implemented.
- Host contract v5.11: a flow step can show a Moodle activity (`resource`, `url`, `page`) in the overlay. Capabilities `activities`
  (editors) and `activity`; messages `request-activities`, `activity-open` and their replies. No address travels in any message.
  A PDF resource shows inline in the overlay whatever its display mode. See `docs/HOST-CONTRACT.md`. New external functions
  `format_rumbos_list_flow_activities` and `format_rumbos_resolve_flow_activity` (run the upgrade).
- Level prerequisites: the message a locked student reads can be replaced in the settings ("Message for a locked level",
  plain text, 500 characters, `{previous}` and `{level}` available). The default now reads "Debes completar el nivel
  anterior (NAME) para acceder a este nivel." and the launch web service answers with the same text. See
  `docs/LEVEL-PREREQUISITES.md`.
- `docs/DESIGN-diagnostic-only-level.md`: an audit, with cost estimates, of a mode that would let a locked student open
  the welcome flow and its check-in. Not implemented.
- Launch assertion: the optional signed claim `uid` (the Moodle user id, an integer), behind the admin setting "Send the
  Moodle user id in launch assertions", **off by default**. Turn it on only after the Rumbos service accepts it. The
  privacy declaration lists it and is marked for legal review. See `docs/FINAL-EXAM-GATE.md`.
- `cli/staging_completion.php`: marks a test student's SCORM activities complete or incomplete, for staging only. It
  refuses unless config.php of the site sets `$CFG->format_rumbos_staging_tools = true`.
- `docs/FINAL-EXAM-GATE.md`: the capabilities the service account needs to read completion, the verification, the override
  path and the deploy order. No schema change, no new capability.

## 0.8.0

### Added

- Level prerequisites (off by default): admin settings `levelgate` and `levelchain` (ordered course ID numbers, validated: each must
  belong to exactly one course, no repeats); capability `format/rumbos:skiplevelcheck` for a recorded per-student exception;
  `cli/level_report.php`, a read-only dry run listing who would be blocked, by level; a "locked" message with a link to the previous level
  (only when the user can open it) and a "could not verify, try again" message with a retry link. See `docs/LEVEL-PREREQUISITES.md`.

### Changed

- The launch assertion and the course page honour the gate when it is on: no assertion and no iframe until the previous level is complete
  in Moodle; a check that cannot run fails closed. Editors and administrators are never blocked. With the switch off, nothing changes.
  Run the upgrade and purge caches after deploying.

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
