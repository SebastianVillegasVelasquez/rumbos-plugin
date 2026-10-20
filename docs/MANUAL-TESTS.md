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
| Quiz (a finished attempt) | yes | Attempt started, answered, **Finish attempt**, **Submit all and finish** with the confirmation dialog: review page shown | complete after closing | The refresh while the popup was open did not catch it (5 s throttle); the refresh on close did |
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
