# What the plugin gives the final-exam gate

The Rumbos service decides, when a student starts the final exam, whether that student completed every required
SCORM activity. **Moodle is the source of truth**: the service asks Moodle live and fails closed (see
`docs/CLOSING-GATE.md` in the service repository). This page is what the plugin contributes and how to set it up,
try it and undo it.

| Piece | Where | Needs a deploy order? |
| --- | --- | --- |
| The signed claim `uid` in the launch assertion | `classes/local/launch_assertion.php`, setting `launchuid` | Yes: service first |
| Per-user state of the closing flow | `format_rumbos_flow` (already there) | No change |
| Override for a student the gate blocks wrongly | Moodle's own completion override | No change |
| Staging helper to mark test SCORM complete or incomplete | `cli/staging_completion.php` | No |

## The claim `uid`

An **integer**, the Moodle user id of the person the assertion is for, signed like every other claim. It is
**additive**: a service that ignores unknown claims, or never reads this one, behaves exactly as before.

- It is **off by default** (admin setting "Send the Moodle user id in launch assertions", config key
  `format_rumbos/launchuid`). Installing or upgrading the plugin changes nothing that the service receives.
- When it is on, the claims are `iss, aud, sub, course, role, iat, exp, jti, uid`. The admin preview of the
  registration panel shows it.
- The service must not log it, and must keep it no longer than the access token it is attached to.

Why a setting and not an unconditional claim: the order of deployment (service first) is then something the site
administrator controls with one tick box, and **rolling back is unticking it**, with no redeploy and no new
assertion format to unwind.

### Does the service accept it? (read from its code, not from a running service)

`rumbos-backend`, `app/auth/launch.py`: the assertion is verified with `jwt.decode(..., algorithms=["RS256"],
options={"require": [iss, aud, sub, course, role, iat, exp, jti]})`. `require` only demands that those claims exist;
nothing rejects an extra one, and `_check_claims` validates the named claims only. The only size limit is
`MAX_ASSERTION_BYTES = 4096`; `uid` adds about 15 bytes. So **an extra `uid` is accepted today and ignored**. What
that proves is the source on the branch `feat/final-exam-gate`; it does not prove what is deployed. Before turning
the setting on, confirm on the deployed service (see "Deploy").

## Per-user state of the closing flow

**The existing table already supports it, with no schema change.** `format_rumbos_flow` is keyed by
`(courseid, userid, mapid, stepkey)`. It has no flow column, and does not need one: the service enforces that a
step key is unique across the welcome and the closing flow of a map (`docs/WELCOME-FLOW.md` in the service
repository), and the closing app already writes its steps with the same host messages (`rumbos:flow-step-done`,
`rumbos:flow-reset`) that the welcome flow uses. Nothing in the plugin looks at which flow a key belongs to.

How the three states of the closing flow are recorded:

| State | Row |
| --- | --- |
| Seen or completed a step | one row for that step key, `method = completed` |
| Completed through its fallback | `method = fallback` |
| **Recorded escape** (the student left the final exam step without taking it) | `method = fallback` (what the app sends for `assessment-escape`) or `unavailable` (the step could not be shown) |

"Seen" and "completed" are not told apart: the app records a content step when it is finished, not when it is
opened. If the product needs "seen" separately it needs a new method value and a change in the app, and was not done.

Idempotency is the welcome flow's: for the same revision the first row wins, a lower revision is ignored and a higher
one replaces the row. **A recorded escape is therefore not turned into a completion by a later `completed` at the
same revision**, and the other way round; a new revision of the step is what changes it.

What the welcome flow has, and so the closing flow has the same, and **not more**:

- Privacy provider: the table is declared, exported and erased (`classes/privacy/provider.php`), with a user, a
  course or a context. A user's deletion and a course's deletion remove the rows (`classes/observer.php`).
- Who writes: only the student about themselves (`set_flow_step_done`, never while "logged in as", never for a
  guest, rate limited, at most 500 rows per user and course). Anyone else's rows are removed only by a user with
  `format/rumbos:resetflow` (`reset_flow_progress`).
