# Result passback: the receiver in the plugin

How the Rumbos backend tells this plugin that a student passed or failed the native check-in or final exam,
and what Moodle does with it. **This page is the specification the backend's sender follows.** The backend
implements the sender, the plugin implements the receiver. The browser is never trusted to report a grade
or a completion.

Evidence for every choice below is in [RESULT-PASSBACK-AUDIT.md](RESULT-PASSBACK-AUDIT.md). Judgment calls are
logged in [DECISIONS.md](DECISIONS.md).

## The channel

**A dedicated script, not a Moodle web service function.**

```
POST https://<moodle>/course/format/rumbos/passback.php
Content-Type: application/json

{ "statement": "<JWS>" }
```

Why not an external function with a token: Moodle's web service layer answers every error with HTTP 200 and
an exception body, so the contract's `400`, `401`, `409`, `422` and `5xx` cannot be expressed; it needs a
service user, a token and a service definition (three more secrets and objects to keep least-privileged); and
the signed statement already authenticates the caller. The script has no session, no cookies and no user:
the only thing that opens it is a statement signed with a key the administrator configured. It does one
thing.

The script is disabled (`503`, reason `not_configured`) until an administrator turns it on and sets the
issuer, the tenant and at least one public key (**Site administration > Plugins > Course formats > Rumbos >
Result passback**).

## The statement

A JWS, compact serialization, **RS256 only** (the algorithm comes from the configured key, never from the
token). The protected header carries `kid`.

| Claim | Type | Checked against |
| --- | --- | --- |
| `iss` | string | The "Result issuer" setting, byte for byte. |
| `aud` | string | This site's issuer: the value the registration panel shows as "Issuer", the same string this plugin signs launch assertions with. |
| `jti` | string, `[A-Za-z0-9._-]{1,64}` | Unique per statement. A retry is a **new** statement with a new `jti`. |
| `iat` | int, Unix seconds | At most 60 s in the future. |
| `exp` | int, Unix seconds | In the future (60 s of clock leeway) and **at most 300 s after `iat`**. |
| `tenant` | string | The "Result tenant" setting. |
| `sub` | string, 32 lowercase hex | The pseudonym of the launch assertion's `sub` (HMAC of the Moodle user id). |
| `course_id` | int | The Moodle course id; must use the Rumbos format. |
| `purpose` | `check_in` or `final` | |
| `attempt_id` | string, `[A-Za-z0-9._-]{1,64}` | The idempotency key. |
| `percent` | string, `^(100\.00\|\d{1,2}\.\d{2})$` | Two decimals, as the backend decided it. Never a number. |
| `passed` | bool | **Decided by the backend** from its policy. Moodle never recomputes it. |
| `policy` | object | `pass_percent` (number or decimal string, 0 to 100) and `pass_operator` (`gt` or `gte`). Recorded in the audit, not used to decide. |
| `finished_at` | string or int | ISO 8601 with a zone, or Unix seconds. |

No name, no email, no IP address: the statement carries the user reference and the score, nothing else. An
extra claim is ignored.

### Key ids and rotation

The administrator pastes one or more PEM `PUBLIC KEY` blocks (RSA, 2048 bits or more) into the "Result keys"
setting. The id of a key is **the first 16 hexadecimal characters of the SHA-256 of its DER
SubjectPublicKeyInfo**, the same fingerprint the registration panel shows for the plugin's own key (its first
16 characters):

```sh
openssl pkey -pubin -in backend-public.pem -outform DER | openssl dgst -sha256
# kid = the first 16 hex characters of that digest
```

