# Virtual tutor overlay

The Rumbos app can show a **Tutor virtual** button. The tutor lives on the Moodle site itself
(for example `https://academiaturismo.mincit.gov.co/agente/chat`) and answers with
`X-Frame-Options: SAMEORIGIN`, so the app, on another origin, cannot frame it. The plugin (the trusted host)
opens it in a full-viewport overlay when the app asks. Message contract: [HOST-CONTRACT.md](HOST-CONTRACT.md) (v5.10).

## Setting

Site administration > Plugins > Course formats > Rumbos > **Virtual tutor URL** (`format_rumbos/tutor_url`).

- Empty = off: no capability is announced and the app shows no button.
- Valid = `https`, no credentials, query or fragment, and **the same host and port as the Moodle site**. Anything else
  is refused when saved, checked again on every page view (a value that stopped being valid turns the feature
  off) and checked again in the browser. A Moodle site on plain http cannot use the feature, by design.

## Audit (what was found before changing anything)

1. **Iframe.** `templates/embed.mustache`: `allow="fullscreen; autoplay"`, `sandbox="allow-scripts allow-same-origin
   allow-forms allow-downloads"`, `referrerpolicy="no-referrer"`. The script is `amd/src/host.js`
   (built to `amd/build`), started from `classes/output/courseformat/content.php`.
2. **postMessage.** `protocol.js` `parseAppMessage()` accepts a message only if `event.origin === appOrigin` **and**
   `event.source === frame.contentWindow`, `v === 1`, and a known type; fields are rebuilt, not passed through.
   There was **no capability mechanism**: the only handshake is `host-init`. Capabilities are therefore a new,
   additive `capabilities` object in `host-init` (v5.10), not an assertion claim (the assertion is for the backend).
3. **Headers.** The plugin sets none. Stock Moodle sends `X-Frame-Options: sameorigin` on its pages, which allows a
   same-origin overlay frame. Any `frame-src`, `frame-ancestors` or `Permissions-Policy` set by the web server or a
   proxy is outside the plugin: see the report's "not verified" list.

## Decision log

| Decision | Why |
| --- | --- |
| `tutor.open` / `tutor.closed` keep the names given in the brief, plus `v: 1` like the rest of the contract | The brief fixes the names; `v: 1` keeps one rule for every message. **If the app does not send `v: 1` the message is ignored**; tell the app team. |
| `capabilities` is in `host-init`, always present, only true features listed | Additive; an old app ignores it; a missing flag means off. |
| The message carries nothing; the URL is the setting | The app (another origin) can never choose what is framed. |
| Same origin required, not just same host | `SAMEORIGIN` compares origins; another port or scheme would be blocked by the browser anyway. |
| Validated in the setting, again in `config::get_tutor_url()`, again in the browser | Defence in depth; a moved site or a hand-edited config value fails closed. |
| Overlay frame `sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox"` | The tutor needs scripts and its session (cookies). Scripts plus same-origin give **no isolation** from the page (it can lift its own sandbox), so this is hygiene, not a boundary. It still blocks top navigation of the Moodle page, modals and downloads. Forms: a chat is a form. Popups: links the tutor opens in a new tab; they escape so the new tab is normal. `allow-modals` and `allow-downloads` stay out until the tutor is shown to need them. |
| `allow="camera 'none'; microphone 'none'; geolocation 'none'"` | Nothing is delegated. Naming them with `none` matters: a same-origin frame otherwise inherits them. Voice input in the tutor is therefore off; enabling it is a separate decision. |
| `referrerpolicy="same-origin"` | The tutor sees where it was opened from; nobody else does. |
| No Fullscreen API | It may be refused from a `postMessage` handler; a `100dvh` fixed overlay always works. |
| Mounted inside the browser's full-screen element when the map is in native full screen | Anything outside it is not shown (same reason as the activity popup). |
| Ignored while the activity popup or the tutor is open | The app is not in front of the user then. |
| One Escape closes one thing | The host's full-screen Escape handler skips while the tutor is open. |
| Escape inside the tutor's own page is not seen by the host | A frame owns its keys; the visible **Close** button is the way out there. |
| No `host-overlay` message for the tutor | It would change the app's behaviour without being asked; `tutor.closed` is the contract. |
| No version bump | Releases are their own commit (docs/RELEASE.md). The new setting shows after a cache purge. |
| Per-course setting: not done; a cheap follow-up | The format already has course options; the page would pass the course value instead of the site value. The same-host rule would stay. |

## Privacy (for legal review, not final)

The tutor is an AI service on the same site. The plugin sends it nothing and stores nothing about its use: it only
frames the page, and the tutor's own session and data handling are the site's. Still, a student now reaches an AI
chat from inside a course, so the site's privacy notice may need to say so. **This text, and `tutorurl_desc` in the
language files, are a draft for legal review.** The privacy provider is unchanged.

## Manual test matrix

Needs an https Moodle on the same host as the tutor. Mark each cell with the browser and device used.

| Check | Chrome desktop | Safari desktop | Phone (small) | Tablet |
| --- | --- | --- | --- | --- |
| Setting empty: no button in the app | | | | |
| Setting valid: button appears; click opens the overlay | | | | |
| Open with keyboard (Enter on the button) and touch (tap) | | | | |
| Close with the Close button: mouse, keyboard, touch | | | | |
| `Esc` closes while the focus is in the host page | | | | |
| Focus returns to the Rumbos iframe; app receives `tutor.closed` | | | | |
| Tab and Shift+Tab never leave the dialog | | | | |
| Page behind does not scroll | | | | |
| Overlay fills the viewport; the on-screen keyboard does not hide the message box | | | | |
| Message from another origin or another window is ignored | | | | |
| Map in full screen: tutor opens above it, closing returns to it | | | | |
| Tutor page loads, chat sends and answers, links open in a new tab | | | | |

## Configure and turn off (production)

1. Deploy the plugin version with this change, run the Moodle upgrade, purge caches.
2. Site administration > Plugins > Course formats > Rumbos > Virtual tutor URL: enter
   `https://academiaturismo.mincit.gov.co/agente/chat` and save. An error means the host or scheme is not the site's own.
3. Open a course: the **Tutor virtual** button should appear in Rumbos (the app version that reads `capabilities`
   and sends `tutor.open` must be deployed).
4. Turn off: empty the field and save. The next page load announces no capability and the button disappears.
