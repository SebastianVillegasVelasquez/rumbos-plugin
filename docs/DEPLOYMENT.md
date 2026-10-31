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

## Completion statistics and downloads (contract v5.7)

- **Run the upgrade (step 2) and purge the caches (step 3).** The upgrade installs the capability
  `format/rumbos:viewstats` (editing teacher and manager by default), the setting
  `mincohort` ("Minimum students to show statistics", default 5), the cache definition
  `coursestats` and the AJAX function `format_rumbos_get_course_stats`. The purge is needed for
  the new JavaScript and the changed template.
- **Nothing is created in the database**: no table and no stored statistic.
- The iframe's sandbox gains `allow-downloads`. If a proxy or a page cache keeps old HTML, the
  app's Export button stays blocked until it is purged.
- **Older builds of the Rumbos app** never send `rumbos:request-stats`; nothing changes for them.
- To give another role access to the numbers, allow `format/rumbos:viewstats` for it in the course
  or site permissions. A teacher who is not in any group of a course that uses groups, and lacks
  `moodle/site:accessallgroups`, sees nothing by design.

## Release 0.3.0: students, empty maps and size

- **Run the upgrade (step 2) and purge the caches (step 3).** The release adds site settings
  (`heightmode`, `heightvalue`, `mobileheight`, `minheight`, `maxheight`) and course options
  (`rumbos_heightmode`, `rumbos_heightvalue`, `rumbos_mobileheight`). The upgrade lists the new settings.
  The layout is in `styles.css`, which Moodle serves from the theme cache, so without the purge the old
  fixed height stays.
- **Older builds of the Rumbos app** never send `rumbos:no-content` or `rumbos:preferred-size`. They keep
  working: a student of a course without a saved map still sees the app's own empty screen (there is no
  fallback until the app sends the message), and in automatic mode the area stays at the reserved 16 / 9
  box. To keep the old look until the app is updated, choose a fixed height in the site settings.
- **Testing as a student:** use a real enrolled student, or "Switch role to... Student". A manager who is
  not enrolled used to lose the map when switching role; that is fixed in this release.

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
