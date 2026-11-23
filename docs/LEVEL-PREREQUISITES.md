# Level prerequisites

A student may **be enrolled** in any level, but may **see the Rumbos content** of a level only after completing the
previous level in Moodle (A1 → A2 → B1 → B2). Moodle's course completion is the source of truth. The plugin decides at
launch time: when the prerequisite is not met it does not sign the launch assertion and does not render the iframe; it
shows a message instead. The Rumbos backend only serves what the plugin signs, so nothing changes in the backend or the app.

The feature is **off by default**. Nothing changes until an administrator turns it on.

## Audit

Audited on 2026-11-20 against the `rumbos_moodle` dev site, Moodle 5.2.4+ (`MOODLE_502_STABLE`, build 20261111), PHPUnit
and the CLI report run on it. **The production site was not available: what depends on production data is marked "not
verified".**

### 1. How the launch is built today

- The iframe is rendered by `classes/output/courseformat/content.php` (`showrumbos`, then `templates/embed.mustache`).
- The assertion is signed by `classes/external/get_launch_assertion.php` → `classes/local/launch_assertion.php`. Claims: `iss`, `aud`,
  `sub` (an HMAC pseudonym of the user id), `course`, `role`, `iat`, `exp`, `jti`. No name, email or other personal data.
  **Lifetime: 60 seconds** (`launch_assertion::LIFETIME`).
- Who bypasses what: `role` is `editor` for anybody holding `format/rumbos:editmap` (archetypes `editingteacher` and `manager`)
  and `student` for everybody else. Site administrators hold every capability, so they are editors too. `access::can_use()`
  admits active enrolments, editors, and users who role-switched to a role in the course. Nothing else is bypassed or special-cased.

### 2. How completion works in each level course

- **Criteria.** On the dev site both courses have exactly one criterion, the "Manual completion by Rumbos result" role criterion
  (type 7) that `cli/configure_course.php` creates. **A1, A2 and B2 on production: not verified.** A level can be completed only
  if it has criteria, and only the levels that are somebody's predecessor must be completable (A1, A2 and B1; B2, the last, need not
  be). If a predecessor has none, "completed" can never be true: the gate treats that as a check that cannot run (closed, retry message,
  precise log line) instead of locking every student for ever, and the dry-run report shows it as `unverifiable`. Run the report to
  see which levels are not ready.
- **How a pass in Rumbos becomes a completion.** The backend queues the result in its outbox and sends it to `passback.php`; the
  receiver (`result_applier::complete()`) marks the role criterion and calls `aggregate_completions()` in the same request, so
  `course_completions.timecompleted` is set synchronously when the statement is applied (checked on a test course: `RESULT-PASSBACK-AUDIT.md`
  section 3; **not verified on the real course 10**, as that document says).
- **Delay.** The delay is the backend's: the outbox worker is meant to run from cron "every minute or so", and a transient failure
  retries with exponential backoff, 1 minute doubling to a ceiling of 6 hours, without end (`rumbos-backend/app/passback/worker.py`). In
  the normal case that is about a minute; during an outage of Moodle or the network it can be hours. Criteria other than Rumbos' (an
  activity criterion, say) are aggregated by Moodle's `completion_regular_task` cron, not at once.
- **What the student sees in between.** The Rumbos app shows their pass; Moodle has not completed the course yet, so the next level
  shows "To open <level> you first need to complete <previous level>". There is no way to speed that up from the plugin. If it matters
  after the exam, the "reconcile" step of the backend (daily) and the manual completion in Moodle are the tools.

### 3. Reading "course complete for user"

- `completion_info::is_course_complete($userid)` (`public/lib/completionlib.php:521`): builds a `completion_completion` for the user and
  course and returns `(bool) timecompleted` (`public/completion/completion_completion.php:96`). It reads the database; it does not use the
  activity completion cache.
- Its companions, used before it: `completion_info::is_enabled()` (`completionlib.php:296`, false when the site or the course has completion
  off) and `completion_info::has_criteria()` (`:393`).
- Finding a course by ID number: there is no helper. `course.idnumber` is **not unique** in the database (`public/lib/db/install.xml:117`,
  `UNIQUE="false"`); only the course form refuses a duplicate (`public/course/edit_form.php:542-546`). The gate therefore queries
  `idnumber = ? AND id <> SITEID` and requires exactly one row.
