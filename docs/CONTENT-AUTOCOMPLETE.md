# Marking the content complete when a student passes

**Off by default. Writes completion data for students: NEEDS LEGAL REVIEW before it is turned on in production.**

When the Rumbos service reports a **passing** result (the check-in or the final exam) and the setting is on, the plugin
marks the content the student can see (SCORM packages, files, URLs and pages) as complete in Moodle, so that a student who
passed the check-in is not asked to do content they were exempted from, and the final-exam gate (which reads Moodle's
completion of the SCORM packages) agrees.

## The rule that matters: only a signed statement can cause it

The single trigger is the result receiver (`passback.php`), after it verified the statement's signature, policy and replay
rules and applied the result. The code reads the verified row in `format_rumbos_result` (`passed = 1`, for that user and
course). **No browser message, external function, web service or page can trigger it**: there is none, and a test fails if
a function that mentions it is added to `db/services.php`. A forged message in the page therefore cannot complete anything.

## What it does, precisely

For one student, after the result is committed:

1. Stops if the setting is off, if there is no verified passing result for that user and course, or if the user is not an
   actively enrolled student who may not edit the map.
2. Takes the **required set**: activities of the module list `content_autocomplete::MODULES` (**`scorm`, `resource`,
   `url`, `page`**) that are **visible to the student** (not hidden, not restricted for them, not being deleted) and
   **track completion**. Changing the list is a code change, on purpose.
