# Manual tests

Things that automated tests cannot cover, with what was actually run and what was not.

## How the activity popup was tested

Moodle 5.2.4 development site (`dev/setup.sh`), Chrome on Windows 11, student account in a
Rumbos course with one activity of each type and automatic completion rules.

There is no Rumbos app on the test site, so the app was simulated in the browser: the
iframe's `contentWindow` was replaced by a hidden same-origin window, `rumbos:embed-ready`
and `rumbos:open-activity` events were dispatched to the host with the app's origin, and
everything the host sent to the app was recorded. The popup, the framed Moodle pages, the
clicks inside them and the completion data are real.

## Activity matrix

"Opens" means the activity loaded in the popup with the Moodle chrome hidden. "Closed"
means closing with the button sent `host-state` and then `host-overlay: false`, removed
the inert attribute from the page and put the focus back on the Rumbos iframe (this held
for every activity). Completion rules are in brackets.

| Activity | Opens | Interact / submit | Completion on the map | Notes |
| --- | --- | --- | --- | --- |
| Assignment, file upload (on submit) | yes | File added in the file manager, **Save changes** clicked: "Submitted for grading" | complete, also refreshed while the popup was open | The file was added by simulating a drop on the file manager's drop zone, not through the native file picker |
| Quiz (a finished attempt) | yes | Attempt started, answered, **Finish attempt**, **Submit all and finish** with the confirmation dialog: review page shown | complete after closing | At version 0.2.0 the refresh while the popup was open did not catch it (5 s throttle); the refresh on close did. Fixed since: see [Completion flow](#completion-flow) |
| SCORM (view) | yes | Package played in the player; **Exit activity** | complete | **Exit activity** loads the course page: the popup closed by itself and the map was not rendered inside it. SCORM display "Current window" (the default) was tested; "New window" was not |
| H5P activity (view) | yes | Opened and rendered | complete | Playing the content and the xAPI completion were not exercised |
| Custom certificate | **not tested** | | | `mod_customcert` is third party and was not installed |
| Page (view) | yes | Read | complete | |
| File, PDF (view) | yes | PDF viewer shown | complete | **Broken-page icon while the frame had a `sandbox` attribute**; works without it. This is why the popup frame has no sandbox |
| URL (view) | yes | Shows "Click on URL to open the resource" | complete | A link to another site would have loaded inside the frame. Now it opens in a new tab (checked on the link's `target`/`rel`; the opened tab itself was not inspected) |
| Forum (a post) | yes | Discussion added through the inline form with TinyMCE | complete | |
| Book (view) | yes | Opened | complete | Chapter navigation was not exercised |
| Lesson (reach the end) | yes | **Continue** twice to "Congratulations - end of lesson reached" | complete | |
| Choice (submit) | yes | Option selected, **Save my choice** | complete | |

Nothing in the matrix broke inside the frame on desktop Chrome.

## Other checks

| Check | Result |
| --- | --- |
| Open, then close with the **Back to the map** button | host-overlay true, then host-state and host-overlay false, in that order |
| Close with Escape pressed on the host page | same sequence |
| An activity's navigation to the course page | closes the popup, same sequence, and only one Rumbos container remains on the page |
| Background `inert` while open | page children are inert while open and restored on close |
| Chrome hidden in the popup | top bar, drawers, secondary navigation and footer are `display: none`; `#page` margins are 0; **Moodle's footer and pages outside the popup are unchanged** (the class exists only in the popup frame) |
| Mobile layout | The window could not be resized by the test tool, so a 390 px wide iframe on a Moodle page was used with the real popup module: the dialog filled the 390 x 640 frame, the title was truncated and the activity chrome was hidden. The **Back to the map** button wrapped onto three lines at first; fixed with `white-space: nowrap` |
| Editing mode (teacher account) | Editing off: the note "Turn editing on to modify the map." appears outside the iframe, `host-init` carried `mode: "student"` and the activity list stayed collapsed. Editing on (`edit=on`): `mode: "editor"`, no note, the core editor expanded below the map. Also covered by PHPUnit (`tests/local/access_test.php`) |

## Defaults for "open in a new tab"

`bigbluebuttonbn` and `lti` are listed by default. They were **not tested**: there was no
BigBlueButton server and no LTI tool on the test site. The default follows from how they
work (they run on another site that normally forbids framing). Adjust the list after
testing the actual tools in production.

## Not verified

- Real phones (iOS Safari, Android Chrome): on-screen keyboard behaviour, rotation, iOS
  scrolling and the `visualViewport` sizing were not tested.
- Firefox, Safari and Edge on desktop. The third-party-cookie reasoning in
  [DESIGN-activity-popups.md](DESIGN-activity-popups.md) was not reproduced.
- The tab focus trap (the invisible stops at both ends) and screen reader announcements.
- A new tab opened from the popup path: popup blockers decide this. Safari is the likeliest
  to block; the fallback then opens the activity in the current tab.
- A real Rumbos app sending `open-activity` after a click in its iframe (user activation).
- Custom certificate, Moodle's other activity types (workshop, glossary, database,
  feedback, wiki, survey) and the customized `format_popups` copy.
- A proxy or WAF in front of Moodle that strips `Sec-Fetch-*` headers: the popup would then
  show Moodle's full page chrome.
- Behat cannot check the hidden Moodle chrome: its site is `http://webserver`, which Chrome
  does not treat as trustworthy, so it sends no `Sec-Fetch-*` headers and the page hook
  correctly does nothing. The hook has PHPUnit tests and the layout was checked by hand
  above. The same run exercised the header-less path for real: the popup then shows
  Moodle's full page, and leaving through the course page still closes it (about 2 seconds
  on the development site, because the course page loads fully before the host can see
  it). Moodle served over plain HTTP on a host other than `localhost` behaves this way too.

