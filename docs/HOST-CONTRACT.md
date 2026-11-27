# Host contract with the Rumbos app

What the Moodle page (the host) and the Rumbos app, which runs in an iframe, say to each
other, and what each side may rely on. The app side is implemented in the Rumbos frontend
against this document. This is **contract v5.11**: everything from v5 to v5.10 is unchanged
and v5.11 only adds to it.

All messages are `window.postMessage` objects with `v: 1`. The host ignores any message
that does not come from the configured app origin **and** from the iframe's own window,
and sends with an explicit target origin. Nothing the app sends can carry a URL the host
would follow.

## Messages

| Direction | Message | Fields | Meaning |
| --- | --- | --- | --- |
| app to host | `rumbos:embed-ready` | | The app is loaded and listening. |
| app to host | `rumbos:request-state` | | Send the course state again. |
| app to host | `rumbos:request-launch` | | Send a fresh launch assertion. |
| app to host | `rumbos:open-activity` | `cmid` | Open the activity in a popup or a new tab. |
| app to host | `rumbos:open-activity-settings` | `cmid` | **v5.4.** Open the activity's settings in a new tab. Editor mode only. |
| app to host | `rumbos:request-fullscreen` | `on` | Turn the full screen mode on or off. |
| app to host | `rumbos:request-stats` | `groupId?` | **v5.7.** Ask for the completion counts per activity, for the whole course (`0` or absent) or one group. Editor mode only. |
| app to host | `rumbos:request-flow-progress` | `mapId` | **v5.8.** Ask for the user's progress through the welcome flow of a map. |
| app to host | `rumbos:flow-step-done` | `mapId`, `stepKey`, `revision`, `method` | **v5.8.** Record that the user completed a step. |
| app to host | `rumbos:request-quiz-result` | `cmid` | **v5.8.** Ask for the user's own result in a quiz. |
| app to host | `rumbos:flow-reset` | `mapId` | **v5.8.** Editor mode only: reset the user's own progress on the map, for testing. |
| app to host | `rumbos:tutor-open` | | **v5.10.** Open the virtual tutor full screen over the page. Only if `capabilities.tutor` was announced. |
| app to host | `rumbos:request-activities` | | **v5.11.** Ask for the course activities a flow step may show. Only if `capabilities.activities` was announced. |
| app to host | `rumbos:activity-open` | `cmid` | **v5.11.** Open a course activity in the overlay. Only if `capabilities.activity` was announced. |
| app to host | `rumbos:error` | `code`, `detail` | The app failed; the host falls back to the standard course content. |
| app to host | `rumbos:no-content` | `reason` | **v5.5.** The app has nothing to show this user; the host falls back to the standard course content. |
| app to host | `rumbos:preferred-size` | `aspectRatio` | **v5.5.** The proportions of the level on display; the host applies them only in automatic height mode. |
| host to app | `rumbos:host-init` | `mode`, `locale`, `courseId`, `courseName`, `assertion`, `state`, `capabilities` | The answer to `embed-ready`. `courseName` is new in v5.6, `capabilities` in v5.10. |
| host to app | `rumbos:host-state` | `state` | A newer course state. |
| host to app | `rumbos:host-launch` | `assertion` | A fresh launch assertion. |
| host to app | `rumbos:host-fullscreen` | `on` | The full screen mode **as it is now**. |
| host to app | `rumbos:host-stats` | `stats` or `error` | **v5.7.** The answer to `request-stats`: the counts, or `error` (`capability` or `unavailable`). Never sent unasked. |
| host to app | `rumbos:host-flow-progress` | `items`, `persistent`, `config` or `error` | **v5.8.** The answer to `request-flow-progress`, `flow-step-done` and `flow-reset`. Never sent unasked. |
| host to app | `rumbos:host-quiz-result` | `result` or `error` | **v5.8.** The answer to `request-quiz-result`. Never sent unasked. `result` gains `previewAttempts`, `canAttempt` and `reason` in v5.9. |
| host to app | `rumbos:host-overlay` | `open` | An overlay (the activity popup) is above the map or has gone. |
| host to app | `rumbos:host-tutor-closed` | | **v5.10.** The tutor overlay was closed (button, Escape or the page). Sent only after `rumbos:tutor-open` opened it. |
| host to app | `rumbos:host-activities` | `items` or `error` | **v5.11.** The answer to `request-activities`. Never sent unasked. |
| host to app | `rumbos:host-activity-opened` | `cmid` | **v5.11.** The activity is on screen in the overlay. |
| host to app | `rumbos:host-activity-open-failed` | `cmid`, `reason` | **v5.11.** It could not be opened. |
| host to app | `rumbos:host-activity-closed` | `cmid` | **v5.11.** The overlay of that activity closed (button, Escape or the page). |