- **No audit trail beyond the row.** The welcome flow logs no Moodle event; the row holds who, which step, which
  revision, how and when. The closing flow is audited exactly like the welcome flow, which is to say by that row.
- **No backup or restore.** The table is not part of a course backup; a restored or duplicated course is a new course
  id and a new map in the service, so there is nothing to carry (see `docs/DESIGN-backup-restore.md`). The request
  "included in backup/restore" does not hold for the welcome flow and was not invented for the closing flow.

**Known debt:** the missing audit event and the missing backup and restore are gaps of the welcome flow that the closing
flow inherits. They were deliberately left as they are (decision D16).

`tests/local/closing_flow_test.php` pins all of this.

## Override for a student the gate blocks wrongly

**The plugin adds nothing, on purpose.** Moodle already has an audited override, the service reads Moodle live, and so
the service learns about an override with no new contract:

1. A teacher, manager or administrator opens *Course administration > Reports > Activity completion*, finds the
   student and the SCORM activity, and chooses **Override** in that cell. (Capability
   `moodle/course:overridecompletion`; editing teachers and managers have it by default.)
2. Moodle stores the new state with `overrideby` (the person who did it) and the time, and writes a
   `course_module_completion_updated` event to its log. That is the record, and it names the administrator.
3. The service's next call to `core_completion_get_activities_completion_status` reads that state. Completion is
   never cached by the service, so the student is let in on the next start.

Why not a plugin override: a flag that only Moodle knows could change the gate only if the service learned it through
a new signal (a new claim or a new endpoint), and that signal would be a second source of truth that can drift from
Moodle's completion. This way the exam gate and the course's completion report always agree.

Limits to know:

- It is per activity. A student blocked by several SCORM activities needs one override for each.
- An activity overridden to **complete** keeps that state when the SCORM later reports (Moodle holds it);
  overridden to **incomplete** is processed by Moodle's normal triggers again, so a later SCORM event can complete it.
- It does not apply to an activity that does not track completion: the service does not count those.
- The check-in, the passed final and the attempts are the service's and are not touched by it.

If a bulk override is needed on production (a whole legacy cohort), it should be a reviewed one-off script run by an
administrator, not a shipped tool; the staging helper below deliberately refuses to run there.

## The service account and `core_completion_get_activities_completion_status`

