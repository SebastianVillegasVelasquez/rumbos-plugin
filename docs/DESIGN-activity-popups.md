# Design: activity popups

Status: implemented in this branch. Decision: **option A, our own viewer.**

## The requirement

An activity chosen on the map must open as a popup over the map, not in a new tab and not
by leaving the page. The user must be able to open it, use it and submit it (assignments,
quizzes, SCORM, H5P, files, URLs...) with everything running in Moodle's core, then return
to the map with the progress updated.

## The spike

Both options were run on a Moodle 5.2.4 development site (Docker, PostgreSQL), with a
Rumbos course holding one activity of each type and a student account.

### Option B: `format_popups` as a dependency

`dthies/moodle-format_popups` v1.1 (2024) was installed and its code read (it is GPL; no
code was reused).

- **How it works.** It does not use a frame. It listens for clicks on anchors inside the
  course page's own DOM (`div.course-content`, the course index), and loads the activity
  through Moodle's Fragment API, with one PHP renderer per module
  (`classes/local/mod_<name>.php`) and extra JavaScript for SCORM, H5P, books and forms.
- **What it covers.** It only handles modules that have a renderer: assign, book, choice,
  contentdesigner, customcert, etherpadlite, folder, h5pactivity, lti, page, plenum,
  poster, resource, scorm, url, videotime and wiki.
- **Measured on the test course** (`format_popups_mods_available()` as the student):
  covered `assign, page, url, choice, book, scorm, h5pactivity, resource`; **not covered
  `quiz`, `forum`, `lesson`.** The requirement names quizzes, lessons and forums.
- **Fit with our architecture.** Our links live in another document (the Rumbos iframe),
  so there are no anchors for it to bind to. Using it would mean calling its internal
  Fragment loading by hand from our host, copying its AMD call, and depending on a plugin
  whose module list we do not control. Its capability `format/popups:view` would also
  become a second permission to manage. Navigation links would follow course order, not
  map order.

B was uninstalled from the site after the test.

### Option A: our own viewer (chosen)

The host JavaScript, running in the Moodle page (the parent of the Rumbos iframe), opens a
modal dialog with an iframe of the same-origin activity URL.

Why in the host page and not inside the Rumbos iframe: a Moodle frame nested below a
cross-site ancestor (the app) risks third-party-cookie treatment and a login screen. In
the host page the activity frame has the same origin as its parent, so it keeps its
session. This is reasoning from how browsers partition cookies; it was not reproduced in
Safari or Firefox, only that the session works in the host-page design in Chrome.

Everything in the matrix (see [MANUAL-TESTS.md](MANUAL-TESTS.md)) works in A in Chrome, so
A was chosen: it covers every module with no per-module code, including those B cannot.

## Decisions inside option A

### What opens where

`resolveActivityTarget()` (`amd/src/protocol.js`, unit tested in Node) decides from the
host's own last state, never from the app:

| URL | Result |
| --- | --- |
| Same origin as the page, under `{wwwroot}/mod/`, module not in the new-tab list | popup |
| Another Moodle URL, or a module in the new-tab list | new tab (`noopener`) |
| Not a Moodle URL, restricted activity, unknown cmid | nothing |

If a browser blocks the new tab (no user activation, as in Safari), the activity opens in
the current tab instead of not opening.

The new-tab list is the admin setting `newtabmodules`, default `bigbluebuttonbn,lti`.
These defaults come from how those modules work, **not from a test**: a video conference
and an external tool run on another site that usually sets `X-Frame-Options` or
`frame-ancestors`, which a frame cannot get around. There was no BigBlueButton server or
LTI tool on the test site.

### Layout inside the popup

The activity page should not show the site chrome. Options considered:

1. A body class set by a script that runs only when `window.name === "rumbos-popup"`, with
   CSS scoped to that class. **Chosen.** Least invasive: nothing changes outside the
   popup frame, and Moodle's own iframes (H5P, the SCORM player, editors) are separate
   documents that never get the class.
2. A theme layout (`?embed=1`-style). Rejected: it needs a URL parameter on every link
   the activity follows, and it changes the page for anyone who opens the URL.
3. CSS injected from the host into the frame's document. Rejected: it races the page
   load and reaches into the framed document.

The script is loaded by a page hook (`before_standard_head_html_generation`) only for
requests the browser marks as same-origin iframe requests (`Sec-Fetch-Dest: iframe`,
`Sec-Fetch-Site: same-origin`; page scripts cannot forge these) on a page of a Rumbos
course. The trade-off: a browser or proxy that does not send those headers shows the
full Moodle page inside the popup. It still works. The script's own `window.name` check
keeps it from doing anything if something else frames the page.