3. For each, if it is not already complete, calls Moodle's supported override, `completion_info::update_state($cm,
   COMPLETION_COMPLETE, $userid, true)`. A state of "complete with fail" is first overridden to incomplete and then to
   complete (Moodle returns early otherwise), the two steps a teacher takes by hand.
4. Repeats up to 5 passes, because finishing one activity can unlock another that was restricted by it.
5. Writes one **log event** per activity, `\format_rumbos\event\content_autocompleted`: the activity, the student, the
   account recorded as the one who overrode, the id and purpose of the statement that justified it, and the state before.

It deletes nothing: no attempt, track or grade is touched, and the student can still open every activity.

## Which module types are completed, and what the final-exam gate sees

A bubble on the map is any activity with a view page that is meant to be linked (`course_state::is_relevant()` here,
`is_bubble_candidate` in the service). That leaves out `label`, `subsection` (no page) and an activity set to show no link,
such as a folder displayed on the course page.

| Types | Does the override work? | Completed by a pass? | What the gate sees |
| --- | --- | --- | --- |
| `scorm` | Yes | **Yes** | Counted: it must be complete. |
| `resource`, `url`, `page` | Yes | **Yes** | Not counted, complete or not. |
| `assign`, `quiz`, `lesson`, `workshop`, `forum`, `glossary`, `data`, `wiki`, `choice`, `feedback`, `h5pactivity`, `lti`, `bigbluebuttonbn` | Yes | **No**, on purpose: completion would say the student did work or took part. | Not counted. |
| `book`, `folder`, `imscp` | Yes | **No**: content too, but not asked for; add it to the list if the client wants it. | Not counted. |
| Plugins not installed on the test site, such as `customcert` | Not tested. The same call applies to any module type. | No | Not counted. |
| Any type with completion "None" | **No**: Moodle records nothing. | No | Not counted (`completion = 0`). |
| Any disabled module type | **No**: `update_state()` returns. | No | Not tested. |

"Yes" was observed on Moodle 5.2.4 in PHPUnit, by overriding one activity of each of these installed types with manual
completion (the test data generator enables a disabled type such as `bigbluebuttonbn` when it creates one). `resource`,
`url` and `page` are also tested with automatic view completion.

The gate counts `scorm` only (`REQUIRED_MODNAMES` in the service's `app/closing/gate.py`). One difference remains: the
gate reads with a privileged account, so a SCORM that is **restricted** for the student (not hidden) is still counted by
the gate, while this feature skips it because the student cannot open it. Such a package can keep the final exam closed
after a pass. The extra passes below cover only restrictions that another completion lifts.

Completing more activities can also complete the **course**, when its completion criteria include them, and whatever
depends on that (a certificate).

## The Moodle API, and what it can and cannot do (read from Moodle 5.2.4 code)

`completion_info::update_state()` (`lib/completionlib.php`), the same call as `core_completion_override_activity_completion_status`.

| Case | Result |
| --- | --- |
| SCORM, automatic completion (view, status or score rules) | **Completed.** With the override flag the rules are not evaluated: the state is set to complete and `overrideby` is stored. Moodle then **holds** it: a later grade or track event may refine complete/complete-with-pass but never moves it back to incomplete. |
| SCORM, manual completion | Completed (the manual branch). |
| SCORM that tracks nothing (completion "none") | **Cannot**: Moodle records nothing for it. It is not in the set, and the gate does not count it. |
| Completion disabled in the site or the course | **Cannot**: `update_state()` returns without doing anything. The result is not even applied in such a course (the receiver answers `course_not_configured`). |
| Module plugin disabled | **Cannot**: `update_state()` returns. The activity is reported as not completed and retried. |
| "Complete with fail" | Completed through incomplete first (see above). |
| Hidden, restricted or being deleted | Left alone (not visible to the student). A restriction that depends on another activity's completion is opened by the extra pass. |
| Already complete | Left exactly as it is, including its `overrideby` and time (idempotent). |
| "Locked" completion settings (`completionlocked`) | It only locks the activity's *edit form* once data exists; it does not stop `update_state()`. |
| Availability conditions that need something other than completion (a date, a group) | Not evaluated here; an activity restricted that way is invisible to the student and so not in the set. |

**Who is recorded.** Moodle's override needs a current user who holds `moodle/course:overridecompletion`; the receiver has
no user. For the duration of the call the **site administrator** (`get_admin()`) is the current user, so `overrideby` and
the event's user are the administrator, and the reason is in the event. This is the only way Moodle's supported API
accepts, and it is why the setting needs a decision of the client: the Activity completion report will show "overridden by"
the administrator.

## A failure never blocks the result

The result is applied and committed **before** this runs, and nothing in it can change the answer to the sender. If an
activity cannot be completed (or anything throws):

- the failure is written to the server error log and as an event, `content_autocomplete_failed` (Site administration >
  Reports > Logs), naming the statement and the activities;
- a **Moodle ad-hoc task** (`\format_rumbos\task\autocomplete_content`) is queued once; it re-reads the verified result and
  repeats, and when something still fails it throws so Moodle retries it with a growing delay. It needs Moodle's cron;
- a **re-delivery of the same statement** (the service's own retry) also runs it again, and does nothing when all is done.

The receiver's answer to the Rumbos service is unchanged (`applied: true`, `completed` or `recorded`), because the
contract in `contracts/openapi.json` is the backend's and the result *was* applied. The service's outbox therefore does not
retry on this failure; the ad-hoc task and the log event are the plugin's own retry and alarm. **Reported to the backend
owner:** if they want the service's outbox to retry too, that needs a new reason in their contract, not a change here.

## Turning it on and off

Site administration > Plugins > Course formats > Rumbos > **Mark the content complete when a student passes**
(`format_rumbos/autocompletecontent`). In config: `php admin/cli/cfg.php --component=format_rumbos --name=autocompletecontent --set=1`
(and `--set=0` to turn it off). Turning it off stops new completions at once; **it does not undo** what was already
completed: those are overrides, visible in the completion report, and a teacher or an administrator can clear them there.
The cron must be running for the retry.

Staging first: the staging helper (`cli/staging_completion.php`) can show the state before and after; send a signed test
statement with `dev/sign-test-statement.php` and look at the activity completion report and the logs.

## Privacy (for legal review; not final)

The provider (`classes/privacy/provider.php`) links the `core_completion` subsystem with the text
`privacy:metadata:autocomplete`, marked **NEEDS LEGAL REVIEW**: with the setting on, the plugin changes a student's
completion by an override recorded under the administrator's name. The data lives in Moodle's own completion tables and
logs, which their providers export and erase; the plugin keeps no extra table. Open for the client and legal: the lawful
basis for changing completion without the student doing the content, that the report names the administrator, and
whether the certificate or the privacy notice must say that completion can be granted by a passing result.