Read from `public/completion/classes/external.php` of Moodle 5.2.4 (this repository's dev site), not from memory.

For a user other than the one the token belongs to, the function:

1. requires an **active, confirmed, real user** (`core_user::require_active_user`): a deleted, unconfirmed or guest
   account fails; a suspended account passes that check, though enrolment is not looked at at all;
2. calls `validate_context` on the course, which runs `require_login` for the token's user: the user must be enrolled,
   **or hold `moodle/course:view`** in the course (that is what lets a service account look without being enrolled);
3. requires **`report/progress:view`** in the **course** context (default: teacher, editing teacher, manager);
4. unless the course has no separate groups, requires the account to see the student's groups:
   **`moodle/site:accessallgroups`** (default: editing teacher, manager);
5. lists only activities that are `uservisible` **for the service account**. A hidden or restricted activity is
   *left out of the answer*, not marked incomplete, unless the account also holds
   `moodle/course:viewhiddenactivities` and `moodle/course:ignoreavailabilityrestrictions`.

**Item 5 is the one that can open a gate by mistake**: an activity missing from `statuses` must count as *not
completed* in the service, never be skipped. Give the account the two capabilities so that nothing is missing, and
check it in the verification below.

### Least privilege

Create a role (for example "Rumbos service") instead of using a manager or an administrator. Assign it to the service
user in the **category that holds the Rumbos courses** (or the system context if they are spread out), never as a site
administrator. Allow exactly:

| Capability | Context | Why |
| --- | --- | --- |
| `moodle/course:view` | course (inherited) | `require_login` without being enrolled |
| `report/progress:view` | course (inherited) | read other users' completion |
| `moodle/site:accessallgroups` | course (inherited) | courses with separate groups |
| `moodle/course:viewhiddenactivities` | course (inherited) | see hidden activities, so none is silently dropped |
| `moodle/course:ignoreavailabilityrestrictions` | course (inherited) | see restricted activities, same reason |
| `webservice/rest:use` | system | the REST protocol (the account already uses it) |

The functions the account already calls (`core_webservice_get_site_info`, `core_course_get_contents`) need their own
capabilities and the function must be in its external service; this table adds only what the completion read needs.
Do **not** grant `moodle/course:overridecompletion`, `moodle/course:update` or anything that writes: the service reads
Moodle and never writes it.

### Exact manual verification (do this before anything is built on it)

Prerequisites: a staging site (see the staging helper), a course with the Rumbos format and at least one SCORM that
tracks completion, a test student enrolled in it, and the service's token. Type the token, do not keep it in the
shell history.

```bash
read -rs WSTOKEN; export WSTOKEN
MOODLE_URL=https://your-moodle.example
COURSE_ID=<course id>
USER_ID=<Moodle id of the test student>

# (a) The function is in the service: prints True.
curl -sS "$MOODLE_URL/webservice/rest/server.php" \
  --data-urlencode "wstoken=$WSTOKEN" \
  --data-urlencode "wsfunction=core_webservice_get_site_info" \
  --data-urlencode "moodlewsrestformat=json" |
  python -c "import json,sys; print('core_completion_get_activities_completion_status' in [f['name'] for f in json.load(sys.stdin)['functions']])"

# (b) Read the test student's completion.
curl -sS "$MOODLE_URL/webservice/rest/server.php" \
  --data-urlencode "wstoken=$WSTOKEN" \
  --data-urlencode "wsfunction=core_completion_get_activities_completion_status" \
  --data-urlencode "moodlewsrestformat=json" \
  --data-urlencode "courseid=$COURSE_ID" \
  --data-urlencode "userid=$USER_ID"
```

All of these must be true:

1. (a) prints `True`. If it prints `False`, add the function to the service's external service (*Site administration >
   Server > Web services > External services > Functions*).
2. (b) answers `{"statuses":[...],"warnings":[]}`. An object with `exception` / `errorcode` is a failure: the message
   names the missing capability (`report/progress:view` and `moodle/site:accessallgroups` are the usual ones) or says
   `invalidtoken` or `accessexception`.
3. **Every** SCORM `cmid` of the course appears in `statuses` with `modname` `"scorm"` and `tracking` 1 or 2. Compare
   with *Course administration > Reports > Activity completion*. A missing one is the item 5 problem above: hide a
   SCORM and restrict another, then run (b) again; both must still be listed.
4. Freshness, with the staging helper:
   - `php public/course/format/rumbos/cli/staging_completion.php --course=$COURSE_ID --user=$USER_ID --state=complete --apply`,
     then (b) again immediately: every tracked SCORM reads `"state":1` (`2` is complete with a pass), and the one
     overridden carries `overrideby` with the administrator's id;
   - `--state=incomplete --apply`, then (b): `"state":0`.
   Any delay between the change and (b) showing it is the real age of the signal; report it.
5. As a negative: a student who is **not** enrolled in the course, and an id that does not exist, must give an
   exception, not an empty list.

Report the output without the token, and the values `state` took.

## The staging helper

`cli/staging_completion.php` marks a test student's tracked SCORM activities complete or incomplete so the gate can be
exercised end to end. It applies Moodle's own override as the site administrator, so the change is recorded and
logged like a teacher's.

```bash
# In config.php of the STAGING site only:
#   $CFG->format_rumbos_staging_tools = true;

php public/course/format/rumbos/cli/staging_completion.php --course=10 --user=42 --state=complete          # dry run
php public/course/format/rumbos/cli/staging_completion.php --course=10 --user=42 --state=complete --apply
php public/course/format/rumbos/cli/staging_completion.php --course=10 --user=42 --state=incomplete --apply
```

- **It refuses to run unless config.php contains `$CFG->format_rumbos_staging_tools = true;`** (the exact boolean).
  A production site does not have that line, so the file that ships with the plugin does nothing there (exit code 3).
  There is no other switch and no flag on the command line.
