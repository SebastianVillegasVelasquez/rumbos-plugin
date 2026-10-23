# Host contract with the Rumbos app

What the Moodle page (the host) and the Rumbos app, which runs in an iframe, say to each
other, and what each side may rely on. The app side is implemented in the Rumbos frontend
against this document. This is **contract v5.5**: everything from v5 to v5.4 is unchanged
and v5.5 only adds to it.

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
| app to host | `rumbos:error` | `code`, `detail` | The app failed; the host falls back to the standard course content. |
| app to host | `rumbos:no-content` | `reason` | **v5.5.** The app has nothing to show this user; the host falls back to the standard course content. |
| app to host | `rumbos:preferred-size` | `aspectRatio` | **v5.5.** The proportions of the level on display; the host applies them only in automatic height mode. |
| host to app | `rumbos:host-init` | `mode`, `locale`, `courseId`, `assertion`, `state` | The answer to `embed-ready`. |
| host to app | `rumbos:host-state` | `state` | A newer course state. |
| host to app | `rumbos:host-launch` | `assertion` | A fresh launch assertion. |
| host to app | `rumbos:host-fullscreen` | `on` | The full screen mode **as it is now**. |
| host to app | `rumbos:host-overlay` | `open` | An overlay (the activity popup) is above the map or has gone. |

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
- `amd/src/refresher.js` is the single-flight, throttled reader (`tests/js/refresher.test.mjs`).
- `amd/src/host.js` wires them to the page.
- `classes/local/map_size.php` resolves the height settings; `styles.css` turns them into the layout.
- `classes/local/course_state.php` computes the state; `classes/external/get_course_state.php`
  and `get_activity_settings_url.php` are the two AJAX functions the host calls for this.
