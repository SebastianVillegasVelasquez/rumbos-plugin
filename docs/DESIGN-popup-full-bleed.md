# Activity popup at 100%: only the activity, no site chrome

The activity page inside the popup showed the site's chrome: a government bar, the
"Academia Virtual" header, an accessibility widget and a large blank area. This page records
what was established about where it comes from, what the plugin does about it, and what has
not been verified.

## What is known about the site

The Mincit site uses **Boost Union**. The header, footer and accessibility widget are
**Additional HTML** (the `standard_head_html`, `standard_top_of_body_html` and
`standard_end_of_body_html` outputs) plus the theme's initial SCSS. They are not part of a
layout file.

## Findings from Moodle 5.2 core (read, and exercised in PHPUnit)

| Question | Finding |
| --- | --- |
| Can `set_pagelayout('embedded')` take effect from `before_http_headers`? | **Yes.** `core_renderer::header()` dispatches `before_http_headers` and only afterwards asks the theme for the layout file (`$this->page->theme->layout_file($this->page->pagelayout)`), so a layout set by the activity script earlier is replaced. `set_pagelayout()` has no "already initialised" restriction (the check is commented out in core). `$SESSION->forcepagelayout` would override it, but nothing sets that for normal users. |
| Does the `embedded` layout remove Additional HTML? | **No.** Boost's `embedded.mustache` still prints `output.standard_head_html`, `output.standard_top_of_body_html` and `output.standard_end_of_body_html`. Boost Union inherits that. The layout removes the navbar, drawers, breadcrumb and footer, but not what the site adds through Additional HTML. |
| Can a hook remove that HTML? | **No.** `before_standard_head_html_generation`, `before_standard_top_of_body_html_generation` and `before_footer_html_generation` can only add output. Removing it means hiding it with CSS. |
| What causes the blank area? | Not determined without the site's SCSS. The usual causes are a body or `#page` offset reserved for a fixed header (Boost's `#page` margin and the theme's own `padding-top`), and the Additional HTML header taking height. The plugin zeroes the standard offsets; anything the theme adds on top is handled with the selectors setting or by scoping the theme SCSS (see below). |

## Techniques

| Technique | Chrome gone? | Blank area gone? | Risk |
| --- | --- | --- | --- |
| T1: server-side `embedded` layout (`before_http_headers`) | Navbar, drawers, breadcrumb, footer, activity navigation: yes. Additional HTML: **no** | Partly (standard offsets) | A few activities depend on their own layout (quiz attempt navigation lives in a fake block; the embedded layout shows fake blocks). Layouts `embedded`, `popup`, `secure`, `maintenance`, `redirect`, `print` and `login` are left as they are. |
| T2: scoped CSS from a configurable selector list (head hook + body class) | Whatever the selectors name, including Additional HTML | Whatever the selectors and the plugin's offset rules cover | Depends on the site's markup; selectors are configuration, not code. Cannot affect any page without the popup body class. |
| T3: `format_popups` as a dependency | Not tested | Not tested | Does not cover quizzes, forums or lessons; binds to links in the course page DOM, which our map (in another frame) does not have. Not pursued. |

**Recommendation: T1 and T2 together (the default `auto` mode).** T1 gets rid of everything the
layout prints, which is most of Moodle's own chrome and the part that varies between activity
types. T2 removes what only the site knows about. T1 alone cannot fix this site, and T2 alone
leaves each activity's own layout (drawers, sticky bars) in place. The mode `css` keeps T2 only,
for a site where the embedded layout breaks an activity.

## What the plugin does

1. **A marker in the session.** Right before it loads the popup frame, the host calls
   `format_rumbos_start_popup_session(cmid)`. The function checks that the user can see the
   activity, that its course uses the Rumbos format, and stores
   `{courseid, expires}` in the Moodle session (5 minutes, extended by every request that
   matches). The host calls `format_rumbos_end_popup_session()` when the popup closes. If the
   call fails the popup still opens, only with the chrome.
2. **Gating** (`popup::decide()`). The layout and the styles apply only when all of this
   holds: the mode is not `off`, the marker is valid, it is bound to the course of the page,
   and the request carries `Sec-Fetch-Dest: iframe` and `Sec-Fetch-Site: same-origin`. If the
   browser sends neither `Sec-Fetch-*` header, only the styles are sent (the page script adds
   the body class after checking `window.name === "rumbos-popup"`, and the styles are scoped to
   that class); no layout change is made. A request that carries the headers but is not a
   same-origin iframe (another tab) is never touched.