### Moodle activities in a flow (v5.11)

A step of the welcome or the closing flow can point at a plain Moodle activity (a PDF, a link, a page) by its course
module id instead of a Rumbos upload. The backend stores the bare `cmid` and cannot know whether it exists: **Moodle
is the authority**, and the page checks it every time.

```
host -> app: { type: "rumbos:host-init", v: 1, ..., capabilities: { activities: true, activity: true } }
app -> host: { type: "rumbos:request-activities", v: 1 }
host -> app: { type: "rumbos:host-activities", v: 1, items: [{ cmid: 41, name: "Guia de inicio", modname: "resource", visible: true }] }
           | { type: "rumbos:host-activities", v: 1, error: "capability" | "unavailable" }
app -> host: { type: "rumbos:activity-open", v: 1, cmid: 41 }
host -> app: { type: "rumbos:host-activity-opened", v: 1, cmid: 41 }
           | { type: "rumbos:host-activity-open-failed", v: 1, cmid: 41, reason: "not_found" | "forbidden" | "unsupported" }
host -> app: { type: "rumbos:host-activity-closed", v: 1, cmid: 41 }     // later, whoever closed it
```

The names follow the existing `rumbos:` family (`activity-open`, `host-activity-opened`, ...) instead of the dotted
`activity.open` of the proposal; the shape is the proposal's.

- **`capabilities.activity`** is announced to everyone; **`capabilities.activities`** only to users who may edit the map
  (`format/rumbos:editmap`). An absent key means off; the host never sends `false`.
