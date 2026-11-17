# Why a quiz result can say "nothing finished" (previews)

**The finding.** In Moodle an attempt is a **preview** when the person who starts it holds `mod/quiz:preview`. A preview is
not an attempt: it is flagged `quiz_attempts.preview = 1`, it never reaches the gradebook, and the quiz's own counts and
reports leave it out. A teacher, a manager, a site administrator, and anyone who holds one of those roles next to the student
role are preview users. Before contract v5.9, `format_rumbos_get_quiz_result` ignored previews and answered
`finishedAttempts: 0` with nothing to say why, so a preview user who finished the entry quiz saw it never unlock.

**What was verified and where.** Moodle 5.2.4+ (`0c7a1c05f`, 11 November 2026) in the dev site (PostgreSQL 17), a Rumbos course
with one quiz set like the production one: browser security "None", no password, no time limit, unlimited attempts, grading
"Highest grade", "Deferred feedback". Two layers: the real HTTP flow (log in, `startattempt.php`, `processattempt.php`, the AJAX
service) for a student and a teacher, and a script that calls the same core functions those pages call for every account type.
The core line numbers below are in that tree.

## How Moodle decides

| Claim | Core source (`public/` of the Moodle tree) |
| --- | --- |
| The attempt is a preview when the **current user holds `mod/quiz:preview`** in the quiz, not when they lack `mod/quiz:attempt`. | `mod/quiz/locallib.php:2051` (`$ispreviewuser = $quizobj->is_preview_user()`), `:2073` (passed to `quiz_create_attempt`), `:141-142` (`$attempt->preview = 1`); `mod/quiz/classes/quiz_settings.php:292-297` (`is_preview_user()` is `has_capability('mod/quiz:preview', ...)`) |
| Who holds it by default: `student` has `mod/quiz:attempt`; `teacher`, `editingteacher` and `manager` have `mod/quiz:preview`. | `mod/quiz/db/access.php:57-62` and `:110-117` |
| A preview user is let in without `mod/quiz:attempt` and past the access rules. | `mod/quiz/locallib.php:1963-1965` (`require_capability('mod/quiz:attempt')` only for non-preview users); `mod/quiz/startattempt.php:70` |
| The quiz page offers them "Preview quiz now" / "Continue the preview" instead of "Attempt quiz now". | `mod/quiz/view.php:236` and `:242` |
| A site administrator passes every capability check, so holds both, **unless a role is switched**. | `lib/accesslib.php:539-560` (`is_siteadmin` bypass, skipped when `$USER->access['rsw']` covers the context) |
| "Switch role to..." replaces the user's roles in that course with the chosen one (plus the default user role) for that session, so `mod/quiz:preview` goes. | `lib/accesslib.php:806-811`, `:4412-4430` (`role_switch`); the page checks `moodle/role:switchroles`: `course/switchrole.php:58` |
| Starting a new attempt **deletes the user's earlier previews**. | `mod/quiz/locallib.php:2056` and `:525-535` (`quiz_delete_previews`) |
| An unfinished attempt is **resumed whatever it is**, so a user who left a preview open and then switched role finishes the preview. | `mod/quiz/locallib.php:1976-1986` (`quiz_validate_new_attempt`, the in progress / not started branch) |
| A preview is never graded into the gradebook and fires no attempt events. | `mod/quiz/classes/quiz_attempt.php:1889` and `:1926-1927` (`if (!$this->is_preview())`) |
| "Finish attempt ..." only opens the summary page; the attempt stays `inprogress` until "Submit all and finish". | `mod/quiz/processattempt.php:62` (page -1 goes to `summary_url()`); `mod/quiz/classes/output/renderer.php:838-865` (the "Submit all and finish" button posts `finishattempt=1`); the attempt is submitted and graded in `mod/quiz/classes/quiz_attempt.php:2250-2251` |
| The quiz's own attempt count is `preview = 0`, **in any state**. | `mod/quiz/lib.php:1624-1670` (`quiz_num_attempts`, used by `quiz_num_attempt_summary`, `:1580`); the results reports: `mod/quiz/classes/local/reports/attempts_report_table.php:572-589`, `mod/quiz/report/overview/report.php:428` |
| What the plugin read before v5.9. | `quiz_get_user_attempts($quizid, $userid, 'all', false)`: `mod/quiz/lib.php:518`, `$includepreviews = false` |

## The table: every account, the same quiz

Each account: **S1** starts an attempt, answers, and presses "Finish attempt ..." (the summary page) **without** "Submit all
and finish"; **S2** goes back in and submits; **S3** starts another attempt and leaves it open. `rows` are the user's rows in
`quiz_attempts` (`state`, `preview`); the result is what `format_rumbos_get_quiz_result` answered **before v5.9** (the six v5.8
fields; `fin` is `finishedAttempts`, `att` is `attempts`, `inProg` is `inProgress`). Every account has `percent: null` except
where `pct` is given.

