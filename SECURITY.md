# Security

This page describes what the Rumbos course format protects, what it trusts, and what
an attacker can and cannot do. It covers the Moodle plugin only. The Rumbos service
has its own threat model.

## Reporting a vulnerability

Report suspected vulnerabilities privately, not in a public issue. Use GitHub's
private vulnerability reporting on this repository if it is enabled, or contact the
maintainers at OBJ Digital directly. Include the plugin release, the Moodle version
and the steps to reproduce.

Only the latest release receives fixes. The plugin is in alpha.

## What the plugin does with trust

The plugin does one security-relevant thing: it signs short-lived **launch
assertions** that tell the Rumbos service who is asking. Everything else is relaying.

### What an assertion proves

An assertion is a JWS signed with RS256 by the Moodle site's private key. Whoever
verifies it with the site's registered public key learns that, at the moment of
issue, this Moodle site vouched that:

- a user, identified only by a pseudonymous id, is signed in to the site;
- that user is an active participant of the given course, or may edit its map;
- that user has the stated role: `editor` if and only if they hold
  `format/rumbos:editmap` in that course.

It expires 60 seconds after issue and carries a random 128-bit `jti`.

### What it does not prove

- **Identity.** The id is a pseudonym. It says nothing about who the person is.
- **Anything after expiry.** If the user is unenrolled or loses the capability a
  second later, an assertion issued a second earlier is still valid until it expires.
- **That it is used once.** The plugin cannot stop a copied assertion from being
  presented twice. Detecting replay is the Rumbos service's job, using `jti`.

The service must also pin RS256, accept only keys registered for the issuer, and check
`aud`, `iat` and `exp`. A service that skips any of these weakens the scheme
regardless of the plugin.

## Where the assertion goes

It is returned to the signed-in user's browser by an AJAX call that needs a login and
a session key, and it takes no user id: a user can only ask for their own. The host
page then sends it to the app with `postMessage` and an explicit target origin. It is
never placed in a URL, stored in Moodle, logged by the plugin or cached in a rendered
page.

It is not verified that every logging store of a given Moodle site leaves AJAX
responses out; check your own logging configuration if that matters to you.

### The administrator's diagnostic

The settings page has a **View sample assertion (unsigned)** action for diagnosing a
registration. It is restricted to users with `moodle/site:config`: the external function
checks the capability itself, and the panel renders nothing for anyone else.

It signs a fresh assertion for the administrator who asks, decodes it **on the server**
and returns only the header and the claims: `alg`, `typ`, `kid`, `iss`, `aud`, `sub`,
`course`, `role`, `iat`, `exp` and `jti`. The result is built field by field from that
fixed list, so it cannot carry anything else. It never returns the compact token or the
signature, and the token is discarded, so nothing it returns can be replayed.

What it does expose is already what the service sees, plus the administrator's own
pseudonymous id: nothing secret. It exposes no key, no secret, and no data about any
other user. The page shows it with `textContent`, not as HTML, and the plugin does not
log it.

## The iframe

The app runs in an iframe with `sandbox="allow-scripts allow-same-origin allow-forms allow-downloads"`,
no referrer, and `allow="fullscreen"`.

**What the app cannot do.** Navigate or script the Moodle page, read Moodle's cookies
or DOM, open pop-ups, show modal dialogs, or download files. The plugin refuses to render
the iframe unless the app is on a different origin than Moodle, because
`allow-scripts` with `allow-same-origin` would let a same-origin page remove its own
sandbox.

`allow-forms` is granted because the app's editor is built on forms, and a sandbox without
it blocks their submission before the app's handlers run. It adds nothing the app cannot
already do with scripts: a form can only navigate the iframe itself, since top navigation
and pop-ups stay blocked, so it does not widen what the app can do to Moodle.

**What the host accepts from the app.** The page ignores any message that does not
come from the configured app origin **and** from the iframe's own window, that is not
version 1, or whose type or fields are not exactly as expected. In particular:

- `open-activity` carries only an activity id. The page looks it up in its own copy of
  the user's course state. It opens the URL only if the activity is not restricted and
  the URL is a Moodle URL: in the popup when it is on the page's own origin under
  `{wwwroot}/mod/` and its module is not in the new-tab list, in a new tab (with
  `noopener`) for any other Moodle page, and not at all for anything else. Dot segments
  are resolved before the path is checked, and only `http` and `https` are accepted. The
  app never supplies a URL.