The receiver picks the key by the header's `kid`. A missing or unknown `kid` is `401`. To rotate: add the new
public key to the setting, let the backend start signing with it, remove the old one when no statement signed
with it can still be in flight (5 minutes after the switch, plus the outbox's backoff).

## What the receiver answers

| HTTP | Body | When |
| --- | --- | --- |
| `200` | `{ "applied": true, "reason": "completed" }` | The result is recorded and **this statement** completed the course. |
| `200` | `{ "applied": true, "reason": "recorded" }` | The result is recorded; the course completion did not change (a fail, or a pass after the course was already complete). |
| `200` | `{ "applied": false, "reason": "already_applied" }` | The `attempt_id` was applied before, or the same `jti` with the same payload arrived again. Nothing changed. |
| `400` | `{ "applied": false, "reason": ... }` | Not JSON, no `statement`, not a JWS, or a claim missing or of the wrong type. `malformed`. |
| `401` | | Bad signature, unknown `kid`, wrong issuer, audience or tenant, expired. `bad_signature`, `unknown_key`, `expired`, `not_yet_valid`, `bad_issuer`, `bad_audience`, `bad_tenant`. |
| `405` | | Not a `POST`. |
| `409` | | The `jti` was used before with a **different** payload. `jti_conflict`. |
| `422` | | `unknown_course` (no such course, or not Rumbos); `unknown_user` (no user of that course has this `sub`); `not_enrolled` (enrolment inactive, suspended or ended); `role_not_allowed` (not a student: a teacher, manager, administrator, or anybody who may edit the map). |
| `429` | | Too many requests. **Treat like a `5xx`**: retry later, with `Retry-After`. |
| `503` | | `not_configured`: the receiver is off, or the course is not set up (`course_not_configured`: no result grade item, no completion criterion, or completion switched off). **Transient**: the result is not lost, the sender keeps it and retries. |
| `500` | | Anything unexpected. Nothing was applied (the change is one database transaction). **Transient.** |

The body is always `{ "applied": bool, "reason": string }`. A sender must treat `422`, `401`, `400`, `409`
as permanent for that statement (and alert: they mean misconfiguration or a bug) and `429`, `5xx` as
transient. A **retry after a lost answer** is a new statement with the same `attempt_id` and a new `jti`:
the answer is `already_applied` if the first one got through.

**Not a student.** A teacher, a manager, an administrator, or anybody who can edit the map in that course
never completes it, even when they are also enrolled as a student. The backend does not send statements for
`editor` mode and previews (the launch assertion's `role` claim says which: see below); this check is the
second line, and it cannot be bypassed by a bug on the other side. Moodle's own "Switch role to Student" is a
property of a browser session: the receiver has no session, so a switched teacher is judged by what they are
(a teacher), never by what the session pretends.

## What Moodle does with a valid statement

All of it in one database transaction; any failure rolls back and answers `500`.

1. Finds the user: it computes the pseudonym of every user enrolled in the course (an HMAC each, microseconds)
   and compares in constant time. The Moodle user id is never in the statement and cannot be derived from it.
2. Checks that the user may complete the course (above).
3. Stores the result (`format_rumbos_result`, unique on `attempt_id` and on `jti`).
4. **Best of check-in and final.** The course has **one** grade item, "Rumbos result" (idnumber
   `rumbos_result`, 0 to 100). It receives the percent only when it is **higher** than what the item holds,
   so a later worse attempt never lowers it. A statement with `passed: false` therefore records the grade
   (when it is a new best) and nothing else.
5. If `passed` is `true`, it marks the course's completion criterion for the user and aggregates their
   completion at once. The first pass, from either purpose, completes the course; after that the criterion is
   already marked and nothing is marked again, so the course completes exactly once and Moodle never moves
   the completion time.
6. Writes the audit record.

The completion is **not** a comparison of the grade with a "grade to pass". Moodle compares with "greater
or equal" (`completion_criteria_grade::review()`, `grade_grade::is_passed()`), and the backend may decide with
"strictly greater" and may change the rule, so the gradebook's pass mark is a display mirror only and never
decides anything. See [the audit](RESULT-PASSBACK-AUDIT.md#3-mechanisms-and-the-recommendation).

## The launch assertion already says who may be previewing

The launch assertion's `role` claim is `editor` for anybody who holds `format/rumbos:editmap` in the course
(an editing teacher, a manager, a site administrator) and `student` for everybody else. The backend must use
it to decide that a session is a **preview**: no assessment statement is ever sent for `editor`. No change to
the host contract was needed.

## The audit record

Every request that reaches the script writes a row to `format_rumbos_audit`, accepted or rejected: time,
HTTP status, reason, `kid`, `jti`, `attempt_id`, purpose, percent, passed, the policy, the pseudonym `sub` and,
once it is resolved, the Moodle user and course. Nothing else: no IP address, name or email. A flood of
requests that fail before their signature is checked is capped (60 audit rows a minute, then the request is
answered `401` and not written) so an attacker cannot fill the table; every request over 600 a minute for the
whole site is answered `429` and not written. The table is never purged by the plugin: it backs a
certificate (see the retention decision in [DECISIONS.md](DECISIONS.md)).

## Setting up a course

`cli/configure_course.php` (see the README). It is a dry run unless `--apply` is given. It creates the grade
item, the role that marks the criterion, the criterion, and the aggregation, and prints what it would change.
Nothing is configured on install or upgrade.

Desired final state of a course, and how to revert it, are in the
[audit](RESULT-PASSBACK-AUDIT.md#the-state-the-command-leaves-and-how-to-revert-it).

## Test statements without the backend

`dev/sign-test-statement.php` prints a signed statement for a **local test site** with a throwaway key it
generates itself, and refuses to run on a site that is not `localhost`. It is how the manual tests and the
end-to-end run were done without the backend. It is not in the release ZIP.
