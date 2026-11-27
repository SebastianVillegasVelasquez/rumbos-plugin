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
Content-Type: application/jwt

<compact JWS>
```

That is what the Rumbos service sends. The JSON body `{ "statement": "<compact JWS>" }` is accepted too. The shape of
the body decides, not the content type. A body over 512 KB is refused.

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

A JWS, compact serialization. The protected header carries `kid`.

### The algorithm comes from the key

`kid` picks one of the configured keys, and **the type of that key decides the only algorithm accepted**:

| Configured key | Algorithm | Who signs with it | Claim rules |
| --- | --- | --- | --- |
| EC P-256, given as a JWK | `ES256` only | the Rumbos service | below |
| RSA, 2048 bits or more, given as PEM | `RS256` only | `dev/sign-test-statement.php` | [the development form](#the-development-signers-form-rs256) |

The header's `alg` must be exactly that. It never selects anything: `none`, any HMAC (an HMAC with the public key as
its secret included), another curve or the other key's algorithm are `401 bad_signature`.

**ES256 signatures.** A JWS carries `R || S`, exactly 64 octets (RFC 7518, section 3.4); OpenSSL verifies the DER
`SEQUENCE { INTEGER r, INTEGER s }` (RFC 3279, section 2.2.3). The plugin converts between them itself
(`classes/local/es256.php`, with the DER integer rules of X.690 section 8.3) and refuses any other length. The bundled
JWT library is not used for this: its conversion halves a signature of any length.

All signature checks are in one function, `result_statement::decode()`. `result_statement::verify_envelope()` adds
issuer, audience, tenant, `jti` and `iat`, for a receiver of other messages of the service.

### The service's statements (ES256)

The source of truth is the service's `contracts/` and its signed vectors, copied to
`tests/fixtures/service_vectors/`.

| Service claim | What the plugin does with it | Rule |
| --- | --- | --- |
| `iss` | Compared with "Result issuer" (the service's `PASSBACK_ISSUER`, by default `rumbos-backend`). | Required. `401 bad_issuer`. |
| `aud` | Must be `moodle`, the service's constant. | Required. `401 bad_audience`. |
| `tenant` | Compared with "Result tenant". | Required. `401 bad_tenant`. |
| `jti` | **The id of the attempt: the idempotency key** (stored as both `jti` and `attemptid`). | Required, `[A-Za-z0-9._-]{1,64}`. |
| `iat` | When the statement was created. | Integer. At most 300 s in the future. **No age limit.** One of `iat` and `finished_at` is required. |
| `finished_at` | When the attempt finished (stored). `iat` when absent. | ISO 8601 with a zone, or Unix seconds. At most 300 s in the future. |
| `exp`, `nbf` | Not sent by the service. Honoured when present (`exp` with 60 s of leeway). | `401 expired`, `401 not_yet_valid`. |
| `sub` | The pseudonym of the launch assertion's `sub`, which finds the Moodle user. | Required, 32 lower case hexadecimal characters. |
| `course_id` | The Moodle course. | Required, positive integer. |
| `purpose` | Stored and audited. | Required, `check_in` or `final`. |
| `percent` | The grade (best of all attempts). | Required, string with two decimals. |
| `passed` | The service's decision. Moodle never recomputes it. | Required, boolean. |
| `completes_course` | The course is completed, and the content when auto-complete is on, only if this **and** `passed` are true. | Required, boolean. It is false when the rule in force did not exempt a student who passed. |
| `pass_percent`, `pass_operator` | The rule, recorded in the audit (`gt:80`). It decides nothing. | Optional, both or neither; 0 to 100, and `gt` or `gte`. |
| `kind`, `schema` | Neither: a schema 1 result. `kind: "attempt"` with `schema: 2`: an attempt record, applied as the same result. `kind: "definition"`: [below](#exam-definitions). | Anything else is `422 unknown_kind`. |
| `ordinal`, `form_id`, `content_version`, `status`, `definition_hash`, `percent_exact`, `sections`, `answers`, `written`, ... | Ignored and not stored. | |

No `attempt_id`, no `policy`, no `exp` is needed.

**Why there is no expiry and no age limit.** The service's outbox keeps a statement until it is delivered, for days if
this site is down, and signs it again each time. A statement that arrives twice changes nothing, because its `jti` is
applied once. A future time is refused: 300 s of skew is what the service's contract assumes. A refusal is `401`, which
the service treats as **permanent**, so both servers need a synchronised clock.

**Why the audience may be `moodle`.** It does not name a site, so what ties a statement to this one is the signature of
a key configured here, the tenant, and for a result the `sub`: an HMAC with a secret of this site, which no other site
resolves to a user.

### Exam definitions

With a tenant's mirror on, the service sends the **definition** of a published exam to the same address, and sends the
results of that exam only after the definition was accepted. The service can send over HTTP only to a tenant with a
mirror endpoint, so for a published exam this is the normal case. This plugin has no exam mirror and stores no
definition. A definition whose signature, issuer, audience and tenant are right is answered according to the setting
**Acknowledge exam definitions without storing them** (`resultsackdefinitions`, off by default):

- **Off:** `503 definition_not_supported`. The service retries it forever and holds that exam's results. Nothing is
  lost, and nothing arrives.
- **On:** `200 { "applied": false, "reason": "definition_not_stored" }`. The service marks it delivered and sends the
  results. **The definition is not kept**, nor the answers inside the attempt records, and the service will not send
  them again by itself. An exam mirror added later has to be filled again (the service's `mirror sync` queues
  definitions again, not attempts it already delivered).

### The development signer's form (RS256)

Unchanged. It is not what the service sends.

| Claim | Type | Checked against |
| --- | --- | --- |
| `iss` | string | "Result issuer". |
| `aud` | string | This site's issuer, as the registration panel shows it. |
| `jti` | string, `[A-Za-z0-9._-]{1,64}` | Unique per statement. |
| `iat` | int | At most 60 s in the future. |
| `exp` | int | In the future (60 s of leeway) and at most 300 s after `iat`. Required. |
| `tenant`, `sub`, `course_id`, `purpose`, `percent`, `passed` | | As above. |
| `attempt_id` | string, `[A-Za-z0-9._-]{1,64}` | The idempotency key. Required. |
| `policy` | object | `pass_percent` and `pass_operator`. Required, audit only. |
| `finished_at` | string or int | Required. |

No name, no email, no IP address: a result carries the user reference and the score. An extra claim is ignored.

### Keys, `kid` and rotation

The "Result keys" setting holds:

- **EC P-256 (the service):** a JWK, or a JWKS `{"keys": [ ... ]}` for more than one. Each needs `kty`, `crv`, `x`, `y`
  and `kid`, the value the service signs with (`PASSBACK_KEY_ID`). A JWK with a private part (`d`) is refused.
- **RSA (development):** PEM `PUBLIC KEY` blocks, placed before any JSON. The id is the first 16 hexadecimal characters
  of the SHA-256 of the DER SubjectPublicKeyInfo.

A JWK, not PEM, for EC because the service's `kid` is chosen by its operator and a PEM block does not carry it. The
service has no command that prints a JWK, so make it from the public PEM:

```sh
# where the private key is; only the public file leaves that machine
openssl pkey -in passback-private.pem -pubout -out passback-public.pem
# on the Moodle server
php public/course/format/rumbos/cli/ec_public_jwk.php --kid=passback-1 --file=/path/passback-public.pem
```

Several keys are trusted at once. **To rotate:** (1) add the new JWK, with a **new** `kid`, next to the old one;
(2) change the service's key and `PASSBACK_KEY_ID` together; (3) when the service's outbox has nothing pending, remove
the old JWK. The service signs when it sends, so waiting statements go out with the new key.

## What the receiver answers

| HTTP | Body | When |
| --- | --- | --- |
| `200` | `{ "applied": true, "reason": "completed" }` | The result is recorded and **this statement** completed the course. |
| `200` | `{ "applied": true, "reason": "recorded" }` | The result is recorded; the course completion did not change (a fail, or a pass after the course was already complete). |
| `200` | `{ "applied": false, "reason": "already_applied" }` | The attempt (the service's `jti`; the development form's `attempt_id`) was applied before. Nothing changed. |
| `200` | `{ "applied": false, "reason": "definition_not_stored" }` | An exam definition, acknowledged and not stored (the setting is on). |
| `400` | `{ "applied": false, "reason": ... }` | Neither a compact JWS nor the JSON form, too long, or a claim missing or of the wrong type. `malformed`. |
| `401` | | Bad signature, unknown `kid`, wrong issuer, audience or tenant, expired. `bad_signature`, `unknown_key`, `expired`, `not_yet_valid`, `bad_issuer`, `bad_audience`, `bad_tenant`. |
| `405` | | Not a `POST`. |
| `409` | | The `jti` was used before with a **different** payload. `jti_conflict`. |
| `422` | | `unknown_kind` (an ES256 statement that is not a result, an attempt or a definition of a known schema); `unknown_course` (no such course, or not Rumbos); `unknown_user` (no user of that course has this `sub`); `not_enrolled` (enrolment inactive, suspended or ended); `role_not_allowed` (not a student: a teacher, manager, administrator, or anybody who may edit the map). |
| `429` | | Too many requests. **Treat like a `5xx`**: retry later, with `Retry-After`. |
| `503` | | `definition_not_supported`: see [Exam definitions](#exam-definitions). `not_configured`: the receiver is off, or the course is not set up (`course_not_configured`: no result grade item, no completion criterion, or completion switched off). **Transient**: the result is not lost, the sender keeps it and retries. |
| `500` | | Anything unexpected. Nothing was applied (the change is one database transaction). **Transient.** |

The body is always `{ "applied": bool, "reason": string }`. A sender must treat `422`, `401`, `400`, `409`
as permanent for that statement (and alert: they mean misconfiguration or a bug) and `429`, `5xx` as
transient. The service does so, and parks a permanent one for an operator. A **resend** carries the same claims with a
new signature (ECDSA signatures differ every time) and is answered `already_applied` if the first one got through. (In
the development form, a retry is a new statement with the same `attempt_id` and a new `jti`.)

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
5. If `passed` is `true` and `completes_course` is not `false`, it marks the course's completion criterion for the user and aggregates their
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

## Production: configure the key and verify with a real statement

Nothing here was run against production or staging.

**Before.** Both servers' clocks synchronised. The plugin with this change installed, upgrade run, caches purged. The
course set up (`cli/configure_course.php`, [README](../README.md)). On the service, leave `PASSBACK_SENDER=none` until
step 5, and look at what is waiting: `python -m app.scripts.passback status`. **Everything pending is sent when the
sender is turned on**, results of old pilots included; a permanent refusal parks them.

1. **Public key and kid.** Find `PASSBACK_KEY_ID` (default `passback-1`). Where `PASSBACK_SIGNING_KEY_PEM` is kept, run
   `openssl pkey -in passback-private.pem -pubout -out passback-public.pem`. Copy **only** the public file to Moodle and
   run `php public/course/format/rumbos/cli/ec_public_jwk.php --kid=<PASSBACK_KEY_ID> --file=passback-public.pem`.
2. **Moodle settings** (Site administration > Plugins > Course formats > Rumbos): "Result keys" = that JSON (it replaces
   any development key; keep none in production); "Result issuer" = the service's `PASSBACK_ISSUER` (default
   `rumbos-backend`; the setting's own default is `rumbos`, so it must be changed); "Result tenant" = the service's
   tenant UUID, the `tenant` claim; "Accept results" on.
3. **Definitions.** Decide [what happens to exam definitions](#exam-definitions). For results of published exams to
   arrive, turn on "Acknowledge exam definitions without storing them", knowing they are then not kept.
4. **Auto-complete** is separate and off by default (needs legal review).
5. **Service:** set `PASSBACK_SENDER=http`, then give the tenant its address:
   `python -m app.scripts.mirror configure --tenant <id|issuer> --endpoint https://<moodle>/course/format/rumbos/passback.php`
   Leave `--written-text` off. Run `python -m app.scripts.passback sync-run` (or let cron).