3. **Layout** (`before_http_headers`): body class `format-rumbos-popup-page` and, in `auto`
   mode, `embedded` layout.
4. **Styles** (`before_standard_head_html_generation`): one rule per configured selector,
   `body.format-rumbos-popup-page <selector>{display:none!important}`.
5. **Overlay.** The iframe fills 100% of the overlay with no padding; the overlay's bar is
   slim (title and **Back to the map**); on small screens it is a full-screen sheet. With the
   position `below-site-header` the overlay starts at the bottom edge of the site header
   (selector from the settings), measured when the popup opens.

## Settings

| Setting | Values | Notes |
| --- | --- | --- |
| Popup mode | `auto` (default), `css`, `off` | `off` leaves activity pages and the marker alone. |
| CSS selectors to hide inside the popup | One per line, at most 30, 200 characters each | Simple selectors only: no `{ } @ ; , \ /`, no `url(`, no `<`, brackets and quotes balanced. Each is its own rule, so one that does not match, or is invalid, does not void the others. Nothing is hard-coded for any site. |
| Popup position | `fullscreen` (default), `below-site-header` | A configuration choice, not a code change. |
| Site header selector for the popup | One selector | Used only with `below-site-header`; if nothing matches, the overlay covers the window. |

## Finding the selectors on the Mincit site

Open an activity in the popup, then in the browser's developer tools select the frame
`rumbos-popup` and inspect the government bar, the "Academia Virtual" header, the
accessibility widget and the footer. Put the **id or class of each outermost element** in the
selectors setting, one per line (for example `#govco-bar`, `.academia-header`, `#a11y-widget`;
these names are examples, not the site's). Where the markup has no usable id or class, edit the
Additional HTML to add one.

Because the popup frame's `<body>` has the class `format-rumbos-popup-page`, the theme's own SCSS
can also be scoped, which is the cleanest fix for the blank area:

```scss
body:not(.format-rumbos-popup-page) .some-header-offset { padding-top: 5rem; }
```

## SCORM, H5P and the other activity types

Recommended SCORM settings for the popup (the package's own page decides the rest):

- **Display package: Current window.** A new window defeats the popup; "Display package in
  popup window" is not meant to be used with this format.
- **Skip content structure page: Always** (for a package with a single SCO), so the player
  starts at once.
- **Student skip content structure page / Display attempt status / Show navigation**: leave the
  navigation off for a Rise or Storyline package, which brings its own.
- A package's own **Salir de la actividad** button navigates the frame to the course page or
  closes the window. Navigating to the course page closes the popup (the host already does this
  on `isCourseViewUrl`); `window.close()` in a frame does nothing, so a package that only calls
  it leaves the user on its last screen with **Back to the map** still working.

## Verification status

Done, in the dev site (Moodle 5.2.4, PostgreSQL, PHP 8.3):

- Core behavior in the table above, by reading `lib/classes/output/core_renderer.php`,
  `lib/pagelib.php` and `theme/boost/templates/embedded.mustache`.
- PHPUnit: the gating truth table, that ordinary pages are never touched (no marker, expired,
  other course, other tab, no headers, other format, mode off), the session marker and its
  external functions, selector validation, and the hook output.
- Behat: the popup page has the popup body class (the test site is plain http, so browsers send no `Sec-Fetch-*` and only the styles path runs; the `embedded` layout is covered by PHPUnit).
- ESLint, the AMD build, stylelint, gherkin-lint, phpcs (`moodle`, `moodle-extra`).

**Not verified** (needs the Mincit site or a copy of its theme and Additional HTML):

- That the Boost Union `embedded` layout and the Additional HTML selectors remove the specific
  header, footer and widget, and the cause of the blank area.
- The activity matrix (SCORM Rise/Storyline, H5P, page, file, URL, quiz attempt, assignment
  upload, certificate, forum, book) with the layout and selectors on. See
  [MANUAL-TESTS.md](MANUAL-TESTS.md) for the checklist to run on staging.
- Browsers that omit `Sec-Fetch-*` (only the styles path), Safari and Firefox.
- Whether the government bar must stay visible: that is the `below-site-header` position.
