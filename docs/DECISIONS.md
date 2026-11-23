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