## Completion flow

Moodle 5.2.4 development site, Chrome on Windows 11, a student in a throwaway Rumbos course with
five quizzes that have one numerical question and a grade to pass of 80 out of 100. The Rumbos
app was simulated as described above. "State" is what `format_rumbos_get_course_state` returned
for the student, read in a **separate PHP process** from the one that made the attempt (as the
AJAX call is), next to what Moodle's own `cm_completion_details` says for the same user.

| Completion condition of the quiz | Student does | State returned | Moodle's own overall state |
| --- | --- | --- | --- |
| None | Views, finishes an attempt | `none` | no completion information |
| View | Opens the quiz | `complete` | complete |
| Receive a grade | Finishes an attempt with a wrong answer (grade 0) | `failed` | failed: the "receive a grade" rule is reported as failed because the grade is below the grade to pass |
| Receive a passing grade | Wrong answer, then a right one in a new attempt | `failed`, then `complete` | the same |
| Receive a passing grade or complete all attempts (2 attempts) | Wrong answer; a second wrong answer | `incomplete`, then `failed` | the same: the overall state is failed although the "all attempts used" rule is met |

None of these conditions waits for a scheduled task: the quiz handles the submitted attempt in the
request that submits it. The exception is an attempt abandoned past its time limit, which the
scheduled task `mod_quiz\task\update_overdue_attempts` closes later; completion then changes with
nobody navigating anywhere.

The same quizzes were then taken **inside the activity popup**, the way a student would, with the
host's messages recorded with their timing:

- *Receive a passing grade*, a right answer: attempt page, summary page, confirmation dialog,
  review page. The popup was still open when `host-state` arrived with `complete`, about a second
  after the review page loaded (the 400 ms debounce plus the request). Earlier pages caused no
  message because nothing had changed. Closing the popup sent `host-state` and then
  `host-overlay { open: false }`.
- *View*: `host-state` with `complete` arrived about a second after the popup opened, before
  anything was clicked.

Also covered by PHPUnit (`tests/local/course_state_test.php`): view, grade, pass and fail are reflected
by the next state in the same test; a cache entry that another server left behind is not served; the
flag follows the course and the site setting.

### To repeat by hand

1. Create five quizzes, one for each condition above, with a grade to pass.
2. As a student, open each from the map (or from the course page), finish attempts as in the table
   and check the activity's colour on the map after each step, with the popup open and after closing it.
3. As a teacher with editing on, ask for the settings of an activity that never completes: a new tab
   opens on its settings with only "Completion conditions" expanded.

### Polled modules

`tests/behat/rumbos_activity_popup.feature` changes a page's completion in the background while its
popup is open: with the module in `pollmodules` the app is sent the new state within about 15 seconds,
with the list empty it is sent nothing until the popup closes. A real SCORM package or H5P content
reporting from inside its frame was **not** played; the poll is only known to react to a change in
Moodle's data. The cost of a poll is in [COMPLETION-STATES.md](COMPLETION-STATES.md#what-a-read-costs).

## Full screen

What was run, on Moodle 5.2.4, Chrome on Windows 11, desktop viewport 1536 x 735, with a stand-in
for the app (an iframe whose window records the host's messages; the app's own button was a 44 px
button 8 px from the top-right corner):

