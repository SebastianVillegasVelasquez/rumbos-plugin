# Native quiz mirror

The client's contract requires Moodle's native quiz metrics (statistics, item analysis, the responses report) for the
Rumbos exams. The plugin therefore keeps, for each published version of an exam, a **hidden native quiz** with the same
questions, and records each student's Rumbos attempt in it as a **finished native attempt with the real answers**.

It is a read-only mirror. The measurements behind every choice are in
[NATIVE-QUIZ-MIRROR-SPIKE.md](NATIVE-QUIZ-MIRROR-SPIKE.md); the decisions are D19 to D24 in
[DECISIONS.md](DECISIONS.md).

> **The wire format is PROVISIONAL.** The Rumbos service has not published its export contract. The messages below
> are fixtures shaped like the B2 form A of the service's content. When the contract exists, only the code that reads
> the two payloads changes (`classes/local/mirror/exam.php` and `attempt_data.php`, each reads its shape in one
> place). Until then, **do not enable the mirror in production**: nothing sends these messages yet.

## What it guarantees

| Rule | How |
| --- | --- |
| The service decides the score and the pass. | The mirror writes the service's marks. It never reads Moodle's total to decide anything, and a total that differs from the service's percent is only flagged (`diverged`). |
| Moodle's inclusive "grade to pass" never grants completion. | The quiz has **no grade to pass and no completion rule**. Only the passback (`passback.php`) grants a grade and completion, strictly above the threshold. |
| One grade in the gradebook. | The quiz has a maximum grade of 0, so Moodle creates **no grade item** for it. The "Rumbos result" item of the passback stays the only grade. |
| Students cannot take it. | Hidden, and `mod/quiz:attempt`, `mod/quiz:view` and `mod/quiz:reviewmyattempts` are prohibited in the quiz for every student and guest role. Only the plugin writes attempts, and only on a signed message. |
| Created from a published version, never a draft. | The service sends a definition only for a published version (a contract rule; the plugin cannot tell a draft from a version). A new content version makes a **new quiz**; old attempts keep the quiz and questions they were taken on. |
| Idempotent. | A version has one quiz (unique course + form + version) and a service attempt is written once (unique attempt id). A resent message changes nothing. |
| A failed mirror never blocks the result or the completion. | A separate script, rate counter and tables. The passback of the same attempt is another request. |
| Off by default. | Setting `mirrorenabled`. |

## Messages

Both are posted to `/course/format/rumbos/mirror.php` as `{"statement": "<JWS>"}`, signed RS256 with a key the
administrator configured for the passback. The envelope is the passback's (`iss`, `aud`, `tenant`, `jti`, `iat`, `exp`;
`exp - iat` at most 300 s). The mirror trusts the **same issuer, tenant and keys** as the passback and has no keys of its
own.

| Claim | `exam_definition` | `exam_attempt` |
| --- | --- | --- |
| `kind` | `exam_definition` | `exam_attempt` |
| `course_id` | the Moodle course | the Moodle course |
| `exam` | the published version (see below) | the same version the attempt was taken on |
| `sub` | | the pseudonym of the student, as in the passback |
| `attempt` | | the attempt (see below) |

An attempt carries its exam, so it can never arrive before its quiz: the quiz is made when it is not there.

`exam`: `form_id`, `content_version` (a hash), `title`, `purpose` (`check_in` or `final`), `sections[]` (`key`, `weight`,
optional `delivery`, `items[]`). An item is `listen_choose`, `mcq` or `gap_choice` (`prompt`, `options[]` of `id` and
`text`, `key`, optional `shared` and `transcript`), `match_audio` (`clips[]`, `places[]`) or a written type
(`written_answers`, `template_text`, `form_fields`: `prompt`, `max_points`, `rubric`). See `tests/fixtures/mirror_fixture.php`.

`attempt`: `attempt_id`, `form_id`, `content_version`, `purpose`, **`preview` (must be exactly `false`)**, `started_at`,
`finished_at` (Unix seconds), `percent` (two decimals), `passed`, `items[]` (`item_id`, `points`, `response`, optional
`comment`). A preview, or a message without `preview: false`, is refused.

