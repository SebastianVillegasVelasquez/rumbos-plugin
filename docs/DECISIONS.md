# Decision log

Judgment calls made while building the plugin, with the reason, so that nobody has to rediscover them. The
newest entries are at the end. Decisions that bind other repositories are also in
[HOST-CONTRACT.md](HOST-CONTRACT.md) and [RESULT-PASSBACK.md](RESULT-PASSBACK.md).

## Result passback (assessment results from the backend)

The brief's decisions (no AI at runtime, one pass rule configured per map, three phases) are not repeated
here; only what the plugin had to decide.

### D1. The completion is not driven by the gradebook

Moodle compares "grade to pass" with `>=` (`grade_grade::is_passed()`, `completion_criteria_grade::review()`).
The backend decides `passed` with a strict "more than" that can change. A grade-based criterion would
therefore decide differently from the backend, and the course grade it compares is the **total** of every
graded item of the course (the fallback quiz included). The completion is a role criterion marked by the
receiver; the grade item is the record and its pass mark a display mirror. Evidence and alternatives:
[RESULT-PASSBACK-AUDIT.md](RESULT-PASSBACK-AUDIT.md#3-mechanisms-and-the-recommendation).

### D2. A dedicated receiver script, not a web service function

Real HTTP status codes, no session, no extra token and service user. [RESULT-PASSBACK.md](RESULT-PASSBACK.md#the-channel).

### D3. A manual grade item found by `idnumber`

Raw grades can only be written to `itemtype = 'mod'` items; `update_final_grade()` is the supported way for
any other item. Finding the item by `idnumber` survives a rename in the gradebook.

### D4. `sub` is resolved by hashing the course's enrolled users

No stored mapping from pseudonym to user: it would be new personal data and would only cover users who had
already launched. The cost is one HMAC per enrolled user per statement.

### D5. A teacher who is also a student never completes

Anybody who holds `format/rumbos:editmap` in the course, and every site administrator, is refused even when
enrolled as a student. A false refusal (a real student who also teaches the course) is fixed by an
administrator in minutes; a false completion would be a certificate that was not earned.

### D6. A course that is not set up answers `503`, not `422`

A permanent refusal would make the sender drop the result. `503` is transient: the backend keeps the
statement and the result is applied after an administrator configures the course.

### D7. The pass rule is the backend's, built as configuration

"A pass in the check-in or in the final completes the course" was given verbally. It is implemented as asked
and the rule itself lives in the backend's per-map configuration (`pass_percent`, `pass_operator`). Every
completion the receiver causes is in `format_rumbos_audit` (`reason = completed`, with the `policy` that
decided it). **To be confirmed in writing** with the client, as is the edge that the document calls 80 to 100
"A1 logrado" while exactly 80 does not pass under `gt`.

### D8. Assessment records in Moodle are not purged automatically

The audit and result tables back a certificate, like the backend's assessment records. The retention period
is open for the client and legal. Moodle's own erasure (a privacy request, a deleted user, a deleted course)
still works: see the privacy provider, which is a draft **pending legal review**.

### D9. No forwarding of an erasure request

There is no server to server channel from the plugin to the backend and none was invented. Erasure in
Moodle erases what Moodle holds; the backend's operator erases what the backend holds.

## Level prerequisites

Full audit and runbook: [LEVEL-PREREQUISITES.md](LEVEL-PREREQUISITES.md).

### D10. The gate reads Moodle's course completion at launch, and fails closed

Moodle is the source of truth for "completed". The plugin asks `completion_info::is_course_complete()` when the course page renders and
when an assertion is requested; if the answer is no, it signs nothing and renders no iframe. If the check cannot run (missing or duplicated
ID number, completion off, a predecessor without criteria, an exception) the content stays closed and the student sees "No pudimos verificar
tu avance, inténtalo de nuevo" with a retry link; the precise cause goes to the error log. Showing the content on failure would turn every
misconfiguration into a silent bypass. Switch off = today's behaviour.

### D11. The manual exception is a capability, assigned as a role

`format/rumbos:skiplevelcheck`, held by nobody by default, given to one student in one course through a role. Moodle already records who
assigned what to whom and when (`role_assigned` / `role_unassigned` events), shows it in Participants and Check permissions, and
needs no admin page. A plugin table would have added a schema, a privacy export and a screen to build for the same audit trail. The
exception covers that level only.

### D12. Staff are never blocked; an administrator who role-switches is judged as a student

Anybody holding `format/rumbos:editmap` (editing teachers, managers) and site administrators bypass the gate, so staff can preview and
test every level. The check is `has_capability()`, which honours role switching: an administrator who switches to "Student" sees the locked
screen, which is how it can be tested.

### D13. The locked page keeps the standard content; direct URLs stay open

The message replaces the map only. The standard section list stays below, collapsed as it already was; direct activity URLs and the
web service listing are governed by enrolment and are not closed. Closing them needs a native Moodle restriction (Moodle 5.2 has no
cross-course condition), which is the owner's decision. Also note the real window after a lock: the backend swaps the 60-second assertion
for an access token that lasts 1800 s by default, so a student already inside keeps access up to that long.

## Final-exam gate support (the `uid` claim, the closing flow, the override, the staging helper)

Audit before building, from `main` at `8ac6428`. The reasoning and the set-up are in
[FINAL-EXAM-GATE.md](FINAL-EXAM-GATE.md).

### D14. `uid` is a signed claim behind a setting that is off

The service has to ask Moodle about one user and `sub` cannot be turned back into an id. The agreed approach is one
signed claim. It is behind an admin setting, default off, instead of always on: the order "service first" is then
enforced by the administrator's tick box and a rollback is unticking it. The service's code (`app/auth/launch.py`)
accepts an extra claim, but that is the source of a branch, not proof of what is deployed. The privacy cost is real:
with `uid` the pseudonym no longer hides the Moodle account from the service. The declaration says so and is marked
for legal review.

### D15. The closing flow uses the welcome flow's table unchanged

`format_rumbos_flow` is keyed by course, user, map and step key; it has no flow column. The service refuses a step key
shared by the two flows, and the closing app writes through the same messages. A `flow` column would need an upgrade
step, a new unique key, a change in the app and in the service, and would buy nothing the key does not already give. So
nothing was added to the schema, and tests pin the use (`tests/local/closing_flow_test.php`). "Seen" and "completed" are
not distinguished; a recorded escape is `fallback` (or `unavailable`) and is kept against a later `completed`.

### D16. The request assumed an audit trail and a backup that the welcome flow does not have

The welcome flow logs no event and its table is not in a course backup. The closing flow is "audited like the welcome
flow" (the row) and has no backup, for the same reason: a restored course is a new course and a new map. Neither was
invented for one flow only.

**Known debt (handed over, not fixed here).** The welcome flow has no audit trail beyond its row (no Moodle event) and
its progress is not part of a course backup or restore. The owner decided not to change the welcome flow's behaviour
for this; the closing flow inherits both gaps. If either is wanted later, it should be done for both flows at once.

### D17. The override is Moodle's own, not a plugin feature

A plugin flag would only change the gate if the service learned it through a new signal, a second source of truth that
could drift from the course's completion. Moodle's "Override" in the activity completion report already records who did
it (`overrideby`), logs an event, needs `moodle/course:overridecompletion`, and the service sees it through the same
read it already makes. The plugin adds nothing. A bulk override on production is a reviewed one-off, not a shipped tool.

### D18. The staging helper is inert unless config.php opts in

It ships with the plugin (`cli/`), so a production site has the file. It therefore refuses unless `$CFG` carries
`format_rumbos_staging_tools === true`, a line only a staging site has; there is no flag on the command line. It uses
Moodle's override as the administrator, is a dry run unless `--apply` is given, and accepts only an active student.

### D26. The locked message defaults to the text that is true today, and the override is plain text

The owner proposed a text that promises completing the level by its diagnostic. A locked student cannot reach the
diagnostic (no launch assertion, no iframe), so that text would be false. The product owner decides the copy; until the
diagnostic-only mode exists the default is Variant 1 ("Debes completar el nivel anterior para acceder a este nivel"), with
the previous level's name added in parentheses because the task asks for it. The override is one site-wide plain text
(`levellockedtext`), no HTML, 500 characters, with `{previous}` and `{level}` replaced in a single pass, and escaped when
shown, so an administrator cannot add markup and a course name that contains a placeholder is not expanded again. One text,
not one per language: the language packs are not editable from the plugin, and a second setting per language would be
two places to keep in step. The web service answer uses the same text so the page and the app do not disagree.
Course names are now taken unescaped from `format_string()` (`escape => false`) because the template escapes once;
before, a name with `&` was shown as `&amp;`.

### D27. Moodle activities in a flow reuse the map popup, and the messages keep the `rumbos:` names

The proposal named the messages `activities.list` and `activity.open`. The contract already speaks `rumbos:kebab-case`
(and a bare `tutor.open` was once dropped in silence because the app sent `rumbos:tutor-open`), so the new ones are
`rumbos:request-activities`, `rumbos:activity-open` and `rumbos:host-activities`, `rumbos:host-activity-opened`,
`rumbos:host-activity-open-failed`, `rumbos:host-activity-closed`. The shape is the proposal's. The overlay is the existing
activity popup, not a third copy of the tutor overlay: it already has the close button, focus trap, Escape, focus
restore, inert background and scroll lock, and it is what drops the site chrome on the framed page. The tutor overlay is
the pattern it follows. No new dependency.

### D28. A file resource is shown inline by a plugin page that calls the module's own `resource_view()`

Three display modes (new window, force download, in pop-up) and the frameset mode show nothing useful in a frame. For a PDF or
a web image the overlay loads `resource.php` instead of `view.php`; it does the same access checks, calls `resource_view()`
(the view event and completion on view, which is how Moodle records a view, so a "view the activity" rule still works) and
redirects to the file without the forced download. This is not a completion write of the plugin's own: the rule that
decides is the resource's. The alternative, redirecting straight to the file, would have skipped the view and broken
"must view" completion. The resource itself and its course link stay as the teacher set them.

### D29. A failure to open an activity is `not_found`

The proposal gives three reasons. A network or server failure is none of them; it is reported as `not_found` (or
`forbidden` when Moodle says the capability is missing) rather than inventing a fourth reason the app does not know.
The detail goes to the browser console at debug level.

### D30. Auto-completion is triggered by the verified result row, never by a message, and runs after the commit

The only trigger is the result receiver, and the class reads the verified `format_rumbos_result` row (`passed = 1`, same user
and course) instead of trusting its caller, so the retry task and a re-delivery justify themselves the same way. It runs
after the result's transaction has committed: a failure inside it cannot roll the result back or change the answer to the
sender. Nothing in the browser path can call it, and a test pins that no external function mentions it.

### D31. The set is the SCORM activities the student can see; the actor is the site administrator

"The activities of the map bubbles" is not known to the plugin (the map lives in the backend). The final-exam gate counts
tracked, visible SCORM activities, so that is the rule, in a constant that a developer can extend. Moodle's override needs a
current user with `moodle/course:overridecompletion` and the receiver has none, so the administrator is the current user for
the call. `overrideby` therefore names the administrator, and the reason (statement id and purpose) is in a per-activity
event. A dedicated service account was considered and rejected: it would be a new user the client has to create and secure,
and Moodle's report would show it just the same. A "complete with fail" activity is overridden to incomplete first (Moodle
returns early otherwise), which is the two steps a teacher takes by hand.

### D32. The plugin retries its own failure with an ad-hoc task; the answer to the service does not change

The proposal asks for the existing outbox/retry. The receiver's answer (`applied`, `completed` or `recorded`) is the
backend's contract and the result was applied, so answering with an error would make the service retry a statement that
Moodle already holds. Instead a failure is logged (error log and an event), an ad-hoc task retries with Moodle's backoff,
and a re-delivery of the statement also retries. Reported to the backend owner: a retry through their outbox needs a new
reason in `contracts/openapi.json`.
