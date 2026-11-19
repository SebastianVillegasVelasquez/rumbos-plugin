# Audit for the result passback

Audited on 2026-11-17 against Moodle `MOODLE_502_STABLE` (5.2.x, the `rumbos_moodle` volume of the dev
setup, a fresh install) and this repository at `origin/main` (release 0.6.0). Everything here was read in
the code or run on that site unless it says **not verified**. Paths are relative to the Moodle `public/`
directory unless they start with the plugin's own folders.

Specification that follows from it: [RESULT-PASSBACK.md](RESULT-PASSBACK.md).

## 1. Identity

**What `sub` is.** `classes/local/pseudonym.php:39-41`: `substr(hash_hmac('sha256', (string) $userid,
key_manager::get_hmac_secret()), 0, 32)`: 32 lowercase hex characters, an HMAC of the Moodle user id with a
per-site secret that is stored encrypted (`classes/local/key_manager.php:45-52`, `:160-163`). It is one way
and **cannot be turned back into a user id**; it is stable for a user on a site and survives key rotation
(`key_manager::rotate()` leaves the HMAC secret alone; only `reset_all()` changes every pseudonym).
The launch assertion carries `sub`, `course`, `role` and nothing personal (`classes/local/launch_assertion.php:72-81`).

**Consequence for the receiver.** To resolve a `sub` the plugin must compute the pseudonym of candidates and
compare. It does that for the users **enrolled in the statement's course** (`get_enrolled_users()`, active or
not, so that "suspended" and "unknown" can be told apart). That is one HMAC per enrolled user and needs no new
stored mapping (a `sub` to user table would be new personal data and would only cover users who had launched).
A user who is not enrolled in the course at all cannot be found, and is answered `unknown_user`.

**Is the user a student, and who is refused?** Moodle's own notion of "a user whose completion is tracked" is
`completion_info::is_tracked_user()` (`lib/completionlib.php:1448`): active enrolment plus
`moodle/course:isincompletionreports`, which only the `student` archetype holds. Two details make it
insufficient on its own, and the receiver adds to it (`classes/local/result_access.php`):

- `has_capability()` is true for a **site administrator** for every capability, so an administrator who is
  enrolled would pass. The receiver refuses `is_siteadmin()` and evaluates capabilities without the
  administrator bypass.
- A user who is a student **and** a teacher (two roles) holds `isincompletionreports` too. The receiver
  refuses anybody who holds `format/rumbos:editmap` in the course (granted to `editingteacher` and `manager`
  by `db/access.php:16-24`), which is the same test the launch assertion uses for `role: editor`.
- A **role switched to student** is a property of the browser session (`$USER->access['rsw']`). The
  receiver has no session, so it never sees it: an enrolled teacher is refused as a teacher; a manager who is
  not enrolled is refused as `unknown_user` (not enrolled, not found). This is the point: the receiver
  judges what the person is, not what the page pretends. Previewing evaluators are never students.

## 2. Course completion today

**Read only, on the real course 10: not verified.** There is no copy of the real site on this machine: the
dev database was empty (the volume had no Moodle installed), course 10 does not exist here and I did not
have credentials for a real site. I verified every mechanism on a faithful test course instead (course
"English A1", Rumbos format, completion on, one student), and I prepared the read-only report on the real
one: `php public/course/format/rumbos/cli/configure_course.php --course=10` (a dry run by default) prints
whether completion is enabled, the criteria and aggregation that exist, the grade items, the pass mark mirror
and what it would change. **Run it on course 10 and paste the output into the report** to answer: is
completion enabled, which criteria exist, what is missing.

What Moodle core lets me say without the real course:

- Course completion needs `course.enablecompletion = 1` **and** criteria. With no criteria
  `completion_info::get_criteria()` is empty and nothing can complete.
- The criteria types are in `completion/criteria/` (activity, course, date, duration, **grade**, **role**,
  self, unenrol). The default overall aggregation is ALL (`completion_info::get_aggregation_method()`
  `lib/completionlib.php:474`); the rule "check-in **or** final" needs the overall aggregation set to ANY if
  other criteria exist.
- **The certificate is not in Moodle core.** `mod/` has no certificate module (`ls mod`: assign,
  bigbluebuttonbn, book, choice, data, feedback, folder, forum, glossary, h5pactivity, imscp, label, lesson,
  lti, page, qbank, quiz, resource, scorm, subsection, url, wiki, workshop). The course uses a third party
  plugin (`docs/MANUAL-TESTS.md` says "`mod_customcert` is third party and was not installed"). Which plugin
  it is, and what it requires (course completion, an activity, a grade, a date), **I could not see** and
  it decides whether the mechanism below is enough. What I can say: anything that reads
  `course_completions` or listens to `\core\event\course_completed` works, because the mechanism produces
  exactly that (verified). A certificate that is restricted by a **grade** condition would not be
  driven by this mechanism.