6. **Verify.** Have one real test **student** (not a teacher, not a preview) finish a check-in. Then check:
   - the service: `python -m app.scripts.passback status` shows it sent, nothing failed;
   - Moodle: `select timecreated, status, reason, kid, jti, courseid, userid, passed, policy from mdl_format_rumbos_audit
     order by id desc limit 10;` (your table prefix) shows `200` with `completed` or `recorded`, your `kid`, and the
     attempt's id as `jti`; a definition shows `definition_not_stored`;
   - the gradebook item "Rumbos result" holds the percent, and after a pass the course completion report shows the
     student complete;
   - then a failing attempt of another test student: `recorded`, not complete.
7. **When it is refused**, the audit's `reason` says why: `unknown_key` (kid), `bad_signature` (another key pair),
   `bad_issuer`, `bad_audience`, `bad_tenant`, `not_yet_valid` (clocks), `unknown_user` (the user is not enrolled, or
   the site's pseudonym secret differs from the one that made the launch), `course_not_configured`,
   `definition_not_supported` (step 3). A `401`, `422` or `400` is parked by the service as `failed_permanent`: it is
   not sent again by itself.

**To turn it off:** set `PASSBACK_SENDER=none` on the service, or untick "Accept results" (then `503`, and the service
keeps and retries). What was applied stays.

## Test statements without the backend

`dev/sign-test-statement.php` prints a signed statement of the development form (RS256) for a **local test site**
with a throwaway key it generates itself, and refuses to run on a site that is not `localhost`. It is how the manual tests and the
end-to-end run were done without the backend. It is not in the release ZIP. `--setup` **replaces** the configured
keys, issuer and tenant.