| Check | Result |
| --- | --- |
| Exit control geometry while full screen | Exactly the top-right 64 x 64 CSS px of the viewport (left 1472, top 0, right 1536, bottom 64) |
| What is at the corner | `elementFromPoint` at three points of the square returned the exit control (the app's button was hidden under it) |
| Stacking | The wrapper is `position: fixed; z-index: 1060`, above Moodle's top bar (1030), drawers (1015 and 1021), the "back to top" button (1000) and toasts (1051). Bootstrap popovers and tooltips (1070 and 1080) can still be above it, and they are transient |
| Exit by click | Left full screen, `host-fullscreen { on: false }` sent, page scroll position (120 px) restored, the control hidden again |
| Exit by Escape on the page | Same |
| A request to leave (`request-fullscreen { on: false }`) | Left, and answered with `host-fullscreen { on: false }` |
| The browser refusing its own full screen | The request after a real click did not enter the browser's full screen in this automated Chrome session; the overlay stayed and the app was told `on: true`, which is the designed behaviour for a refusal |
| Exit control label | `aria-label` and `title` "Exit full screen" |

Also covered by Behat (`tests/behat/rumbos_fullscreen.feature`): the control's size and corner and that
it is above the app, exit by click and by the app's request, and the messages sent. Escape is not in Behat: the key goes to the focused app frame there, as it does for a user (see the README).

The browser's own full screen was exercised in Behat (`tests/behat/rumbos_fullscreen_native.feature`,
Chrome 155 on Linux driven by Selenium) with a real click through the driver, which browsers require:
the full screen goes to the map's wrapper, opening an activity keeps it with the popup inside it, and
closing the popup leaves the map in full screen with the exit control in the corner. The request made
from a script with no click, as in the Chrome session above, is refused and leaves the overlay.

### Not verified

- **Leaving the browser's full screen with Escape** (the `fullscreenchange` path), with or without a
  popup open, was not exercised, nor was the browser's full screen in Chrome on Windows, Firefox,
  Safari or Edge. With a popup open Escape leaves the full screen and the popup stays; a second
  Escape closes it. The code is guarded so that every failure leaves the overlay.
- **Mobile.** iPhone Safari (no Fullscreen API for elements; the dynamic viewport units and the
  address bar), iPad Safari, Android Chrome (rotation, the on-screen keyboard) and the notch: not run.
  To check on a phone: ask for full screen, confirm the cross sits in the top-right corner, is large
  enough to tap and is not covered by the browser's bars, exit, and confirm the page scroll position
  is where it was; rotate while in full screen.
- **Keyboard and screen readers.** Tab reaching the exit control from the app frame, and the dialog
  announcement, were not tried.
- **Firefox, Safari and Edge on desktop.**

## Students, empty maps and size (release 0.3.0)

Moodle 5.2.4 (moodle-docker, PostgreSQL), real students enrolled by manual enrolment, requests made
from clean cookie jars with curl, and one student session in Chrome on Windows 11 (viewport
1536 x 735). The app at `https://rumbos.invalid` does not exist, so the iframe never loads and the
"saved" and "never saved" maps could not be observed; the app's part was played by the stand-in of the
Behat steps.

### Why students seemed to see nothing

| Scenario | Container | `iframe` | State function (AJAX) | Notes |
| --- | --- | --- | --- | --- |
| S1 course with a visible activity, real student | yes | yes | ok | the page itself is correct |
| S2 map never saved | not observable | not observable | ok | needs the real app; see below |
| S3 section 0 empty, no activities, real student | yes | yes | ok | the container does not depend on the sections |
| S4 every activity hidden, real student | yes | yes | ok | same |
| S5 app unreachable | yes, then replaced | removed after the timeout | ok | standard content shown; teachers get the notice |
| S6 manager **not enrolled**, switched to Student | **no** (before the fix) | **no** | **refused, "Edit the Rumbos map"** | `access::can_use()` was false |
| S6 manager enrolled, switched to Student | yes | yes | ok | enrolment kept it working |

Findings: the container is rendered by the format's own template above the standard content, whatever
section 0 holds, whether there are activities, and whether editing is on; "not inside the General block"
is not a cause. Nothing in `format.php` or the templates reads the sections or the editing mode. The
likely causes are two: a manager testing with "Switch role" (fixed), and a course whose map was never
saved, where the app has nothing to draw and the host had no way to know (fixed by `rumbos:no-content`,
which needs the app to send it). The second could not be reproduced without the real app.

### Size

| Check | Result |
| --- | --- |
| Reserved room before the app loads (auto, student, 1536 px window) | 441 px tall for a 784 px wide area (16 / 9), present before any script |
| Boost course page width | `limitedwidth` is added by `course/view.php`; main area 830 px. With the plugin rule the area went from 784 to 1126 px wide |
| Behat (`rumbos_size.feature`, Chrome on Linux) | fixed, percentage, site default, course over site, site maximum and minimum, small screen percentage, course form saving and refusing, automatic mode following the app's ratio, ignored in other modes, wider than 830 px |
| Backup and restore (PHPUnit) | a duplicate keeps the three options and is independent of its source |

### Not verified

- The real Rumbos app: nothing here shows what it sends for an unsaved or empty map.
- Firefox, Safari and Edge; real phones; the height transition by eye; a theme other than Boost
  (Classic, a child theme, a custom SCSS that changes the course page width).
- Moodle 5.3.

