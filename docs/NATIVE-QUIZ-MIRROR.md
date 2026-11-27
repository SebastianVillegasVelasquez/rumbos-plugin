# Native quiz mirror

The client's contract requires Moodle's native quiz metrics (statistics, item analysis, the responses report) for the
Rumbos exams. The plugin therefore keeps, in each course and for each published version of an exam, a **hidden native
quiz** with the same questions, and records each student's Rumbos attempt in it as a **finished native attempt with the
real answers**.

It is a read-only mirror. The measurements behind the choices are in
[NATIVE-QUIZ-MIRROR-SPIKE.md](NATIVE-QUIZ-MIRROR-SPIKE.md); the decisions are D19 to D24 and D43 to D49 in
[DECISIONS.md](DECISIONS.md).

**The wire format is the service's.** The source of truth is the service's `docs/EXAM-MIRROR-EXPORT.md`, its
`contracts/schemas/` and its signed vectors (copied to `tests/fixtures/service_vectors/`).

## What it guarantees

| Rule | How |
| --- | --- |
| The service decides the score and the pass. | The mirror writes the service's marks, and the attempt's total from the service's exact percent. It never reads Moodle's total to decide anything; marks that do not add up to the percent are only flagged (`diverged`). |
| Moodle's inclusive "grade to pass" never grants completion. | The quiz has **no grade to pass and no completion rule**. Only the result grants a grade and completion, from the service's `passed` and `completes_course`. |
| One grade in the gradebook. | The quiz has a maximum grade of 0, so Moodle creates **no grade item** for it. "Rumbos result" stays the only grade. |
| Students cannot take it. | Hidden, and `mod/quiz:attempt`, `mod/quiz:view` and `mod/quiz:reviewmyattempts` are prohibited in the quiz for every student and guest role. Only the plugin writes attempts, and only on a signed statement. |
| Built from a published version. | The service sends only published versions. A changed exam has a new definition hash, so a **new quiz**; old attempts keep the quiz and questions they were taken on. |
| Idempotent. | A definition is kept once (form and hash), a course has one quiz per definition, and a service attempt is written once (unique `jti`). A resend changes nothing. |
| A failed mirror never blocks a result or a completion. | The result of an attempt record is applied first, in its own transaction. |
| No record is dropped for a reason that can be cured. | Such a refusal is answered with a status the service retries. |
| Off by default. | Setting `mirrorenabled`. |

## Statements and routing

The service posts everything to **one address**, `/course/format/rumbos/passback.php`, as a bare ES256 token
(`application/jwt`). One function verifies all of them: the signature by the configured key's type, `iss`, `aud`,
`tenant`, `jti`, `iat`; no `exp` is needed. See [RESULT-PASSBACK.md](RESULT-PASSBACK.md).

| `kind`, `schema` | What it is | What the plugin does |
| --- | --- | --- |
| none | A result (schema 1) | Applies it. Not the mirror's business. |
| `attempt`, `2` | The result claims and the attempt record | Applies the result, then gives the record to the mirror. |
| `definition`, `1` | A published exam: questions and keys. **It names no course.** | The mirror stores it. |
| anything else | | `422 unknown_kind` |

The mirror takes ES256 statements only. The development signer's RS256 form has no definitions or attempt records.

A statement that has a `preview` claim other than `false` is refused (`422 preview_not_accepted`) before anything is
applied. **The service never sends one:** it does not queue a preview attempt at all, and its statements have no such
claim. The check, and the role check (a teacher or anybody who may edit the map is `422 role_not_allowed`), are the
second line.

### What the mirror answers

The body is `{ "applied": bool, "reason": string }`. For an attempt record, `applied` says what happened to the
**result**; a mirrored one also has `"mirror": "mirrored"` or `"already_mirrored"`.

| HTTP | Reason | When | The service then |
| --- | --- | --- | --- |
| `200` | `definition_stored`, `definition_already_stored` | A definition | marks it delivered |
| `200` | the result's reason, with `mirror` | An attempt record, applied and mirrored | marks it delivered |
| `409` | `unknown_definition`, **and `"error": "unknown_definition"`** | An attempt whose definition is not stored | queues the definition again and retries the attempt. It never drops it. |
| `503` | `mirror_not_enabled` | The mirror is off | retries forever |
| `503` | `invalid_definition`, `invalid_attempt` | This version cannot read the statement. The status page says where. | retries, so a fixed plugin receives it with nobody doing anything |
| `503` | `exam_not_mirrored` | The quiz no longer has what the exam needs | retries |
| `500` | `mirror_failed` | Anything unexpected. The attempt is written in one transaction, so nothing is half written. | retries |
| `409` | `definition_conflict` | Another content under a hash already stored | parks it for an operator |
| `422` | `unknown_kind` | A definition of another schema | parks it for an operator |

