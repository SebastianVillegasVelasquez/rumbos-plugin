# Quizzes with browser security "secure window" in the activity overlay

Verified on 2026-11-13 against Moodle `5.2.4+` (build 20261013, branch 502), PostgreSQL 17, Chrome on Windows 11.
Core paths are relative to `public/`. The overlay frame was the plugin's own (`amd/src/popup.js`): a same-origin
`iframe` named `rumbos-popup`, `allow="fullscreen"`, **no `sandbox`**, mounted in the Moodle page.

## Short version

The earlier statement "a quiz with the secure window setting does not work in the overlay" is **not accurate** for the
overlay as the plugin builds it. Moodle opens the attempt in a separate browser window (`window.open`), and that
works from the overlay frame. The student can start, answer, submit, review and finish the attempt there. Finishing
reloads the overlay frame and closes the popup.

What does not work, and why:

| Situation | Result | Depends on |
| --- | --- | --- |
| Overlay frame without `sandbox` (today) | Works | us (current attributes) |
| Overlay frame with `sandbox` and no `allow-popups` | "Attempt quiz" does **nothing**, silently | us (attributes) |
| Browser blocks the popup (no user gesture, blocker) | Same silent no-op | the browser |
| Overlay closed while the popup is open | The popup does not close: it navigates itself to the quiz page and stays open as a full Moodle page | Moodle's design |

## Reproduction

Student account, overlay frame mounted as above, three quizzes with one true/false question each.

| Quiz | Start button | Popup | Result |
| --- | --- | --- | --- |
| (a) Browser security "None" | Plain form POST inside the frame | No `window.open` call | Attempt, finish and review all inside the frame |
| (b) "Full screen popup with some JavaScript security" | `window.open` called once, returned a window (not blocked) | Opened `attempt.php`, body class `quiz-secure-window`, layout `secure`, `window.opener` is the overlay frame | Attempt completed in the popup. "Finish review" reloaded the overlay frame (it then showed the finished attempt and grade) and closed the popup |
| (c) With a password | The password form appears in a modal inside the frame | No `window.open` call | Attempt started in the frame |
| (b) with the frame given `sandbox="allow-scripts allow-same-origin allow-forms allow-downloads"` | `window.open` returned `null` | None | Nothing happens. No console error, no blocked-request message |
| (b), overlay removed while the popup is open | n/a | `window.opener` becomes `null` | "Finish review" navigated the popup itself to `view.php` (layout `incourse`); it did not close |

No `X-Frame-Options` or CSP message appeared: the frame is same-origin, so Moodle's `X-Frame-Options: sameorigin`
(`lib/weblib.php:1621`) allows it.

## Cause, with core references

1. `quizaccess_securewindow::make()` applies the rule when `quiz.browsersecurity === 'securewindow'`
   (`mod/quiz/accessrule/securewindow/rule.php:44-50`). `attempt_must_be_in_popup()` returns true for everyone except
   preview users, i.e. teachers who can preview (`rule.php:53-55`). A teacher testing the quiz therefore never sees
   the popup behaviour a student gets.
2. `view.php:209-210` passes `popuprequired` and the popup options to the renderer, which calls
   `start_attempt_button()` (`classes/output/renderer.php:982-1003`). That adds the class `quizsecuremoderequired`
   and initialises `mod_quiz/preflightcheck` with the popup options.
3. `preflightcheck.js:98-110` (`launchQuizPopup`) calls `e.halt()` first, then `window.openpopup()` with the form URL.
   `openpopup()` (`lib/javascript-static.js:928-965`) calls `window.open()` and, if the result is falsy, returns
   without any message. Because the click was already halted, **a blocked popup leaves the button dead**. This is the
   mechanism of the sandbox failure above.
4. The attempt page is a normal top-level page. Nothing checks `window.top`, `window.opener` or the frame name on
   the way in. `setup_attempt_page()` (`rule.php:61-73`) sets layout `secure` and `M.mod_quiz.secure_window.init`,
   which only blocks the context menu, selection, cut, copy, paste and printing (`mod/quiz/module.js:343-367`).
5. Leaving: after submitting, `access_manager::back_to_view_page()` (`classes/access_manager.php:528-536`) and the
   review page's "Finish review" button (`renderer.php:267-276`) run `M.mod_quiz.secure_window.close()`
   (`module.js:420-429`): if `window.opener` exists it reloads the opener and closes the window; **otherwise it sets
   `window.location.href` to the quiz view URL**. The opener is the overlay frame, so the frame reloads. If the opener
   no longer exists (overlay closed), the fallback navigates the popup itself.
