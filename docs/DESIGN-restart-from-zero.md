# Audit: restart a student from zero after three failed finals (not implemented)

**Status: audit only. The restart is not built, neither in this plugin nor in the Rumbos service, and nothing should be
until the client decides what "from zero" may destroy.** Read from the code of Moodle 5.2.4 (this repository's dev site),
not from memory. Where a claim is read from code and not run, it says so.

> **Overriding a really-taken SCORM to incomplete does not hold without deleting the student's tracks.** Moodle accepts
> the override and calculates the activity complete again at its next recalculation. Observed on 2026-11-27 (below).

The product rule: after three failed final attempts the student must see all the content again from zero, through the
map and not the welcome flow, and the check-in stays consumed.

## What "from zero" has to change

Three separate states say "this student has done the content", and they live in three places:

| State | Where | Who reads it |
| --- | --- | --- |
| Activity completion | Moodle: `course_modules_completion` (state, `overrideby`, time) and `course_modules_viewed` | The map, and the Rumbos service's closing gate (`core_completion_get_activities_completion_status`) |
| What the activity itself holds | Moodle: for SCORM, `scorm_attempt` and `scorm_scoes_value` (the tracks), plus the SCORM grade | SCORM's own completion rules, the gradebook |
| Closing-flow progress | Moodle: `format_rumbos_flow` (a row per completed step) | The app |
| The check-in consumed and the three attempts | The Rumbos service (not Moodle) | The service |

## What a non-destructive reset can and cannot do

**It can** (nothing is deleted, everything is reversible by re-running the opposite operation):

1. **Clear the closing-flow rows** of the student for the map: `flow_progress::reset_map()` (or the `reset_flow_progress`
   function, which needs `format/rumbos:resetflow`). This is the same operation the editor already has for testing. It deletes
   rows that are progress, not evidence, but it does lose *when* each step was completed.
2. **Override each activity's completion to incomplete** with `completion_info::update_state($cm, COMPLETION_INCOMPLETE,
   $userid, true)`, the supported override a teacher uses.
3. Leave the result of the check-in and of the finals (`format_rumbos_result`, the audit, the grade) exactly as they are,
   which is what "the check-in stays consumed" needs on the Moodle side.

**It cannot hold for a SCORM that was really taken** (read from `lib/completionlib.php`, `update_state()`, and
`mod/scorm/classes/completion/custom_completion.php`).

Observed in a temporary PHPUnit probe on Moodle 5.2.4: a SCORM with "require status: completed", and one `completed`
track inserted for the student (no package was played). The recalculation is `update_state($cm, COMPLETION_UNKNOWN,
$userid)`.

| Step | `completionstate` | `overrideby` |
| --- | --- | --- |
| Moodle calculates from the track | 1 (complete) | none |
| Overridden to incomplete | 0 | the administrator |
| Next recalculation, the track untouched | **1 (complete) again** | none |

Why:

- An override to *incomplete* is not sticky. `update_state()` documents that only an override to complete (or complete with
  pass) is held ("per the original override design (MDL-37361), only a COMPLETE-or-better override holds"). An activity
  with automatic completion that is overridden to incomplete is recalculated normally on the next trigger.
- The recalculation reads the SCORM's tracks (`completionstatusrequired` and `completionscorerequired` look for a matching
  value in `scorm_scoes_value`). A student who finished the SCORM has those tracks, so the first thing that triggers a
  recalculation (opening the activity, which records a view and calls `update_state`) turns the state back to complete.
- So for a student who did the content, **completion cannot be reset without removing the tracks**. That is the destructive
  part, and it is not optional.
- A new SCORM attempt does not help: the rules ask for *at least one* track that meets the requirement, so old attempts keep
  the activity complete.

**It can hold** in one case (read, not run): a SCORM completed only by auto-completion (a check-in pass,
`docs/CONTENT-AUTOCOMPLETE.md`) has no tracks, so an override to incomplete is not re-derived to complete, except that
`course_modules_viewed` stays and a "must view" rule is then already met. That case is not the three-failed-finals case:
those students took the content. The same limit applies to a file, a link or a page with view completion that the student
has opened.

## The staging reset tool is not a restart

`cli/reset_test_student.php` (staging only, `docs/FINAL-EXAM-GATE.md`) deletes no evidence. It takes back only overrides
the plugin recorded, so it does nothing to a SCORM the student completed by taking it. It is for walking a test student
through the journey again, not for this rule.

## What a destructive reset deletes

For each SCORM activity of the course, for that student only (`scorm_delete_attempt()` / `scorm_delete_tracks()` in
`mod/scorm/locallib.php`):

- every `scorm_attempt` row and every `scorm_scoes_value` row of the student: **their answers, scores, status, time and
  suspend data. This is the evidence of what they did.** It cannot be rebuilt.
- the SCORM grade is recalculated by `scorm_update_grades()` and goes to none, and the gradebook history keeps its trace.
- Moodle fires `\mod_scorm\event\attempt_deleted` and the logs keep that fact, but not the data.
- the stored completion row is **not** changed by the deletion (`scorm_delete_attempt()` does not call `update_state()`),
  so the activity stays complete until it is also overridden to incomplete; both steps are needed, in that order.

Also affected, and to be decided:

- The student's course completion for the level (`course_completions`): the level gate reads it for the *next* level. A
  reset must not undo a level the student passed on another path, so it must not touch it. There is no per-user API to
  clear it except `completion_completion::delete()`, which is not a documented public method for this use.
- Do **not** use Moodle's course reset (`reset_course_userdata`): it deletes everyone's data. Do not use the privacy
  "delete data for user": it deletes all of their data in the course, including the results that back a certificate.

## Safe procedure (manual, run by an administrator)

1. **Decide, in writing, with the client and legal**, whether the SCORM evidence may be deleted at all, and for how long
   the pre-reset copy is kept (the results back a certificate; retention is already an open item in
   `classes/privacy/provider.php`).
2. **Backup first.** A course backup with user data (it includes the SCORM attempts) or, as a minimum, a dump of
   `scorm_attempt` and `scorm_scoes_value` filtered to the student and course, plus their `course_modules_completion`
   rows. Store it outside the site and record where.
3. **Dry run.** List, for the chosen students (the service says who failed three finals; Moodle cannot know), every SCORM
   activity with its attempts, its completion state and who overrode it, and the closing-flow rows. Output only; nothing
   is changed. The list is reviewed by a second person.
4. **Explicit confirmation** of exactly that list by an administrator, with the student ids and the activity ids typed
   back, not a blanket flag.
5. **Apply, per student and per activity, in this order:** delete the attempts (`scorm_delete_attempt`), override the
   completion to incomplete, then clear the closing-flow rows. One activity at a time, logging each. A failure stops the
   run for that student and leaves the backup as the way back.
6. **Verify** with the Rumbos service's own read (`core_completion_get_activities_completion_status`) and by opening the map
   as that student (staging copy first).
7. **The service side** (the three attempts, "the check-in stays consumed", sending the student to the map and not the
   welcome flow) is not Moodle's and has to be reset or left as it is by the service. The two must agree: a reset
   in Moodle without the service's side would send the student through the content with a closed final-exam gate.

## Recommendation

**Do not ship a reset before 2026-11-30. Keep it as this documented manual procedure.**

- It destroys evidence, and the decision on whether that is allowed (and the retention of the backup) is open and legal.
- Six days is not enough to build a tool that is safe by construction: the dry run, the confirmation and the per-user
  procedure need review by someone who is not the author, and a mistake cannot be undone.
- The number of students who fail three finals before that date is small and known by the service: a reviewed one-off
  script (like the bulk override described in `FINAL-EXAM-GATE.md`) run by an administrator per case costs less and risks
  less than a shipped feature.
- If the volume grows after launch, build it then as a CLI (never a web action), dry run by default, `--apply` plus the typed list,
  behind a `$CFG` opt-in like the staging helper, and only after the client approved the deletion in writing.

A cheaper product alternative, if destruction is not acceptable: do not reset. Give the student a **second copy of the
content** (a second set of SCORM activities) for the repeat run. It destroys nothing, but it doubles the content to
maintain, and the gate would have to read the second set. That is a product decision, not a plugin change.

## Together with the service's restart document

The service has its own proposal, `docs/CLOSING-RESTART.md` in the `rumbos-backend` repository (read on 2026-11-27 at
`652c29e`, branch `fix/journey-testability`). It is also a design and nothing of it is built. It follows this audit on
what Moodle can reset, and both recommend not shipping a restart before 2026-11-30.

| Point | The service's proposal | The plugin today |
| --- | --- | --- |
| How the reset reaches Moodle | A signed `cycle.restart` statement through its outbox. | Nothing handles it. A statement of an unknown `kind` is refused (`422 unknown_kind`). What the plugin may do on receiving it is not decided; this audit recommends an administrator's command with a backup and a dry run, not an automatic reaction. |
| When the new cycle opens | After the statement is `sent`. | `sent` would prove delivery only, not that completion stayed reset, which with tracks it would not. |
| A manual way that destroys nothing | Raise `assessments.final.maxAttempts`, for every student of the map; no content is redone. | Nothing to do in Moodle. This is not "from zero". |
| A manual way "from zero" | This document's safe procedure, then the same service setting. | The same. Overriding the completion cells alone is not enough for a student with tracks. |
| Moodle's course reset | Never. | The same. |

**The service's open question** was whether the plugin accepts an extra claim or a new `kind`. Extra claims are dropped.
A new `kind` is refused: only a result, a schema 2 attempt and a definition are known.

**A larger mismatch, which is not about the restart:** when this was written, the result statements the service signs
were not accepted by this plugin (`ES256`, no `exp`, `attempt_id` or `policy`; and also, not noticed then, the audience
`moodle` and a bare token as the body). The plugin has since been changed to accept them: see
[RESULT-PASSBACK.md](RESULT-PASSBACK.md#the-services-statements-es256). That does not add the restart: a `cycle.restart`
statement is a kind the plugin does not know and is refused (`422 unknown_kind` for an `ES256` statement).