- `error` details come from the app and are untrusted. They are shortened to one line
  of at most 200 characters and shown as text, never as HTML.
- `open-activity-settings` (editor mode only) carries only an activity id and can do one
  thing: open, in a new tab, the settings page of that activity on this Moodle site. See
  [below](#opening-an-activitys-settings).
- `request-fullscreen` toggles an overlay (and asks the browser for its own full screen,
  which the browser may refuse). While it is on, the top-right 64 x 64 CSS px of the viewport
  belong to the page: its exit control sits there, above the app, so the app cannot cover it,
  and it works even if the app is frozen. A malicious app can still draw a fake prompt
  elsewhere in its frame, as noted below.

**What the app can do.** It receives the signed-in user's assertion and their course
state: activity and section names, the URLs of activities they can open, whether each
is available, restricted or complete, the restriction text, and the ids (not names) of
activities hidden from them. It sees the user's network address and browser details,
because their browser loads it directly. It controls everything drawn inside its frame,
so a malicious app could show a fake prompt there. Moodle never asks for credentials
inside the frame.

The Moodle administrator therefore decides which service receives this data. Point the
plugin only at a service you trust.

`allow-downloads` is granted so the map's Export button can save a file that the app generates
itself. It lets the frame start downloads and nothing else: no access to the Moodle page, its
cookies or its navigation. A hostile app could offer the user a file to save, as any website can;
the browser's own download protections apply to it.

## Completion statistics

The teacher heat map gets, through the page, how many tracked students completed or failed each
activity. The design keeps this to aggregates:

- The function `format_rumbos_get_course_stats` takes no user id and returns no user id, name or
  per-user row; the response has a fixed set of fields, and a test checks that no name, e-mail or
  id of any enrolled user appears in the response or in the cache.
- It requires `format/rumbos:viewstats` in the course and checks it on every call; the host also
  answers a request outside editor mode with an error. A user who may see only some groups gets
  only those, and asking for another group is refused.
- Below the **minimum cohort** (default 5) no count is computed or sent, so small groups cannot be
  used to learn about individuals.
- The numbers go to the iframe with `postMessage` to the app's origin and are never part of the
  assertion, `host-init` or `host-state`; the backend does not receive them. Nothing is stored in
  the database or logged by the plugin. The result sits in a Moodle application cache for 5
  minutes.
- Residual risk: a teacher who may see two groups can ask for each and for "all" and compare, which
  they could already do in the core completion report. The app also learns the numbers it asks
  for, which is the point; a hostile app could ask repeatedly, and the host answers at most one
  server call per group every 2 seconds.

## Opening an activity's settings

`rumbos:open-activity-settings { cmid }` lets a teacher editing the map jump to an activity's
completion settings. What it can and cannot do:

- **It can** make the page open one new tab (with `noopener`) on
  `{wwwroot}/course/modedit.php?update={cmid}&showonly=activitycompletionheader`, the settings
  page Moodle's own completion dialog links to.
- **It cannot** open anything else. The app supplies only the id, never an address. The page
  ignores the message unless the page is in editor mode and the id is in its own last course
  state; then it asks the AJAX function `format_rumbos_get_activity_settings_url`, which has
  no user id parameter, requires a Rumbos participant who holds `format/rumbos:editmap` in the
  course **and** `moodle/course:manageactivities` in the activity, rejects an id that is not an
  activity of that course or is being deleted, and builds the address with Moodle's URL class.
  The page then checks again that the answer is the settings page of this site before opening it.
- **It cannot change anything.** It opens a page; saving the settings is Moodle's own form,
  with its own capability checks and session key. A student, or a teacher who does not edit the
  map or may not manage that activity, gets nothing, and the message gets no answer.
- If the browser blocks the new tab the page shows a notice instead of navigating away.

## The activity popup

The popup frames a **same-origin** Moodle page from the Moodle page itself, never from
inside the Rumbos iframe, so the framed page is not a child of a cross-site ancestor and
keeps its normal cookies.

- **What it validates.** Only URLs from the host's own course state, under
  `{wwwroot}/mod/`, on the page's origin. The activity title is shown with `textContent`
  and never as HTML.
- **No sandbox on the popup frame.** A same-origin page that needs scripts needs
  `allow-same-origin` too, and the two together give the page no isolation (it can lift
  the sandbox itself). Chrome also refuses to show a PDF in any sandboxed frame. So the
  attribute would add no protection and would break file activities. The protection is
  the URL check above; compare the Rumbos app frame, which is cross-origin and sandboxed.
- **Messages from the framed page** (`rumbos:popup-leave`, `rumbos:popup-escape`) are
  accepted only from the popup frame's own window, from this site's origin, and only
  close the popup. They carry no data.
- **The page hook** loads the popup script only for same-origin iframe requests (browser
  headers `Sec-Fetch-Dest: iframe` and `Sec-Fetch-Site: same-origin`, which page scripts
  cannot forge) on a page of a Rumbos course; the script also checks that the window is
  named `rumbos-popup`. Every other page is served unchanged. The same check keeps the
  Rumbos map from being rendered inside the popup.
- **The popup marker.** Before loading the popup the host page calls
  `format_rumbos_start_popup_session`, which stores `{courseid, expires}` in the user's own
  Moodle session (5 minutes, extended by each matching request, cleared when the popup
  closes). The function requires an activity the user can see in a Rumbos course. The marker
  **can** make pages of that course, in that session, requested as a same-origin iframe, use
  the `embedded` layout and have the configured selectors hidden. It **cannot** grant access,
  skip a capability or login check, change what a page contains, or affect another user,
  another course, another session, or a top-level navigation in another tab (those requests
  carry other `Sec-Fetch-*` values and are ignored). Without the `Sec-Fetch-*` headers a
  request only receives the stylesheet, scoped to a body class that the page script adds only
  in the window named `rumbos-popup`. Hiding is cosmetic: it is not an access control.
- **The hide selectors** are admin configuration validated to a safe subset (no braces, `@`,
  `url()`, commas, comments or angle brackets; balanced brackets and quotes; bounded count and
  length) and written as one scoped rule each, so they cannot close a rule or load anything.
- **Links to other sites** inside the popup get `target="_blank"` and `rel="noopener"`,
  so an external page cannot take over the popup or reach the Moodle page.

## Keys and secrets

- The signing key pair and the secret behind the pseudonymous ids are generated on the
  site. The private key and the secret are stored encrypted with Moodle's
  `\core\encryption` (sodium); the key for that is a file in `dataroot/secret`.
- Pseudonymous ids are HMAC-SHA256 of the Moodle user id with a per-site secret. Without
  the secret they cannot be linked to users. **With the secret they can**: user ids are
  small integers, so anyone holding the secret can recompute every id.
- Rotating the key keeps the secret, so ids stay stable. A reset, offered only when the
  stored secrets cannot be decrypted, replaces both and changes every id.
- Neither secret is logged, shown, or put in a rendered page. The settings page shows
  only the public key and its id.

## What an attacker can do

| Attacker | Capability | Consequence |
| --- | --- | --- |
| Signed-in student | Calls the external functions for a course they are in | Receives their own assertion and state; no way to ask for another user's |
| Signed-in student | Changes the DOM or messages in their own browser | Can only affect their own session; the service must authorise by the assertion's role, never by anything the page sends |
| Not signed in, or a guest | Calls the external functions | Refused |
| Malicious Rumbos app | Sends crafted `postMessage` data | Ignored unless it matches the protocol; cannot navigate the page or inject HTML |
| Moodle administrator in the web UI | Changes the app URL, grants `editmap`, rotates keys | Can send users' assertions and course state to a different HTTPS service. This is within what an administrator can already do in Moodle, but note that a service that is handed an assertion could present it to the real service while it is valid, which is why the service should reject a repeated `jti` |
| Someone who can read the database **and** `dataroot/secret` | Decrypts the private key and the secret | Can sign assertions for any user, course and role, and can compute every pseudonymous id. Protect both with the same care as Moodle's other secrets |
| Someone who can read only the database | Sees encrypted values and the public key | Cannot sign or decrypt |

## Residual risks and recommendations

- The plugin cannot prevent replay inside the 60-second window. The Rumbos service
  should reject a repeated `jti` for the same issuer.
- Register the public key per site in the service, and treat a key change as a manual,
  confirmed operation.
- Allow the app origin in `frame-src` on the Moodle side and Moodle's origin in
  `frame-ancestors` on the app side, and nothing wider.
- Back up `dataroot/secret` with the database. Losing it makes the stored secrets
  unreadable and forces a reset.
- Keep the Moodle site and this plugin on supported versions.