- What a student sees when the course is complete: Moodle shows the completion status in the course
  completion report and block, and sends the "Course completed" notification (`completion_completion::mark_complete()`,
  `completion/completion_completion.php:158-215`). The certificate download belongs to the third party plugin.
  **Not verified on the real course.**

## 3. Mechanisms and the recommendation

The statement says `passed` and the backend's rule can change (`gt` today, `gte` possible, any threshold).
The constraint that decides the design is how Moodle compares:

- **Grade to pass is greater-or-equal.** `lib/grade/grade_grade.php:1068`:
  `return $this->finalgrade >= $this->grade_item->gradepass;` and the course grade criterion
  `completion/criteria/completion_criteria_grade.php:97` `$this->gradepass <= $grade` and, in the cron query,
  `gg.finalgrade >= cr.gradepass` (`:193`). Run on the test site: a grade of `80.00` with a pass mark of
  `80.01` is **not** passed, `80.01` is. So a strict "more than 80" has no exact value for Moodle to
  compare, and any other rule would move it.

### Candidates

| | Mechanism | Verdict |
| --- | --- | --- |
| **a** | A gradebook grade item written by the plugin and a **"course grade"** criterion. | **Rejected for completion.** The criterion compares the **course total**, not our item (`get_grade()` calls `grade_get_course_grade()`), so every other graded activity (the fallback check-in quiz has a grade item) is mixed into it; and it compares `>=`, which cannot express `gt` or a changed policy. The grade item itself is kept, as the **record** (see below). |
| **b1** | Mark the **"Self completion"** criterion for the student. | **Rejected.** The criterion shows the student a "Mark course complete" button (`course/togglecompletion.php:86-100`): a student could complete the course without passing. |
| **b2** | Mark the **"Manual completion by role"** criterion (`completion_criteria_role`) through its public `complete()` (`completion/criteria/completion_criteria_role.php:84-86`, the call `course/togglecompletion.php:76-79` makes). | **Recommended.** No comparison anywhere: the backend's `passed` is the only decision. Only a holder of that role could mark it in the UI, and a dedicated role is assigned to nobody. |
| **c** | A hidden **activity** whose completion the plugin sets, with an "activity" criterion. | Rejected: it adds an item to every course, Moodle's activity-completion recalculation and "reset completion" tools act on it, and the Rumbos map would see a stray activity. |
| **d** | Force the completion state of the **existing** activities. | Rejected outright, as asked: it falsifies the records of activities nobody did, and Moodle recomputes them. |

### Recommendation: grade item as the record, role criterion as the completion

1. **One grade item** "Rumbos result", `itemtype = manual`, `idnumber = rumbos_result`, 0 to 100, holding the
   **best** percent of the check-in and the final. The receiver only ever raises it. It is a manual item
   because `grade_update()` can write raw grades only to `itemtype = 'mod'` items (`lib/grade/grade_item.php:1205-1207`,
   `is_external_item()`/`is_raw_used()` `:1238`; I ran `grade_update()` on a `format` item and got
   `GRADE_UPDATE_FAILED`), while `grade_item::update_final_grade()` is the supported way to set a value on a
   manual item and records it in the grade history (verified: `80.00` then `80.01`). The item is found by
   `idnumber`, so renaming it in the gradebook does not break the receiver.
2. **Its "grade to pass" is a display mirror**: with `gt` the mirror is `pass_percent + 0.01` (percent has two
   decimals, so "more than 80.00" is "80.01 or more" and the gradebook colours the exact 80.00 as not passed),
   with `gte` it is `pass_percent`. It decides nothing. It can be wrong by a hundredth when the backend's
   exact value rounds (80.004 shows as 80.00 and passes under `gt`); the statement's `passed` is what counts.
   (The backend should round half up to two decimals so that the percent it sends agrees with its decision
   wherever it can; where it cannot, `passed` wins.)
3. **One completion criterion**: "Manual completion by" the dedicated role `rumbosresult` (no capabilities
   except `moodle/course:markcomplete`, **assigned to nobody**). The receiver marks it through the public
   API (`completion_criteria_role::complete()`), then aggregates that user at once with
   `aggregate_completions()` (`lib/completionlib.php:1800`), so the course is complete when the request
   returns rather than at the next cron. Verified: after `complete()` the user's `course_completions` row had
   `timecompleted` NULL and `reaggregate` set; after `aggregate_completions()` it was complete and
   `completion_info::is_course_complete()` was true.
   - The role needs `moodle/course:markcomplete`, or the course completion form (`course/completion_form.php:226`
     lists only roles that have it) would **drop the criterion the next time a teacher saves the form**.
