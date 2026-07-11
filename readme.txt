=== Ashford Guardian ===
Contributors: ashfordcreative
Tags: updates, auto-update, maintenance, security
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 2.0.1
License: GPL-2.0+

Self-contained smart auto-updates. Patch releases apply immediately, minor releases after a safety delay, security-flagged changelogs fast-tracked, majors left for humans.

== Description ==

Ashford Guardian decides which plugin updates WordPress may auto-apply:

* Patch (x.y.Z) — apply immediately
* Minor (x.Y.z) — apply after a configurable safety delay (default 3 days)
* Major (X.y.z) — manual only (optional auto after delay)
* Security-flagged changelog — fast-tracked (delay skipped for patch/minor)
* Denylisted slug — never touched

Configure policy under Tools → Guardian. Activity is logged for client reporting.

== Installation ==

1. Upload the `ashford-guardian` folder to `/wp-content/plugins/`, or install the zip via ManageWP / wp-admin.
2. Activate the plugin.
3. Optionally tune delays and denylist under Tools → Guardian.

== Changelog ==

= 2.0.1 =
* Modernized Tools → Guardian admin UI with clearer Check for updates / Apply due updates actions.

= 2.0.0 =
* Initial public release with policy engine, security fast-track, digest email, and admin log.