- Edge cases (all covered by `tests/local/level_gate_test.php`):
  - no course has the ID number, or two do: **closed**, retry message, log line naming the ID number;
  - the user is not enrolled in the previous level: locked, with the reason `notenrolled` in the report; no link to the course is shown
    unless `can_access_course()` says the user can open it;
  - completion granted by an administrator by hand (`completion_completion::mark_complete()`): counts as complete;
  - a completion stays after the user is unenrolled from the previous level: still counts.
  - Moodle's "Reset course" with completion data removed: **not verified**; the student would be locked again.

### 4. What else exposes level content (the Rumbos gate does not close these)

- The standard course view: the sections and activities below the map. They stay on the page, collapsed behind "View the activity list" as
  they already were (the locked message does not remove them).
- Direct activity URLs (`/mod/<name>/view.php?id=…`): governed by enrolment and the activity's own availability only.
- The course index drawer, and the web service functions that list the course for an enrolled user (`format_rumbos_get_course_state`
  returns the activity names and URLs the user can already see).
- A student who already launched keeps the Rumbos session of the backend: see "Short-lived assertions".

Closing these needs a native Moodle restriction, which is a separate decision (see 5).

### 5. Does Moodle offer a cross-course prerequisite?

Checked in `public/availability/condition/` of this version: the conditions are `completion`, `date`, `grade`, `group`, `grouping` and
`profile`. `availability_completion` resolves the activity inside the same course (`condition::load_course_structure($course)`);
`availability_grade` selects grade items of the same course. **None of them reads another course's completion.** The course completion
criterion "Courses completed" (`completion_criteria_course`) makes one course's *completion* depend on another's; it does not restrict
access. No enrolment plugin in core offers a completion prerequisite.

A native route exists but needs glue: a custom profile field (for example "level") set when a course completes, plus an
`availability_profile` condition on the activities, or enrolment into the next level only when the previous one completes. That
closes the direct URLs too, at the price of more moving parts and a field to keep in sync. **Recommendation:** keep this design for the
Rumbos launch (simplest, reads the source of truth directly, nothing to keep in sync) and decide separately whether the direct URLs
must be closed.

## Configuration

**Site administration > Plugins > Course formats > Rumbos > Level prerequisites**

| Setting | Meaning |
| --- | --- |
| `levelgate` "Require the previous level" | The feature switch. **Off by default.** |
| `levelchain` "Level chain" | The ordered course **ID numbers** (the "ID number" field of the course settings, not the numeric id), first level first, separated by commas. Example: `1000,1001,<B1>,1002`. |

The chain is validated on save: every ID number must belong to **exactly one** course, and none may repeat (a straight list can
loop back only by repeating an entry). A course that is not in the list has no prerequisite; neither has the first one. Today A1 = `1000`,
A2 = `1001`, B2 = `1002`; B1's ID number is not known yet, so the chain is configuration, not code.

### Who is never blocked

Everybody who holds `format/rumbos:editmap` in the course (editing teachers and managers) and site administrators. They can preview
and test every level. An administrator who role-switches to "Student" is judged as a student, which lets staff see the locked screen.

### The manual exception for individual students

For students who completed A1 before Rumbos existed, or by hand, there is the capability **`format/rumbos:skiplevelcheck`**
("Open a level without completing the previous one"). Nobody holds it by default.

1. Create a role once (for example "Level exception"), assignable at course level, and allow this capability in it.
2. In the level to open (for example B1), open **Participants**, edit the student's roles and add that role.
3. The student opens that level only; the exception does not carry over to the next one.

How it is recorded: it is a role assignment, so Moodle records it like any other: the `role_assigned` event in the log (who, whom,
when), visible in **Participants** and in **Check permissions**, and removed by unassigning the role (`role_unassigned` logged). No
plugin table, no plugin audit. The dry-run report counts these users as `exception`.

## Dry-run report

```
php public/course/format/rumbos/cli/level_report.php [--chain=1000,1001,B1ID,1002] [--json]
```

Read only; works with the switch off; `--chain` previews a chain before it is saved. Run it as the web server user from the Moodle
directory. It lists user ids, usernames and names: for the administrator reviewing it, not for a ticket.

Text format (dev data, chain `1000,1001`):