4. **"Check-in OR final"**: there is one criterion and one grade item. The first pass from either purpose
   marks the criterion; the best-of rule keeps the grade at the highest percent. If the course has other
   criteria (for example the fallback quiz), the overall aggregation must be **ANY**; the command sets it
   and says so.
5. **What the criterion shows in Moodle's reports**: the completion report lists "Manual completion by
   Rumbos result". That is true: the result came from Rumbos.

Moodle's own reports keep working because the mechanism is Moodle's own: `course_completions`,
`course_completion_crit_compl`, the `course_completed` event and the notification are produced by the core
classes, not written by hand.

**Risks of the recommendation**, stated honestly: (i) it depends on a role the site must have and on core
classes (`completion_criteria_role`, `aggregate_completions()`) that are public in practice but not part of a
documented stable API; the tests exercise them so a Moodle upgrade that changes them fails CI; (ii) an
administrator who unticks the criterion in the completion form removes the completion, and the receiver then
answers `503 course_not_configured` (the result is kept by the sender and not lost); (iii) a teacher can edit
or delete the manual grade item in the gradebook (it is a normal manual item); deleting it also gives `503`
until the command is run again.

## 4. Receiving channel

Decided in [RESULT-PASSBACK.md](RESULT-PASSBACK.md): a **dedicated script**, `passback.php`, with its reasons:

- An external function cannot answer `400/401/409/422`: `core_external`/the REST server answer `200` with an
  exception body for every failure, and the contract needs real status codes.
- It would need a service, a service user and a token on top of the signature: three more credentials to keep
  least-privileged, for a caller that already proves itself with a signature.
- The script uses no session (`NO_MOODLE_COOKIES`), no login and no user: it can do exactly one operation.

Keys, replay, idempotency and the audit are specified there. Rate limiting is a fixed window counter in a
Moodle application cache (the same technique as `classes/local/flow_rate_limit.php`), site wide because the
only legitimate caller is one backend.

## 5. Privacy API and iframe attributes

**What the provider declares today** (`classes/privacy/provider.php:58-77`): the table `format_rumbos_flow`
(course, user, map, step, revision, method, time) and one external location, `rumbos`, with the pseudonymous
user id, the course id, the role and the IP address. It implements `plugin\provider` and `core_userlist_provider`
for course contexts.

**What changes**: new stored data in Moodle (the table `format_rumbos_result` and the audit table
`format_rumbos_audit`, and the grade, which Moodle's own gradebook privacy provider already covers); the
external location now also covers what the backend keeps (practice attempts 90 days, assessment records
retained without an automatic purge, saved words and progress until deletion) and receives results back.
The text is in `lang/en/format_rumbos.php`, **marked for legal review**: it is a draft, not final.

**A server to server channel to forward an erasure request: none exists** (`rg` for outbound HTTP in the
plugin finds none; the plugin never calls the backend, the backend calls the plugin). I did not invent one.
The gap is documented in the privacy text and in the README: an erasure request in Moodle erases what Moodle
holds; what the backend holds is erased by the backend's operator (the student's `DELETE /me` for practice
data, an administrator procedure for assessment records, pending legal review).

**`allow` and `sandbox`: no change needed.** The iframe has `allow="fullscreen; autoplay"` and
`sandbox="allow-scripts allow-same-origin allow-forms allow-downloads"` (`templates/embed.mustache:93-95`). The
exam plays audio on a click and records nothing, so neither `microphone` nor any new sandbox token is
required. Not changed.

## 6. Does the assertion tell the app the user can edit?

**Yes, already.** `launch_assertion::create()` signs `role`: `editor` when
`has_capability('format/rumbos:editmap', $context, $userid)`, else `student`
(`classes/local/launch_assertion.php:70-77`). It is true for an editing teacher, a manager and a site
administrator. No change to the host contract was needed; [RESULT-PASSBACK.md](RESULT-PASSBACK.md) says the
backend must treat `editor` as preview.

One more thing worth knowing: the host-init `mode` is `editor` only while Moodle's editing mode is on
(`classes/local/access.php:60-62`), so a teacher with editing off sees the student map, but the **assertion's**
`role` stays `editor`. The backend must use the assertion's `role`, not the mode, to recognise a preview.

## The state the command leaves and how to revert it

See the README section "Result passback" and `cli/configure_course.php --help`. The desired final state of
a course: completion enabled; the grade item `rumbos_result`; the role `rumbosresult` with only
`moodle/course:markcomplete`; one role criterion for it, role aggregation ANY and overall aggregation ANY
(only changed when asked with `--aggregation-any`); the pass mark mirror set from `--pass-percent` and
`--pass-operator`. The revert is `--revert --apply`: it removes the criterion and the role aggregation row, and
deletes the grade item **only when it holds no grades**; `--drop-grades` also deletes the grades. It leaves
the role, the overall aggregation and every completion record alone (existing completions are real and stay).