- It is a dry run unless `--apply` is given.
- It accepts one active student enrolled in the course, never an editor (editors skip the gate), and only tracked
  activities of the module asked for (`--module`, default `scorm`).
- Running it again leaves the same state.
- Each state it actually changes is recorded in the event `staging_completion_overridden`.

### Resetting a test student

`cli/reset_test_student.php` puts a test student's journey back so it can be walked again, as far as that is possible
without deleting evidence. It has the same refusal as the helper above and accepts the same kind of user.

```bash
php public/course/format/rumbos/cli/reset_test_student.php --course=10 --user=42           # dry run, the default
php public/course/format/rumbos/cli/reset_test_student.php --course=10 --user=42 --apply
```

- It deletes the student's flow progress rows in the course, for every map id and both flows.
- It takes back **only the overrides the plugin recorded**: those of auto-completion (`content_autocompleted`) and of the
  helper above (`staging_completion_overridden`). The record is Moodle's log, so "Standard log" has to be on both when the
  override is made and at the reset. With no readable log store it takes back nothing and says so. An override made while
  logging was off, or whose log entry was purged, is listed as unrecorded and left alone.
- It also leaves alone an activity that was overridden by another account, or modified after the record, that is no
  longer an override, or that is gone or no longer tracks completion.
- "Taking back" is an override to incomplete: Moodle's API cannot remove an override.
- **That does not hold for automatic completion.** Moodle calculates it again at the next trigger, so a SCORM whose tracks
  meet its rules becomes complete again. A passing statement delivered again, or a queued auto-completion retry, can
  complete it too.
- It deletes no SCORM track, quiz attempt, grade, verified result or course completion, and cannot reset what the Rumbos
  service holds. It prints what it left.
- Exit codes: 0 done, 1 a user who is refused or a change Moodle did not make, 2 bad arguments, 3 not a staging site.

## Privacy (for legal review; not final)

`uid` is the first thing the service receives that is a Moodle identifier and not a pseudonym. With the setting on,
the pseudonym `sub` no longer hides the Moodle account from the service: anyone who holds the service's data can see
which Moodle user each pseudonymous student is. The declaration in `lang/en/format_rumbos.php`
(`privacy:metadata:rumbos:uid`) says so and says **NEEDS LEGAL REVIEW**. Open for the client and legal:

- the purpose (reading one student's completion to decide an exam attempt) and its lawful basis;
- retention: the service keeps `uid` with the access token it issues for the visit and nothing else; it must not write
  it to logs, to attempts or to statements;
- the effect on the pseudonymisation promised in `README.md` and in `docs/LIFECYCLE.md` (a reset of the site secret
  changes every `sub` but not `uid`);
- whether the privacy notice shown to students has to name it.

The plugin's own rows are covered by the existing provider; `uid` itself is stored nowhere in Moodle.

## Deploy, in this order

1. **Service first.** Deploy the version of the Rumbos service that accepts and uses `uid`, with
   `CLOSING_GATE_MODE=off`. Confirm on the deployed service that an assertion with an extra integer `uid` still
   starts a session. This is the one check the plugin cannot do for you: the service is a separate deployment.
2. **Plugin.** Install this version, run the upgrade (no schema change, no new capability), purge caches. Nothing the
   service receives changes yet: `launchuid` is off.
3. **Verify the service account** with the steps above, on staging, then on production with a real test student.
4. **Turn on** "Send the Moodle user id in launch assertions". Open a course as a student and check in the admin
   preview that `uid` is an integer equal to that student's id.
5. **Then** switch the service to `CLOSING_GATE_MODE=enforce`.

### Roll back

- Gate misbehaving: set `CLOSING_GATE_MODE=off` in the service. Nothing else is needed.
- Stop sending the Moodle id: untick the setting. The very next assertion has no `uid`; the service must then keep the
  exam closed (`progress_unavailable`), never open, which is the intended failure.
- Plugin removal: install the previous release over this one. There is no schema change, so nothing has to be
  undone in the database.
- Revoking the service account's capability has the same effect as the previous point.