The CSS hides `nav.navbar.fixed-top`, the drawers, the drawer toggles, the secondary
navigation, the breadcrumb, the activity navigation and the footer, and zeroes the
`#page` margins. It uses selector specificity rather than `!important` (the stylelint
config forbids it). `.drawer-toggles` stays `display: flex` because Boost's `d-flex`
utility is `!important`; it is empty once the drawers are hidden.

### Leaving through "Continue" / "Exit activity"

Many activities end by loading the course page. Two guards:

- `format\output\courseformat\content` does not render the Rumbos container for a popup
  request, so the map is never nested in the popup.
- The popup page script, when the page is the course view, tells the host
  (`rumbos:popup-leave`) and the host closes the popup. As a fallback for browsers
  without `Sec-Fetch-*`, the host also checks the frame's address after every load.

Verified with a SCORM package: **Exit activity** closes the popup, the state is refreshed
and the activity shows as complete on the map.

### Closing and refreshing

On close (button, Escape, or the course-page redirect) the host closes the dialog, calls
`format_rumbos_get_course_state`, sends `host-state`, and then sends
`host-overlay { open: false }`. If the call fails it still sends `open: false`, so the
app is never left believing an overlay is open. If another popup opened meanwhile, the
older close does not send `open: false`.

While the popup is open the host also refreshes after **every** page the activity frame loads
(its `load` event, the first one included, because viewing can complete an activity), debounced
by 400 ms and at least 2 seconds after the previous scheduled read, and sends `host-state` when
the state changed. An earlier version only counted the second page and dropped anything inside
a 5 second window, so the review page that follows a quiz submission was never read. Reads never
overlap: one in flight is joined, and a navigation during it causes one follow-up read after it.
The read on close always starts after the close and always sends `host-state`. A failed read keeps
the last state. The policy for the whole host is in [HOST-CONTRACT.md](HOST-CONTRACT.md).

### The popup frame has no `sandbox`

The contract text suggests only the permissions needed. A same-origin Moodle page needs
`allow-scripts` and `allow-same-origin`, and a sandbox with both gives the page no
isolation, because it can remove its own sandbox. Dropping it loses no protection. And
it is not only neutral: **Chrome shows a broken-page icon instead of a PDF in any
sandboxed frame.** This was observed on a File activity with `allow-scripts
allow-same-origin allow-forms allow-modals allow-downloads`; removing the attribute made
the PDF viewer work. Files are very often PDFs. See
[SECURITY.md](../SECURITY.md#the-activity-popup). The Rumbos app frame, which is
cross-origin, keeps its sandbox.

### Links to other websites

An activity of type URL shows "Click on URL to open the resource". Inside the popup that
would load the external site in the frame, and most sites refuse that, leaving a blank
frame with no way back except the popup's own button. The popup page script therefore sets
`target="_blank" rel="noopener"` on links to another origin.

### Accessibility and small screens

- `role="dialog"`, `aria-modal="true"`, labelled by the activity title; the iframe has a
  title ("Activity: ...").
- The visible **Back to the map** button is first in the tab order and receives the focus
  on open. Invisible tab stops at both ends send the focus back into the dialog, and a
  `focusin` listener catches anything else.
- The rest of the page gets the `inert` attribute while the dialog is open, so it can
  neither be focused nor read by a screen reader. It is removed on close.
- Escape closes the dialog from the host page; from inside the activity the page script
  forwards it unless the activity handled it or a Moodle modal is open.
- On close the focus returns to the Rumbos iframe.
- Below 768px the dialog fills the screen (`inset: 0`). Its height follows
  `visualViewport` (`--format-rumbos-popup-height`), so the on-screen keyboard does not
  cover the lower part. The body does not scroll behind it (`overflow: hidden`,
  `overscroll-behavior: contain` on the dialog). The popup's frame scrolls on its own.
- iOS Safari: not tested. Known risks to check on a device: older iOS ignores
  `overflow: hidden` on the body, `100vh` includes the toolbar (the `visualViewport`
  height is used instead), and an iframe scrolls internally only when its height is
  constrained, which it is here.

### Not in this phase

Previous/next by map order (needs a contract change) and anything involving the Deft
response block.

## Contract v5.3

```
host -> app: { type: "rumbos:host-overlay", v: 1, open: boolean }
```

`host-init.mode` is `"editor"` only when the user holds `format/rumbos:editmap` **and**
the page is in Moodle's editing mode; the assertion's `role` claim is unchanged and still
capability based. Moodle's editing toggle reloads the page, so the host starts from
scratch each time; a page restored from the back/forward cache is reloaded so it cannot
keep an old mode.