A retried attempt record finds its result already applied (`already_applied`) and goes on to the mirror.

### With the mirror off

Definitions and attempt records are answered `503`. The result inside an attempt record **is** applied. But the service
sends the results of a published exam only after its definition was accepted, so **a site whose tenant has a mirror
endpoint in the service must keep the mirror on here**, or those results wait on the service until it is. Nothing is
lost while they wait. (A site that does not want a mirror asks for none: with no endpoint for the tenant the service
sends plain results. It then has no way to send them over HTTP at all, in the service as it is today.)

## How an exam becomes a quiz

A definition is stored when it arrives. Each course builds its quiz from it on the **first attempt** of that course,
with one question per question of the definition (one per scorable unit), in a question bank of the quiz.

| Service type | Moodle question | What is lost |
| --- | --- | --- |
| `mcq`, `listen_choose`, `gap_choice` | multiple choice, single answer, no shuffle | The audio. Its id is in the question text; the service sends no transcript. |
| `match_pair` | one multiple choice **per pair**, whose choices are the places | The audio, and the picture of one matching table: each clip is its own question, so each pair has its own statistics. |
| `form_fields`, `template_text`, `written_answers` | essay, plain text, graded by hand | The service sends no rubric. Its mark is the grade; Moodle does not regrade. |

The question text carries the part's instructions, the shared passage (with the id and alternative text of an image),
the prompt, the clip number or gap label, and a written task's boxes and template. The question's id number is the
service's `question_id`.

- **Maximum mark:** the question's `weight_points`, its exact share of 100, to 7 decimals.
- **Mark:** that maximum times `points_exact / max_points`, as the **service** gave them. Where Moodle's automatic mark
  of a choice differs, and for every written task, it is set by manual grading.
- **Attempt total:** the service's `percent_exact` (to the 5 decimals Moodle stores), not the sum of rounded marks. A
  difference over 0.0001 is flagged.
- **Time:** start and finish are both `finished_at`. The service sends no start time, so Moodle's duration is zero.
- Questions are pinned to the version made, so a teacher editing a question in the bank changes neither the quiz nor
  its statistics.

## Written text and the 64 KB cap

Both are **the service's**, and the plugin follows what each statement says:

- The service has a **per-tenant switch** for the text a student typed, **off by default** (its
  `mirror configure --written-text on`; its contract asks for legal review first). Off: the record has
  `written_text_included: false` and no text. The essay then has no response, the service's mark, and a comment that
  says the text was not sent. A statement that says no text travelled and has some is refused.
- The service caps a statement at **64 KB** of canonical JSON. Over it, the written text goes first
  (`truncated: true`) and an answer is never dropped. The comment on the essay then says the size limit left the text
  out. By the service's measurements no current exam can reach the cap.
- The receiver reads a body of up to 512 KB, which fits a 64 KB record in its worst encoding (tested).

## Gradebook

Audited options and the one chosen (quiz maximum grade 0, no grade item) are in the spike, section "Gradebook decision".
In short: a graded quiz would put a second, differently rounded and differently inclusive grade in the course total.

## Enabling

