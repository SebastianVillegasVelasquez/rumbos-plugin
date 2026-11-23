# Design audit: a "diagnostic only" mode for a locked level

**Status: report only. Nothing in this document is implemented, and nothing here is approved.** It answers one
question for the product owner: what would it take for a student who has not completed the previous level to open Rumbos
limited to the welcome flow and its check-in, with the map and the SCORM bubbles locked?

It exists because of a copy question. The owner's proposed text, "Debes de completar el nivel anterior **o la evaluación
diagnóstica** para poder completar este nivel", promises a way in that the current design does not have: a locked
student gets no launch assertion and no iframe, so they **cannot reach the diagnostic at all** (see
[LEVEL-PREREQUISITES.md](LEVEL-PREREQUISITES.md)). Until a mode like this one exists, that text is untrue.

What this document knows and does not know: the plugin side is read from this repository. **The Rumbos app (front) and
the service (backend) are not in this repository.** Every statement about them below is an assumption to confirm with
their owners, and every estimate for them is a guess, not a measurement.

## What the plugin does today

- `level_gate::check()` decides `locked`, `met`, `bypass`, `error`, `off` or `notgated`.
- When the decision is closed, the course page renders a message and **no iframe**; `format_rumbos_get_launch_assertion`
  throws and **signs nothing** (`classes/external/get_launch_assertion.php`). The signed claims are
  `iss, aud, sub, course, role, iat, exp, jti` and, optionally, `uid`.
- The student therefore never talks to the Rumbos service for that course.

## What the mode would need, piece by piece

### 1. A signed claim (plugin, additive)

The plugin would sign one more claim for a locked student **instead of refusing**, for example `level_locked: true`
(absent for everybody else). Properties to keep:

- **Additive and optional**, like `uid` (`docs/HOST-CONTRACT.md`): a service that does not know the claim ignores it, so
  the claim set of every other student is byte-for-byte what it is today.
- **Behind its own admin setting, off by default**, separate from `levelgate`, so the gate can run with today's behaviour.
- The claim is a statement by Moodle, signed like the rest; the browser cannot add or drop it.
- **Open question, not verifiable here:** whether the backend accepts an unknown claim. The `uid` precedent says the
  deploy order must be **service first**, and `uid` was introduced on that rule. The backend owner must confirm that
  verification does not reject unknown claims, and that a missing `level_locked` means "not locked" (so an old plugin
  next to a new service stays safe).

The plugin change is small: the gate's `locked` branch signs instead of throwing when the setting is on, and the page
renders the iframe with a different message above it. The risk is that this also touches the **fail-closed** rule: an
`error` decision must still sign nothing. Tests for that are part of the cost.

### 2. What the front must do with it (a separate task, in the app repository)

When it receives `level_locked`, the app must:

- open the welcome flow and its check-in, and nothing else;
- show the map and the SCORM bubbles as locked, without a way to open them (the popup/launch path for an activity is
  the app's; the plugin only receives the open request through the host contract);
- never present a state that suggests the level is open (progress bars, "continue" buttons);
- cope with the claim changing after a reload (the student completed the previous level meanwhile).

This is front work only; the plugin cannot enforce what the app draws.

### 3. What the backend must enforce

The front is not a control. The service must itself, for a session carrying `level_locked`:

- accept check-in attempts and the welcome-flow steps, and **refuse** everything else (starting the SCORM-linked
  content, the final exam);
- keep the **final-exam gate independent** (`docs/FINAL-EXAM-GATE.md`): it is decided from Moodle completion read by the
  service, not from the claim. A locked student who passes the check-in must not become able to sit the final exam
  because of it; that gate is unchanged.

If the backend does not enforce it, the mode is only cosmetic.

### 4. The student passes the diagnostic

The service sends the existing result statement (`docs/RESULT-PASSBACK.md`), `purpose: check_in`, `passed: true`. The
passback **already grants completion on the first pass from either purpose** (rule 5 of the passback), strictly above the
threshold, so the level completes by the **existing policy**, with no new rule. On the next reload `level_gate` finds
this level complete and the **next** level unlocks, because the chain reads Moodle completion.

Consequences the owner should accept on purpose, because this mode creates them:

- **The diagnostic becomes a way to skip a level's content.** Passing the check-in completes the level without the
  SCORM activities. That is what the proposed text says, but it also means a level can be completed in minutes. If that
  is not wanted, the pass rule for a locked student needs a different policy, which is a change to the pass rule and
  therefore out of scope here.
- **The previous level is still not complete.** Completing B2 by diagnostic does not complete A1. Whether the chain
  should then treat the previous levels as met is a separate product decision; today it does not.

### 5. The student fails the diagnostic

The passback records the result (`recorded`), the level stays incomplete and locked, and the student can retry only as
far as the check-in's own attempt rules allow (the service's, not the plugin's). The message on the page stays the
locked message. Nothing completes, and nothing is granted by the plugin.

### 6. The limit that cannot be closed from the plugin

SCORM activities and every other activity of the level remain reachable **by direct URL**
(`/mod/<name>/view.php?id=…`): the gate only covers the Rumbos launch (section 4 of
`docs/LEVEL-PREREQUISITES.md`). Locking the bubbles in the app does not lock the activities in Moodle. A student who
knows or finds the URL can open them. Closing that needs Moodle's own restrictions (availability by completion of
another course is not offered natively, see section 5 of that document) or a different mechanism, and is not part of
this mode.

## Cost estimate

Estimates by repository, in working days for one developer who knows that repository, **not measured**, and the front
and backend figures are guesses that the owners must replace.

| Repository | Work | Estimate |
| --- | --- | --- |
| Plugin | The setting, the claim for a locked student only, the page rendering and a changed message, tests (including the fail-closed and byte-identical-claims tests), documentation and the manual matrix. | 2 to 3 days |
| Front (app) | Read the claim, a limited-flow state, locked map and bubbles, the reload and unlock behaviour, tests on desktop and small phone. | 4 to 7 days (guess) |
| Backend | Accept and record the claim, refuse everything outside the welcome flow and check-in for a locked session, keep the final-exam gate independent, tests. | 3 to 5 days (guess) |
| Coordination | The deploy order (service, then app, then plugin), a staging run with a real locked student, and the decision on skipping a level (section 4). | 1 to 2 days |

## Decisions the owner must make before any of it is started

1. Is a level that can be completed by the diagnostic alone acceptable (section 4)?
2. Should completing a level by diagnostic mark the earlier levels as met?
3. Is closing the direct-URL gap (section 6) required, or accepted?
4. Who owns the front and backend work, and in which order is it deployed?

Until these are answered and the work exists, the locked message must stay Variant 1: *"Debes completar el nivel
anterior para acceder a este nivel."*