```
Level prerequisites: DRY RUN (nothing was changed). The switch is currently OFF.
Chain: 1000 > 1001

Level 1000 "englisha1" (course 2)
  first level: no prerequisite, nobody is blocked; active enrolments: 1

Level 1001 "a1e2e" (course 3)
  requires 1000; active enrolments: 6
  would open: 2 (completed 0, exception 0, staff 2)
  would be BLOCKED: 4
    4	stua	stua T	notenrolled
    5	stub	stub T	notenrolled
    6	stuc	stuc T	notenrolled
    8	nonedit	nonedit T	notenrolled
```

Result on the current data: **the dev site has no courses with the real ID numbers**, so the dev courses were given 1000 and 1001 for
the run and restored afterwards. The numbers above come from that scratch data and **say nothing about production**. Running it on
production is the step that matters.

## Short-lived assertions

The assertion lives **60 seconds** (the contract maximum). That window is acceptable and is not changed. But it is not the real window: the
backend exchanges the assertion for its own access token, whose default lifetime is **1800 seconds** (`access_token_ttl_seconds`, 60 to
86400, in `rumbos-backend/app/core/config.py`; the production value is **not verified**). A student who already has a session keeps
access to the Rumbos content for up to that long after the check would start failing, and the gate runs only when a page loads or a
launch is requested. If the effective window of 30 minutes is not acceptable, shorten it in the backend; it is outside this plugin.

## The locked message

A student who has not completed the previous level reads, by default (**Variant 1**):

- Spanish: *Debes completar el nivel anterior (NAME) para acceder a este nivel.*
- English: *You need to complete the previous level (NAME) to open this level.*

`NAME` is the full name of the previous course. A link to that course is shown only when the student can open it
(`can_access_course()`); no ID number and no reason for a failed check is ever shown. The same text is what the
`format_rumbos_get_launch_assertion` web service answers to a locked student.

**Changing the text.** Site administration > Plugins > Course formats > Rumbos > Level prerequisites > **Message for a
locked level** (`levellockedtext`). Plain text, at most 500 characters, no HTML (saving refuses markup). It is one text for
every language of the site. It may use `{previous}` (name of the previous level) and `{level}` (name of the level being
opened). Empty means the default above. The text is escaped when it is shown, so it can never become markup. The "could
not check your progress" message is not affected. Command line, for a text without the interface:

```
php admin/cli/cfg.php --component=format_rumbos --name=levellockedtext --set="Debes completar {previous} para acceder a {level}."
php admin/cli/cfg.php --component=format_rumbos --name=levellockedtext --set=""     # back to the default
php admin/cli/purge_caches.php
```

**Variant 2** (the owner's text, "...o la evaluación diagnóstica...") is **not true today**: a locked student gets no iframe
and cannot reach the diagnostic. Do not set it until the diagnostic-only mode exists and is deployed
([DESIGN-diagnostic-only-level.md](DESIGN-diagnostic-only-level.md) says what that needs). When it does, replace the
text in the same field; no code change is needed.

## Decisions

See D10 to D13 and D26 in [DECISIONS.md](DECISIONS.md).

## Rollout, step by step

1. Install the release. The new capability and settings appear on upgrade; the switch stays off.
2. Check that every level that has a successor has course completion criteria (Course > Course completion). Run the report: a level
   showing `unverifiable` is not ready.
3. Set the chain (**Level chain**) with the real ID numbers, including B1's. Saving refuses a missing, repeated or shared ID number.
4. Run the report with the switch still off. Review the BLOCKED lists. For legitimate cases (students who completed A1 before Rumbos or by
   hand), either mark the previous course complete for them in Moodle (the source of truth), or give them the exception.
5. Turn **Require the previous level** on. Purge nothing; the change applies on the next page load.
6. Watch the web server error log for `format_rumbos level gate failed closed`: every line says what could not be checked.

**Turning it off again:** untick **Require the previous level** (same page) and save. Effect is immediate on the next page load: every
student gets the iframe as before. Nothing is deleted: the chain and the exceptions stay for next time. Emergency without the UI:

```
php admin/cli/cfg.php --component=format_rumbos --name=levelgate --set=0
```

## What was not verified

- Production: the completion criteria of A1, A2 and B2, the real B1 ID number, the production access token lifetime, and the passback timing
  on the real course.
- The browser matrix (Chrome, Safari, a small phone) and the real teacher/admin/student pass on a running Rumbos app: see
  [MANUAL-TESTS.md](MANUAL-TESTS.md), section "Level prerequisites". The rendering is covered by PHPUnit on the course page output.
- Moodle's Reset course effect on completions.