6. Session and cookies: the popup is a same-site top-level window with the student's session; nothing failed.

Discarded: the popup needing `window.opener` (it needs it only to refresh the opener, with a fallback), a top-level
check on the attempt page (none exists), cookie or session problems (none), and a framing refusal (same-origin).

Note a Moodle quirk seen in passing: `preflightcheck.js:106` passes `windowname: 'quizpopup'`, but `openpopup()` reads
`args.name`, so the window is opened as `_blank`.

## What decides the outcome

- **Ours:** the frame's `sandbox`. The plugin's overlay frame has none, on purpose (`amd/src/popup.js`, PDF viewer);
  that is also what lets this work. If a sandbox is ever added to the overlay frame, it needs `allow-popups`, and
  `allow-popups-to-escape-sandbox` would be needed for the popup not to inherit it.
- **The browser:** popup policy. Chrome allowed the popup from a real click. Not run: Firefox, Safari, mobile
  browsers, with a popup blocker on.
- **Moodle's design:** the popup depends on the opener staying alive. A student who closes the overlay with the
  attempt open ends up with a loose full-size popup showing the quiz page. The attempt itself is not lost.

## Options (not implemented)

1. **Leave it as is and document it** (this file). No code, no new contract. Cost: the editor's fixed warning says
   "does not work", which is wrong. Risk: a student on Safari or with a blocker sees a dead button.
2. **Allow the popup path explicitly.** Nothing to do today (no sandbox). If a sandbox is added later:
   `allow-popups` and the same-origin trio. Security cost: a popup is a full Moodle top-level window with the
   student's session, which is what Moodle intends. It does not widen what the Rumbos app (a different, sandboxed
   frame) can do.
3. **Open secure-window quizzes in a new top-level tab** (`new-tab` style, as `newTabModules` already does for other
   modules). The host would need to know the setting; the app would not learn that the attempt finished, so it
   would have to poll `request-quiz-result` or ask when the tab regains focus. More moving parts than option 2, and
   it loses the attempt-then-reload behaviour that already works.
4. **Detect the setting server-side and expose it.** Proposal only: an additive, optional boolean in the
   existing quiz result answer, `secureWindow` (or the category as a string, `browserSecurity: "none" | "securewindow"
   | "other"`). `quiz_result::compute()` already loads the `quiz` record (`classes/local/quiz_result.php:95`), so
   the value is `$quiz->browsersecurity`. The `cmid` access checks of `for_user()` (lines 59-90: course and cm
   lookup for the user, `mod/quiz:view`, `uservisible`) stay in front of it, and the value is a setting every
   student of that quiz already sees on its view page, so nothing beyond what the student can see is returned. Cost:
   a contract change (`HOST-CONTRACT.md`, `get_quiz_result` return structure, the protocol sanitiser, a version
   note) and a frontend change, to turn a warning that is currently wrong into one that is right.

## Recommendation

Option 1 for now. The check-in quiz is ours and is created with browser security "None", the case that works with
no caveat. Option 4's cost (contract, protocol, frontend, tests) is not justified by a quiz nobody authors with the
secure window. If teachers will be able to link their own quizzes, revisit option 4, and in that case the editor
warning should say "opens in a separate window" instead of "does not work".

## Proposed wording for the frontend's `docs/WELCOME-FLOW.md`

> **Quizzes with browser security "secure window".** Moodle opens the attempt of such a quiz in a separate browser
> window. In the activity overlay this works: the student answers and finishes in that window, and the overlay
> reloads. It needs the browser to allow the popup, and the overlay to stay open until the attempt is finished; if the
> student closes the overlay first, the popup stays open on the quiz page. The check-in quiz should use browser
> security "None", which keeps everything inside the overlay. The editor's warning should say that the quiz opens in
> a separate window, not that it does not work.

## Not verified

- Firefox, Safari, mobile browsers and popup blockers; only Chrome (allowed).
- The real Rumbos app sending `open-activity`. The overlay frame was built by hand with the plugin's attributes
  (name, `allow`, no `sandbox`), not through the host's message path.
- The popup's visible size: Chrome opened it, the screenshot tool could not capture a second window.
- Quizzes with the Safe Exam Browser rule (`quizaccess_seb`), a separate mechanism.
- A time-limited secure-window quiz.
