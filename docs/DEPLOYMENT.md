# Deploying a new version

For the Moodle administrator. Follow these steps for every release, in this order. Installing the
plugin the first time takes the same steps; the first time also needs the configuration in the
[README](../README.md#configuration).

## 1. Install the ZIP

1. Download `format_rumbos-<release>.zip` from the release page (see [RELEASE.md](RELEASE.md)).
2. As an administrator, open **Site administration > Plugins > Install plugins**, upload the ZIP,
   check the plugin type is "Course format (format)" and confirm. The archive has a single top-level
   folder `rumbos`, which Moodle places at `public/course/format/rumbos`.

Without the web installer, replace the `public/course/format/rumbos` directory with the ZIP's `rumbos`
folder and go on with step 2.

## 2. Run the upgrade

**This step is required for every release that changes the web service functions**, as this one does
(it adds `format_rumbos_get_activity_settings_url`). Moodle reads `db/services.php` only when the
plugin's `version` goes up and the upgrade runs. Without it the page asks for a function Moodle
does not know, and the map editor's "configure completion" shortcut fails (the map itself keeps working).

Run it from **Site administration > Notifications**, or on the command line:

```sh
php admin/cli/upgrade.php
```

The upgrade also shows the plugin's new settings; save the page once to keep the defaults (new
settings read their default until then, so nothing breaks if you skip it).

## 3. Purge the caches

**Site administration > Development > Purge caches > Purge all caches**, or:

```sh
php admin/cli/purge_caches.php
```

This is needed for the new language strings, the changed template and the new JavaScript, which Moodle
and the browsers cache. Users who still see the old behaviour need a hard refresh; the JavaScript URL
changes with the purge, so it is usually not needed.

## 4. Check it

1. Open a Rumbos course as a teacher with editing on: the map loads.
2. Open an activity that has a completion condition as a student (or "Log in as"): the progress on the map
   changes while the popup is still open.
3. In the map editor, ask for an activity's completion settings: a new tab opens on that activity's settings.

## Settings added in this version

| Setting | Default | What it does |
| --- | --- | --- |
| `pollmodules` | `scorm,h5pactivity` | Modules whose open popup also reads the course progress every 15 seconds while the tab is visible. Empty turns it off. Each read queries the whole course (see [COMPLETION-STATES.md](COMPLETION-STATES.md#what-a-read-costs)). |

## Several web servers

Nothing to configure: the state function drops the user's cached completion before every read, so it
does not depend on the servers sharing a cache. Other Moodle pages still use that cache as usual.

## Older builds of the Rumbos app

Plugin and app do not have to be deployed together. This version is contract v5.4, which only adds to
v5 to v5.3 ([HOST-CONTRACT.md](HOST-CONTRACT.md)). What an app built for an earlier version sees:

| Change | An older app |
| --- | --- |
| `courseCompletionEnabled` in the state | An extra field. An app that ignores unknown fields is unaffected. **An app that validates the state strictly may reject it, and was not tested.** Update such an app first. |
| The exit control of full screen (64 x 64 px, top-right) | The app's own toggle in that corner is covered while the mode is on. Leaving full screen still works through the host's control and Escape. |
| `host-fullscreen` after every change | An app that only reads it as the answer to its own request still works; it just also hears the other changes. |
| More `host-state` messages (every popup page, a poll, the close) | Each carries the whole state, so an app that applies it idempotently is unaffected. The host sends one only when something changed, except on close and when the app asks for it. |
| `open-activity-settings` | An older app never sends it. |

The other direction, a newer app on an older plugin: the old host ignores a message type it does not
know, so `open-activity-settings` does nothing, and the state has no `courseCompletionEnabled`; the app
must treat a missing field as "unknown", not as `false`.