- **No address ever travels in a message, in either direction.** `activity-open` carries only `cmid` (anything else is
  dropped, and a message with a `url` and no `cmid` is ignored). Moodle builds the address from the course module; the
  host checks it again (same origin, the view page of an allowed module or the plugin's inline page) and keeps it to itself.
- **`activities`** lists, for map editors only (also checked on the server), the activities of **this** course whose
  module is in the allow-list **`resource`, `url`, `page`** (constant `flow_activities::ALLOWED_MODULES`; extending it is a
  code change plus a test, on purpose: an interactive module does not belong in a flow step unseen). `visible: false` means
  hidden from students, so the editor sees that a step would fail for them. Activities being deleted are not listed.
  `name` is plain text, at most 255 characters. A repeat of the request while one is in flight joins it.
- **`activity-open`** is answered after Moodle checked, for the current user: the module belongs to the course
  (`not_found`, also for an id that does not exist, so ids cannot be probed), is not being deleted, is visible to this user
  (`forbidden` for hidden, restricted and not-enrolled), is in the allow-list (`unsupported`, for example a quiz) and the
  user holds the module's view capability (`forbidden`). A failure of the call itself is `not_found`
  (or `forbidden` when Moodle says the capability is missing). The message is ignored, without a reply, while another overlay
  is open.
- **The overlay** is the activity popup of the map (same-origin frame, close button, focus trap, Escape, focus restored,
  page scroll locked, the rest of the page inert), so a step looks like opening a map activity. `host-overlay` is sent
  as for any popup, then `activity-opened`; on close the refreshed state, `host-overlay { open: false }` and
  `activity-closed` follow.
- **Viewing completion is Moodle's own**: the overlay loads the activity's view page, which records the view. This feature
  writes no completion.

#### PDF file resource: display modes inside the overlay

A file resource has seven display modes. Measured on Moodle 5.2.4 with a one-page PDF (HTTP headers of the real responses).
That an inline PDF shows in an unsandboxed same-origin frame in Chrome was verified earlier (`DESIGN-activity-popups.md`);
it could not be screenshotted again here because the browser tool drops its debugger when a PDF viewer frame appears.

| Display mode | What `view.php` answers | Inside the overlay as is | What the plugin loads instead |
| --- | --- | --- | --- |
| Automatic (a PDF) | 303 to the file, `Content-Disposition: inline` | Shows the PDF | Nothing: `view.php` |
| Embed | Moodle page with the PDF in an `<object>`, `X-Frame-Options: sameorigin` | Shows the PDF (inside Moodle's page, chrome dropped by the popup mode) | Nothing: `view.php` |
| Open | 303 to the file, inline | Shows the PDF | Nothing: `view.php` |
| In frame | Frameset: Moodle's top bar and the file | Shows, but nested frames and a second bar | `resource.php` |
| New window | Page with a link that opens a new tab | **No**: a link, the PDF is not shown | `resource.php` |
| Force download | 303 to the file, `Content-Disposition: attachment` | **No**: the browser saves it, nothing is shown | `resource.php` |
| In pop-up | Page whose link runs `window.open` | **No**: a link | `resource.php` |

`resource.php` (the plugin's `/course/format/rumbos/resource.php?id=CMID`, used by the overlay only) applies the resource's
own login and capability checks, calls Moodle's `resource_view()` (the same call `view.php` makes: the view event and the
completion-on-view rule, nothing else) and redirects to the file **without** the forced download. Measured: for every mode it answers
`303` to the file with `Content-Disposition: inline`. It is used only for a PDF or a web image in the four modes marked above;
any other file (an office document, an archive) keeps the resource's own page, which is no worse than before. The
resource and its link in the course are not changed. This is what makes a PDF in the closing flow possible without a Rumbos upload.

### Virtual tutor (v5.10)

The site's tutor page sends `X-Frame-Options: SAMEORIGIN`, so the app (another origin) cannot frame it; the host does.
See [TUTOR.md](TUTOR.md) for the setting, the overlay and the decisions behind it.

```
host -> app: { type: "rumbos:host-init", v: 1, ..., capabilities: { tutor: true } }   // tutor only when the feature is on
app -> host: { type: "rumbos:tutor-open", v: 1 }
host -> app: { type: "rumbos:host-tutor-closed", v: 1 }
```

- **`capabilities`** is always present in `host-init`; it is `{}` when nothing is on. The app shows its "Tutor virtual"
  button **only** if `capabilities.tutor === true`. An absent key means off; the host never sends `tutor: false`.
- **`rumbos:tutor-open` carries no URL and no data.** Anything else in the message is dropped. The address is the admin
  setting `tutor_url`, https on the Moodle site's own host. The usual sender checks apply (origin **and** window);
  the host ignores the message, without a reply, when the feature is off, when the tutor is already open and while
  the activity popup is open.
- **`rumbos:host-tutor-closed`** is the only answer, also sent when the user closes the overlay in the host.
- Like every message in this contract both carry `v: 1`; a message without it is ignored.

### Completion statistics (v5.7)

The teacher heat map. The numbers are computed **inside Moodle as aggregates** and go to the iframe
only; the Rumbos backend never receives them, and no user id, name or per-user row is in them.

```
app -> host: { type: "rumbos:request-stats", v: 1, groupId?: int }        // editor mode only
host -> app: { type: "rumbos:host-stats", v: 1, stats: CourseStats }
           | { type: "rumbos:host-stats", v: 1, error: "capability" | "unavailable" }
CourseStats = { schemaVersion: 1, generatedAt, courseId, groupId: int (0 = all accessible),
                groups: [{ id, name }], tracked: int, minCohort: int, suppressed: boolean,
                activities: [{ cmid, completionTracked: boolean, complete: int, failed: int }] }
```

- **Editor mode only.** In any other mode the host answers `error: "capability"`. Asking is also
  checked on the server: the user needs `format/rumbos:viewstats` in the course, and the answer is
  `error: "capability"` if they lack it.
- **`groupId`** is `0` (or absent) for everyone the user may see, or the id of one of `groups`. A
  user who lacks `moodle/site:accessallgroups` in a course that uses groups gets only their own
  groups, and "everyone" means those groups together. A group outside what the user may see, or a
  request that fails for any other reason, is answered `error: "unavailable"`. A request whose
  `groupId` is not a non-negative integer is ignored like any malformed message.
- **Who is counted** (`tracked`): the students Moodle's completion reports track: actively enrolled
  (not suspended, enrolment method enabled, inside its dates), holding the capability
  `moodle/course:isincompletionreports`, not deleted.
- **`complete`** counts the states complete and complete with pass; **`failed`** counts complete with
  fail. The state that hides a failure from the student counts as neither, as the map shows it as
  unfinished. Anything not complete is the rest of `tracked`: **percentages are the app's to compute.**
- **`activities`** are the activities the map lists that the user can see. `completionTracked: false`
  (no completion condition, or completion off in the course) comes with zeros.
- **Suppression.** When `tracked` is below `minCohort` (the admin setting, default 5) `suppressed` is
  `true`, **`activities` is omitted** and `tracked` is `0`: no count of any kind is sent.
- **`generatedAt`** is when the numbers were computed, not when they were sent: results are cached on the
  server for 5 minutes per course and group, so a number can be that old.
- **Rate.** The host makes one call per group at a time and answers a repeat within 2 seconds with the
  previous answer, so a request loop costs nothing. The app should ask when the heat map is turned on
  and when the group changes, not on a timer.
- **Never in `host-init` or `host-state`.** Statistics are sent only as the answer to a request. The
  host does not log the numbers or keep them beyond that 2-second window.

### Welcome flow progress and quiz results (v5.8)

The app has a mandatory, multi-step welcome flow. Its **definition** lives in the Rumbos backend. **Who completed
which step lives in Moodle**, in the table `format_rumbos_flow`; no student data reaches the backend. The entry quiz
is a normal Moodle quiz taken in the activity overlay (`open-activity { cmid }`).

```
app -> host: { type: "rumbos:request-flow-progress", v: 1, mapId }
host -> app: { type: "rumbos:host-flow-progress", v: 1, mapId, items: [{ stepKey, revision, method, completedAt }],
               persistent: boolean, config: { quizThreshold: int } }
             | { type: "rumbos:host-flow-progress", v: 1, mapId, error: "readonly" | "unavailable" }
app -> host: { type: "rumbos:flow-step-done", v: 1, mapId, stepKey, revision, method: "completed" | "fallback" | "unavailable" }
             // answered with a refreshed host-flow-progress (or the error form)
app -> host: { type: "rumbos:request-quiz-result", v: 1, cmid }
host -> app: { type: "rumbos:host-quiz-result", v: 1, cmid, result: QuizResult }
             | { type: "rumbos:host-quiz-result", v: 1, cmid, error: "not_found" | "unavailable" | "capability" }
app -> host: { type: "rumbos:flow-reset", v: 1, mapId }     // editor mode only
QuizResult = { attempts: int, finishedAttempts: int, inProgress: boolean, percent: number|null,
               pendingGrading: boolean, passedThreshold: boolean|null,
               // v5.9, all three or none (see "Why a quiz result is what it is" below):
               previewAttempts?: int, canAttempt?: boolean,
               reason?: "ok" | "no_attempts" | "in_progress" | "preview_only" | "cannot_attempt" | "not_graded_yet" }
```

- **Whose data.** Everything is about the **current user**; there is no user id in any message. The host
  asks Moodle through external functions that check access again, and the answer goes to the app and nowhere else.
  It is **never part of `host-init` or `host-state`**, and the host does **not** push a result when the activity
  overlay closes or reloads: the app asks again (`request-quiz-result`). `host-overlay` is unchanged.
- **Validation (host side, same as the server).** `mapId` is a UUID (opaque to Moodle, stored in lower case);
  `stepKey` is 1 to 16 characters of letters, digits, `_` and `-`; `revision` is an integer from 0 to 1,000,000;
  `method` is one of the three; `cmid` is a positive integer. A message that fails is ignored without a reply.
- **`persistent`** is `false` when progress cannot be stored: a guest, somebody who used "log in as", or a user who
  takes no part in the course. The app then treats the flow as non-persistent. A write from such a user is answered
  `error: "readonly"`. Rate limit (30 writes a minute per user), the cap of 500 rows per user and course, and any failure
  are answered `error: "unavailable"`.
- **Writes are idempotent.** The same step and revision again keeps the earliest completion; a higher revision
  replaces the row; a lower one is ignored. The host sends writes one at a time, in the order received.
- **`completedAt`** is ISO 8601 in UTC, for example `2026-11-11T10:00:00Z`.
- **`config.quizThreshold`** comes from the course format option "Entry quiz result threshold (%)" (0 to 100,
  default 80). 0 means no branching on the result.
- **`percent`** is the quiz grade from the gradebook (so the grading method and teacher overrides apply), `null`
  when the student may not see it: the course hides grades, the user may not view grades, the grade (or the item)
  is hidden or hidden until a date, the quiz's review options do not show marks for every finished attempt, the quiz
  is ungraded, or there is no finished attempt. **`passedThreshold`** is `null` when the threshold is 0, `percent` is
  `null`, or grading is pending, else `percent > quizThreshold`: **strictly above**, the rule of the product and of the
  Rumbos service, so exactly the threshold is not a pass and nothing passes a threshold of 100 (it was `>=` before
  2026-11-27; see D34 in `docs/DECISIONS.md`). `finishedAttempts` counts only attempts in the
  finished state (not abandoned ones, not previews); `inProgress` is true for an open or overdue attempt;
  `pendingGrading` is true when a finished attempt still needs manual grading (or is submitted and not yet graded).
- **Errors of `request-quiz-result`.** `not_found`: no such quiz in this course, or the user cannot see it.
  `capability`: the user may not view the quiz. `unavailable`: anything else.
- **`flow-reset`** resets the **current user's own** progress on that map, for testing. The host answers a page that is
  not in editor mode with `error: "readonly"` without asking Moodle, and Moodle needs `format/rumbos:editmap`.
  After a write or a reset the refreshed `host-flow-progress` is sent.

**Normative decisions for every implementation of this contract** (the app and the backend included):

1. `completedAt` is a **string**: ISO 8601 in UTC with the `Z` suffix (`2026-11-11T10:00:00Z`).
2. `stepKey` is accepted by the host as `[A-Za-z0-9_-]{1,16}`, a superset of the backend's pattern. A step key outside it is
   ignored without a reply.
3. `revision` is accepted as an integer from 0 to 1,000,000.
4. When the grade is hidden from the student, `percent` and `passedThreshold` are `null`.
5. `not_found` is also the answer for an activity that exists but is hidden from the user.
6. `inProgress` can remain `true` after the quiz's time limit has passed, until Moodle submits the attempt (cron or the next
   view of the quiz). The app must not read it as proof that the student is still working.

What the activity overlay supports for the entry quiz is in [MANUAL-TESTS.md](MANUAL-TESTS.md#welcome-flow-progress-and-quiz-results-release-060).

### Why a quiz result is what it is (v5.9)

A number alone cannot tell the app, or the person giving support, **why** a student who believes they finished a
quiz has `finishedAttempts: 0`. The usual cause is not a bug: Moodle records the attempts of anybody who holds
`mod/quiz:preview` (a teacher, a manager, a site administrator, a user with both a student and a teacher role) as
**previews**, which are not attempts, never reach the gradebook and are not counted. The evidence is in
[QUIZ-PREVIEW-ATTEMPTS.md](QUIZ-PREVIEW-ATTEMPTS.md). `QuizResult` therefore gains three fields. They are
**derived when the result is read**: nothing new is stored.

| Field | Meaning |
| --- | --- |
| `previewAttempts` | The current user's own preview attempts in this quiz that were started. They are **never** added to `attempts` or `finishedAttempts`. |
| `canAttempt` | The user holds `mod/quiz:attempt` in the activity. It is `false` for a teacher or a manager, and `true` for a student and for a site administrator. |
| `reason` | One value, decided in the server by the precedence below. |

**`reason`, first match wins.** The order is fixed and the app must not recompute it:

| # | `reason` | When |
| --- | --- | --- |
| 1 | `cannot_attempt` | `canAttempt` is `false`. It wins over everything, including a finished attempt the user made earlier in another role. |
| 2 | `ok` | At least one **real** attempt is finished and has its mark (`finishedAttempts` counts it). |
| 3 | `in_progress` | A real attempt is open: in progress, or overdue and not yet submitted by Moodle. |
| 4 | `not_graded_yet` | The finished real attempts all wait for manual grading, or an attempt was handed in and is not processed yet. |
| 5 | `preview_only` | The user has previews and nothing else. |
| 6 | `no_attempts` | None of the above. This includes a user whose only attempts were abandoned (`attempts` can then be above 0). |

- **`ok` says nothing about the grade.** A hidden grade, a course that hides grades or review options that hide the marks
  give `percent: null` and `passedThreshold: null` under any reason; `reason` is the same with the grade shown or hidden, so
  it cannot give a hidden grade away. To gate a step on the quiz keep using `finishedAttempts` and `percent` as in v5.8;
  use `reason` to **tell the user what to do**: `cannot_attempt` and `preview_only` mean "this account cannot complete the
  quiz, use a student account", `in_progress` means "press **Submit all and finish**", `not_graded_yet` means "wait for the
  teacher".
- **An administrator** holds both capabilities, so `canAttempt` is `true`, but Moodle still makes the attempt a preview
  (`mod/quiz:preview` decides, not the lack of `mod/quiz:attempt`): the reason is `preview_only` once one exists.
- **A role switched to Student** (Moodle's "Switch role to...") removes `mod/quiz:preview` for that session, so the attempt is
  real and the reason is `ok` or `in_progress`. An **earlier open preview** is resumed instead of starting a real attempt, so
  a switched user who left a preview open finishes a preview (`preview_only`). A new attempt after it starts a real one and
  deletes the earlier previews.
- **Old apps.** Everything up to v5.8 is unchanged: the same six fields with the same names, order and values. The host sends
  the three new fields **only when all are present and well formed**, otherwise the message has exactly the v5.8 shape. An app
  that does not know them ignores them; a v5.9 app must treat their absence as "unknown", not as `ok`.
- **Whose data.** `previewAttempts` and `canAttempt` are the user's **own** attempts and capability, checked for the activity
  exactly as in v5.8 (`not_found` and `capability` are unchanged). No other user's data is in the answer.

**For support.** The state behind a result can be read without the app with the administrator's command line script
`cli/quiz_check.php` (see the README). It is read only: it logs and stores nothing.

### `uid` in the launch assertion (additive, optional)

The launch assertion (the `assertion` of `rumbos:host-init` and `rumbos:host-launch`) can carry one more signed claim,
`uid`: the Moodle user id as a JSON integer. It is **additive** and **off by default**: with the setting off the
claims are exactly `iss, aud, sub, course, role, iat, exp, jti` as before, and no message of this protocol changes.
A service that does not read `uid` ignores it. The app never sees the claim as such; it only forwards the assertion.
The service must accept the claim before a site turns it on (deploy order: service first). Why it exists and how it is
used: [FINAL-EXAM-GATE.md](FINAL-EXAM-GATE.md).

### `courseName` in `rumbos:host-init` (v5.6)

`courseName` is the course full name as the user sees it, as **plain text**: the site's string
filters have run (a multi-language name arrives in the user's language), every tag is gone,
entities are decoded, runs of whitespace are one space, and it is cut at **200 characters**. It is
never HTML, but it can contain `<`, `>` or `&` as ordinary characters, so the app must render it
as text (`textContent`), never as markup. It can be an empty string only if the course has no name.
It is sent once, with `host-init`; a later rename shows after the page is reloaded.

### CourseState

`state` is computed by Moodle for the signed-in user and nobody else. Fields:
`schemaVersion`, `courseId`, `generatedAt` (an ISO 8601 string in UTC), `sections`,
`activities`, `unavailableCmids`, and, new in v5.4:

- `courseCompletionEnabled` (boolean): whether completion tracking is on for the course
  (the site-wide switch and the course's own). When it is `false` every activity reports
  `completion: "none"`, and the app can say that the course tracks no progress instead of
  showing activities that never complete.

Each activity's `completion` is one of:

| Value | Moodle's state |
| --- | --- |
| `none` | The activity has no completion condition (or the course does not track completion). |
| `incomplete` | A condition is set and has not been met. |
| `complete` | Complete, or complete with a passing grade. |
| `failed` | Moodle recorded the completion as failed: the activity needs a grade, the user has one, and it is below the grade to pass. |

`failed` is Moodle's own overall state and is not always final: a quiz that requires a
passing grade stays `failed` until a later attempt passes. When the quiz also accepts
"all attempts used" Moodle keeps the overall state `failed` once the attempts are used up
without a pass, even though the attempts rule itself is met.

The state is read afresh each time (see [the refresh policy](#refresh-policy)): the host
never serves it from Moodle's completion cache.

### `rumbos:open-activity-settings` (v5.4)

```
app -> host: { type: "rumbos:open-activity-settings", v: 1, cmid }
```

Lets a teacher who is editing the map jump to the activity's "Activity completion"
settings in one click, so the app can offer it next to an activity that never completes.

The host acts only when **all** of these hold, and otherwise ignores the message without
an answer:

1. The page is in **editor mode** (the user holds `format/rumbos:editmap` and has Moodle's
   editing mode on).
2. `cmid` is in the host's own last state.
3. Moodle confirms, for this user and this activity, that they may edit the map and manage
   the activity (`moodle/course:manageactivities`), that the activity belongs to the course
   and is not being deleted.
4. The address Moodle returns is the settings page of **this site**.

Then the host opens it in a **new tab** with `noopener`. The address is Moodle's own
(`/course/modedit.php?update={cmid}&showonly=activitycompletionheader`, the one its
completion dialog links to), which opens the settings form with only the "Completion
conditions" section expanded. The app never supplies a URL, and gets no reply. If the
browser blocks the new tab, the host shows a Moodle notification and does not navigate the
page, so unsaved work in the map editor is not lost.

### `rumbos:no-content` (v5.5)

```
app -> host: { type: "rumbos:no-content", v: 1, reason: "no_saved_map" | "empty_map" }
```

The app has nothing to show this user: no map was ever saved for the course
(`no_saved_map`) or the saved map has no bubbles (`empty_map`). A student must never be left
on an empty page, so the host hides the iframe and shows the standard course content.

- **Students:** silently. No notice, and the list of activities opens.
- **Users who may edit the map** (`format/rumbos:editmap`, whether or not editing is on):
  the same, with a notice that names the reason and what to do ("There is no saved map
  yet. Turn editing on for the course and press Save." / "The map has no bubbles yet.").
  The capability decides, not the editing switch, because the teacher who has to save the
  map normally has editing off when they first see the course.

The message is checked like all the others (origin, source window, `v`, shape). A `reason`
outside the two values is ignored, and so is any message after the first one that was acted
on. The app should send it instead of its own empty screen for a student; in the map
editor (`mode: "editor"`) it should show its "create the first level" call to action.
The host publishes `data-state="degraded"` and `data-reason="no_saved_map"` (or
`empty_map`) on the container, for tests.

### `rumbos:preferred-size` (v5.5)

```
app -> host: { type: "rumbos:preferred-size", v: 1, aspectRatio }
```

`aspectRatio` is width divided by height, a finite number from 0.5 to 4 (bounds included;
anything else is ignored). The app sends it when the displayed level changes.

The host applies it **only when the course's height mode is "auto"**; in "viewport" and
"fixed" it is ignored, because the course settings decide there. In "auto" the height is
the container's width divided by the ratio, clamped to the site minimum and maximum. The
host tracks the width with a `ResizeObserver` and keeps the proportions when the window is
resized. A change of level is animated for 0.25 seconds, unless the user prefers reduced
motion. Until the first message the area reserves a 16 / 9 box within the same limits, so the
page does not jump. On small screens and in full screen the message has no effect: the
area takes a percentage of the screen height, or the whole screen.

## Size of the area

How tall the area is comes from the course options, then the site defaults, then the
built-in values (`auto`, 80 percent on small screens). The app does not choose it, except
through `rumbos:preferred-size` in automatic mode.

| Setting (site) / option (course, prefixed `rumbos_`) | Values | Meaning |
| --- | --- | --- |
| `heightmode` | `auto`, `viewport`, `fixed` (empty in a course: the site default) | How the height is decided. |
| `heightvalue` | 40 to 100 for `viewport` (percent of the screen height), 360 to 1600 for `fixed` (pixels); 0 or empty: the default | The height. Not used with `auto`. |
| `mobileheight` | 50 to 100 (percent of the screen height); 0 or empty: the default | Height on screens narrower than 768 px. |
| `minheight`, `maxheight` (site only) | 200 to 1000 and 400 to 4000 (pixels) | Limits no course can exceed on a computer screen. |

Full screen ignores all of this and is exactly the viewport. The course options are
ordinary format options: they are in the course settings form and in backups, and they hold
no ids.

## Full screen

`rumbos:request-fullscreen { on }` asks for the full screen mode. The host answers with
`rumbos:host-fullscreen { on }` carrying what actually happened, and sends the same message
whenever the mode changes for any other reason: the host's exit control, Escape, the browser
ending its own full screen, or the host giving up on the app. The app follows the host.

The mode is a CSS overlay that covers the viewport, so it works everywhere, including
iPhone Safari. Where the browser has the Fullscreen API the host also asks it for the
browser's own full screen; a refusal changes nothing the app can see.

### Delegated browser features (the video player)

The app shows its welcome video in an iframe of its own, so the page nests three documents:
Moodle, the app, the player. The browser lets the player enter full screen or play without a
click on it only when **every iframe in the chain delegates the feature** and no
`Permissions-Policy` response header forbids it.

| Link | Who sets it | Value |
| --- | --- | --- |
| The Rumbos iframe in the Moodle page | The plugin (`templates/embed.mustache`) | `allow="fullscreen; autoplay"` and `allowfullscreen` |
| The video iframe in the app | The app | Must include `fullscreen` (and `autoplay` for autoplay) |
| Response headers of the Moodle page, the app's `/embed` and the player's page | Moodle, the app, the video provider, and any proxy or CDN in front of them | No `Permissions-Policy` or `Feature-Policy` that removes `fullscreen` or `autoplay` |

The plugin delegates only these two features. The sandbox is unchanged, and fullscreen and
autoplay are not sandbox flags. The plugin and stock Moodle 5.2 send no `Permissions-Policy`
or `Feature-Policy` header (Moodle sends `X-Frame-Options: sameorigin` on its own pages,
which is unrelated). A site administrator or a proxy must therefore not add a policy such as
`fullscreen=()` or `autoplay=()`, or one that omits the app's origin. This host's CSS full
screen overlay and the activity popup do not change the delegation: the overlay is a CSS
class on the wrapper and the popup is a sibling of the Rumbos iframe, not an ancestor.

Troubleshooting is in the README, under "The video's full screen button does nothing".

### Reserved area

While the mode is on (`host-fullscreen { on: true }`) the host owns the **top-right 64 x 64
CSS pixels of the viewport**. An exit control sits there, labelled "Exit full screen",
always above the app, reachable with the keyboard, and working even if the app is frozen.

The app must:

- keep that area free of interactive controls, and
- hide its own fullscreen toggle until the mode ends (`host-fullscreen { on: false }`).

Anything the app draws in that corner is covered by the host's control.

On exit the host restores the focus and the scroll position the page had before. Escape
leaves the mode when the focus is on the Moodle page; the browser does not deliver keys
pressed inside the iframe to the page, so the app should not rely on the host to see them.

The activity popup opens above the full screen mode. When the browser's own full screen is
active the popup is mounted inside the fullscreen element (anything outside it is not shown),
so opening an activity does not end the full screen and closing it returns to the map still in
full screen. If the user leaves the browser's full screen with Escape while a popup is open, the
mode ends and the popup stays open where it is: moving its frame would reload the activity.
A full screen request made while a popup is already open is a CSS overlay only.

## Refresh policy

The host reads the course state again at the moments completion can change:

| When | What the host does |
| --- | --- |
| The app asks (`request-state`) and at `embed-ready` | Reads and sends `host-state`. |
| Every navigation of the activity popup's frame (its `load` event) | Schedules a read, debounced by 400 ms and at least 2 s after the previous scheduled one, and sends `host-state` **if the state changed**. A quiz submission loads a review page, so this catches it. |
| Every 15 s while the popup of a **polled module** is open and the tab is visible | Reads through the same single-flight reader and sends `host-state` **if the state changed**. The modules are the admin setting `pollmodules` (default `scorm,h5pactivity`; empty turns it off). Stops when the popup closes. |
| The popup closes | Reads once more, always sends `host-state`, then `host-overlay { open: false }`. |

Rules:

- **One request at a time.** A read in flight is joined, never duplicated; a navigation that
  happens meanwhile triggers one follow-up read afterwards, and the read on close always
  starts after the close.
- **A failed read keeps the last state.** Nothing is sent and the error is only logged.
- **No polling while no overlay is open, and only for the listed modules while one is.** The
  host reads nothing on a timer otherwise. A hidden tab skips the poll and it resumes when the tab
  is visible again. The app refreshes on visibility and on its own interval by sending `request-state`.
- **What it cannot see.** Completion that changes without the popup navigating, in a module that
  is not polled (a teacher grading later, a scheduled task, any module outside `pollmodules`), is
  seen at the next read: the close of the popup or the app's next `request-state`. For a polled
  module it is seen within about 15 seconds.
- **What a read costs** is measured in [COMPLETION-STATES.md](COMPLETION-STATES.md#what-a-read-costs).

## Where this is implemented

- `amd/src/protocol.js` parses and builds messages (tested in `tests/js/protocol.test.mjs`).
- `amd/src/stats_gate.js` limits the statistics requests (`tests/js/stats_gate.test.mjs`).
- `amd/src/flow_calls.js` joins flow reads and orders flow writes (`tests/js/flow_calls.test.mjs`); the flow messages are tested in `tests/js/protocol_flow.test.mjs`.
- `classes/local/flow_progress.php` stores the rows, `quiz_result.php` computes the quiz result, and `classes/external/get_flow_progress.php`, `set_flow_step_done.php`, `reset_flow_progress.php` and `get_quiz_result.php` are the AJAX functions.
- `classes/local/course_stats.php` computes the counts and `classes/external/get_course_stats.php` is the AJAX function.
- `amd/src/refresher.js` is the single-flight, throttled reader (`tests/js/refresher.test.mjs`).
- `amd/src/host.js` wires them to the page.
- `classes/local/map_size.php` resolves the height settings; `styles.css` turns them into the layout.
- `classes/local/course_state.php` computes the state; `classes/external/get_course_state.php`
  and `get_activity_settings_url.php` are the two AJAX functions the host calls for this.

## Preview and results (no new field)

The launch assertion already carries `role`: `editor` for anyone who holds `format/rumbos:editmap` (editing teacher, manager,
site administrator), `student` otherwise. The app uses it to mark an attempt as a **preview**. It does not depend on editing
mode or on a role switched in the browser. Results the app produces are reported by the Rumbos **service**, not by the
host, in a signed statement: see [RESULT-PASSBACK.md](RESULT-PASSBACK.md). The receiver refuses a statement for anyone who
is not a student (`422 role_not_allowed`), so a preview can never complete a course even if the service sent it. The host
contract is unchanged by this feature.
