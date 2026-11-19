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