Answers: `200` with `applied` true (`created`, `written`) or false (`already_mirrored`, `already_written`); permanent
refusals `400`, `401`, `422` (`unknown_kind`, `unknown_course`, `unknown_user`, `not_enrolled`, `role_not_allowed`,
`preview_not_accepted`, `invalid_definition:*`, `invalid_attempt:*`); **retryable** `429`, `500`, `503`
(`not_configured`, `exam_not_mirrored`). The sender retries the retryable ones and never the others.

## How an exam becomes a quiz

One question per exam item, in a dedicated question bank of the quiz.

| Exam item | Moodle question | What is lost |
| --- | --- | --- |
| `listen_choose`, `mcq`, `gap_choice` | multiple choice, single answer, no shuffle | The audio: the transcript is in the question text. |
| `match_audio` | matching (each clip's transcript is a stem, the places are the choices; unused places are empty stems) | The audio. Counted as 5 points, as the service does. |
| `written_answers`, `template_text`, `form_fields` | essay, plain text, graded by hand | The rubric is named in the question and the service's mark is the grade; Moodle does not regrade. |

The mark of each question is `weight × points ÷ units ÷ section units` and the quiz totals 100, so the total of an
attempt is the service's percent. The marks written are the **service's**: where Moodle's automatic mark differs, the
service's is set by manual grading (always for written tasks). Questions are pinned to the version that was made, so a
teacher editing a question in the bank changes neither the quiz nor its statistics.

## Gradebook

Audited options and the one chosen (quiz maximum grade 0, no grade item) are in the spike, section "Gradebook decision".
In short: a graded quiz would put a second, differently rounded and differently inclusive grade in the course total.

## Enabling

Prerequisites: the result passback is configured (issuer, tenant, public keys) and the Rumbos service sends the two
messages. **Staging first.**

1. Deploy the plugin and run the upgrade (`php admin/cli/upgrade.php`): it creates four tables
   (`format_rumbos_mirror_quiz`, `_item`, `_attempt`, `_log`). Purge caches.
2. Site administration > Plugins > Course formats > Rumbos > "Mirror exams in native quizzes": tick it and save.
3. Give the service the address shown there (`.../course/format/rumbos/mirror.php`).
4. The service sends a definition. Check **Site administration > Plugins > Course formats > Rumbos > Native quiz mirror
   status**: one more quiz, no problems. The quiz appears in the course as a hidden activity ("Rumbos check-in: ...").
5. Send one real student's attempt. Check the quiz's reports (teacher view) and that the student's grade and
   completion did not change.
6. Production: the same steps, after staging passed and the legal review of the privacy declaration (below).

## Turning it off

Untick the setting. The receiver then answers `503 not_configured` (the sender retries later) and nothing else changes:
the quizzes, their attempts and their reports stay, hidden. To remove the data: delete the hidden quizzes in the course
(Moodle removes their attempts and questions); the plugin notices a deleted quiz and forgets its mapping the next time
that version is sent. Uninstalling the plugin drops the four tables.

## Status and log

The status page shows the number of quizzes and mirrored attempts, the attempts whose percent differs from their marks,
the problems of the last seven days and the newest problems with their reason. It records rejected and failed messages
that were **authentic**; an unsigned message is answered but never written (anybody can send one for free).

## Privacy

`format_rumbos_mirror_attempt` names the user and holds the service attempt id, the percent and the decision. It is
declared, exported and erased by the plugin's privacy provider (and removed with the course or the user). The answers
and marks are in the quiz attempt, which Moodle's own quiz provider exports and erases. The declaration is marked
**NEEDS LEGAL REVIEW**: retention is not decided, and nothing forwards an erasure to the Rumbos service.

## Risks that remain

- **Backup and restore were not exercised.** A restored course brings back the quizzes but not the permission overrides;
  the plugin re-applies them every time the quiz is used, but until then a restored quiz is hidden only by its visibility.
  The mapping is not part of a backup, so a restored course mirrors its next message into a new quiz.
- **The responses report was exercised as data, not rendered in a browser.**
- **Upgrades of Moodle**: the plugin uses public, not `@api`-tagged, functions (listed in the spike). The tests are the
  alarm; run them against each new Moodle branch.
- **A teacher can edit or delete the hidden quiz.** Deleting it is detected and repaired. Editing its settings (for
  instance making it visible) is undone the next time the quiz is used, not at once.
- **Half-way failure**: a write is one database transaction; the failure answer is retryable and nothing is left behind.
  This is proven on the dev site, not in PHPUnit (PHPUnit runs inside one transaction).