| Account | `mod/quiz:preview` / `:attempt` | S1: unsubmitted | S2: submitted | S3: another attempt, open |
| --- | --- | --- | --- | --- |
| student (`student1`) | no / yes | `inprogress`, preview 0: att 1, fin 0, **inProg true** | `finished`, preview 0: att 1, **fin 1**, pct 100, passed | `finished` + `inprogress`, preview 0: att 2, fin 1, inProg true, pct 100 |
| editing teacher (`teacher1`) | **yes** / no | `inprogress`, **preview 1**: att 0, fin 0, inProg false | `finished`, **preview 1**: att 0, **fin 0** | the first preview was **deleted**, a new `inprogress` preview: att 0, fin 0 |
| non-editing teacher | **yes** / no | as the editing teacher | as the editing teacher | as the editing teacher |
| manager (system role, not enrolled) | **yes** / no | as the editing teacher | as the editing teacher | as the editing teacher |
| site administrator | **yes** / yes | `inprogress`, **preview 1**: att 0, fin 0 | `finished`, **preview 1**: att 0, **fin 0** | new open preview: att 0, fin 0 |
| student **and** editing teacher in the same course | **yes** / yes | as the administrator | as the administrator | as the administrator |
| editing teacher, role **switched to Student** | no / yes | as the student | as the student | as the student |
| administrator, role **switched to Student**, no earlier preview | no / yes | as the student | as the student | as the student |
| administrator who **left a preview open**, then switched | no / yes | the **earlier preview is resumed**: preview 1, fin 0 | finished as a **preview**: att 0, **fin 0** | a new **real** attempt (preview 0); the old preview is deleted: att 1, inProg true |

**Cases that return `finishedAttempts: 0` while the user believes they finished** (the quiz page and the review page both
look normal to them):

1. **Any account with `mod/quiz:preview`**: editing teacher, non-editing teacher, manager, site administrator, and a user who also holds
   one of those roles besides the student one. Their finished quiz is a preview. S2 shows it. This is `reason`
   `cannot_attempt` (teacher, manager) or `preview_only` (administrator, dual role) since v5.9.
2. **A switched administrator (or teacher) who resumes an earlier open preview.** The role switch does not change the attempt
   that is already open.
3. **A real attempt left unsubmitted** ("Finish attempt ..." without "Submit all and finish"): S1. This one answers `inProgress:
   true`, so it is distinguishable in v5.8 too, and it is `reason: in_progress` since v5.9.

A role switched to Student and a plain student both work (S1 to S3), so the role switch is **not** a cause by itself.

## What this says about the production incident

Evidence from production: the course has one quiz (`cmid` 310) with no restriction on who can attempt it, and Moodle reports **0
attempts by 7 enrolled students**.

| Candidate | Fits? | Why |
| --- | --- | --- |
| The evaluators hold `mod/quiz:preview` (teacher, manager, administrator, or a teacher role next to the student one) | **Fits everything** | Their attempts are previews, which `quiz_num_attempts` leaves out (`preview = 0`), the result ignored, and `Comprobar de nuevo` cannot change. The behaviour is reproduced end to end over HTTP (teacher: S2 answers all zeros). |
| A real attempt left in progress ("Finish attempt ..." without "Submit all and finish") | **Contradicted, if the number came from the quiz's own count** | `quiz_num_attempts` counts `preview = 0` in **every** state, in progress included: an open real attempt makes it 1, not 0. It would also answer `inProgress: true`, not a silent zero. Not excluded if the "0" came from a report filtered to finished attempts. |
| An overdue or abandoned real attempt | Same as above | Counted by `quiz_num_attempts` too. |
| A hidden grade | No | It changes `percent`, never `finishedAttempts`; and a hidden grade needs an attempt, which "0 attempts" says there is none. |
| The quiz not visible to the user | No | The answer would be the error `not_found`, not `finishedAttempts: 0`. |

**Confirmed here:** the mechanism and that it produces exactly the reported symptom. **Not confirmed:** that the real evaluators
hold `mod/quiz:preview`. Nobody has looked at production. The steps to do it are in the README ("Reading the check in
production") and in the report that came with this change.

## What changed in the plugin

See [HOST-CONTRACT.md](HOST-CONTRACT.md#why-a-quiz-result-is-what-it-is-v59): `previewAttempts`, `canAttempt` and `reason`.
The plugin does **not** make previews count and does not change Moodle's behaviour; it explains it. The fix for a user is a
**student account**, or "Switch role to... Student" **before** opening the quiz, in the course.