**Before:** the result passback is configured and verified
([production steps](RESULT-PASSBACK.md#production-configure-the-key-and-verify-with-a-real-statement)), and the legal
review of the privacy declaration (below) is done or explicitly waived for staging. **Staging first.**

1. Deploy the plugin and run the upgrade (`php admin/cli/upgrade.php`), then purge caches. It creates five tables
   (`format_rumbos_mirror_def`, `_quiz`, `_item`, `_attempt`, `_log`) and removes the old
   `resultsackdefinitions` setting.
2. Site administration > Plugins > Course formats > Rumbos: tick **Mirror exams in native quizzes** and save. The
   address shown there is the passback's.
3. On the service, with that address:
   `python -m app.scripts.mirror configure --tenant <id|issuer> --endpoint https://<moodle>/course/format/rumbos/passback.php`.
   Leave `--written-text` off. `PASSBACK_SENDER=http`.
4. Exams published **before** the endpoint was set have not been queued. Dry run, then queue:
   `python -m app.scripts.mirror sync --tenant <id|issuer>`, then the same with `--apply`. If this plugin ever
   acknowledged a definition without storing it (the removed setting), the service counts it as delivered; an attempt
   that needs it is answered `unknown_definition` and the service sends it again.

## Verifying a mirrored attempt

With one test **student** (not a teacher) in a staging course set up with `cli/configure_course.php`:

1. The student finishes a check-in of a published exam. On the service, `python -m app.scripts.passback sync-run` (or
   cron), then `python -m app.scripts.passback status`: the definition and the attempt are sent, nothing failed.
2. **Rumbos > Native quiz mirror status** (the link in the plugin's settings): one more definition, one quiz, one
   attempt, no problems, nothing diverged.
3. In the course, as a teacher, the hidden activity **"Rumbos check-in: ..."** (turn editing on if it is not listed).
   Open it:
   - **Results > Grades:** the student has one finished attempt. Its total, of 100, is the service's percent.
   - **Results > Responses** (tick "Marks for each question" and the response column): the chosen options are the ones
     the student chose. A written task shows no response while the text switch is off.
   - Click the attempt's total to **review** it: each question's mark, and on each written task the comment.
   - **Results > Statistics:** fills as attempts arrive; Moodle needs several to compute item analysis.
4. **Gradebook:** the student has "Rumbos result" and the course total, and no column for the quiz.
5. As the student, the course page does not list the quiz and its address does not open it.
6. A **failing** attempt of another test student: the course is not complete.
7. Production: the same steps, after staging passed.

## Turning it off

Untick the setting. Definitions and attempt records are then answered `503` and the service keeps them; results inside
attempt records are still applied. The quizzes, their attempts and their reports stay, hidden. Read
[With the mirror off](#with-the-mirror-off) first. To remove the data: delete the hidden quizzes in the course (Moodle
removes their attempts and questions); the plugin notices a deleted quiz and makes a new one when the next attempt of
that exam arrives. Uninstalling the plugin drops the tables.

## Status and log

The status page shows the definitions, quizzes and mirrored attempts, the attempts whose percent differs from their
marks, the problems of the last seven days and the newest problems with their reason and detail (for an unreadable
statement, which part). It records only statements that were **authentic**; an unsigned message is answered but never
written. Every statement is also in the passback's audit table, `format_rumbos_audit`.

## Security and privacy

- **The correct answers are stored in Moodle**, in the definition table and in the question bank of each hidden quiz.
  Anybody with question-bank access in that course, or database access, can read them. The exam is as secret as the
  course's roles and the site's backups.
- `format_rumbos_mirror_attempt` names the user and holds the service attempt id, the percent and the decision. It is
  declared, exported and erased by the plugin's privacy provider (and removed with the course or the user). The answers,
  text and marks are in the quiz attempt, which Moodle's own quiz provider exports and erases. The declaration is marked
  **NEEDS LEGAL REVIEW**: retention is not decided, and nothing forwards an erasure to the Rumbos service.

## Risks that remain

- **Backup and restore were not exercised.** A restored course brings back the quizzes but not the permission overrides;
  the plugin re-applies them every time the quiz is used, but until then a restored quiz is hidden only by its
  visibility. The mapping is not part of a backup, so a restored course mirrors its next attempt into a new quiz.
- **The definition's hash is not recomputed here.** It is a signed claim, and recomputing it would need PHP to write
  the same JSON as the service, byte for byte. It names the exam; the signature is what proves the content.
- **Upgrades of Moodle:** the plugin uses public, not `@api`-tagged, functions (listed in the spike). The tests are the
  alarm; run them against each new Moodle branch.
- **A teacher can edit or delete the hidden quiz.** Deleting it is detected and repaired. Editing its settings (for
  instance making it visible) is undone the next time the quiz is used, not at once.
- **Half-way failure:** a write is one database transaction; the failure answer is retryable and nothing is left
  behind. PHPUnit runs inside a transaction of its own, so this was last proven on the development site with the
  provisional format ([MANUAL-TESTS.md](MANUAL-TESTS.md#native-quiz-mirror)).
