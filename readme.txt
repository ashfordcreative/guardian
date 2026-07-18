=== Ashford Guardian ===
Contributors: ashfordcreative
Tags: updates, auto-update, maintenance, security, monitoring
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 2.2.0
License: GPL-2.0+

Self-contained smart auto-updates, with an optional Guardian Hub connection for fleet visibility. Patch releases apply immediately, minor releases after a safety delay, security-flagged changelogs fast-tracked, majors left for humans. WordPress same-branch maintenance/security releases are applied automatically.

== Description ==

Ashford Guardian decides which plugin updates WordPress may auto-apply:

* Patch (x.y.Z) — apply immediately
* Minor (x.Y.z) — apply after a configurable safety delay (default 3 days)
* Major (X.y.z) — manual only (optional auto after delay)
* Security-flagged changelog — fast-tracked (delay skipped for patch/minor)
* Denylisted slug — never touched
* License-blocked or failed auto-updates — tagged in the UI and emailed once per issue
* WordPress core same-branch maintenance/security releases — applied automatically; majors never

Configure policy under Tools → Guardian. Activity is logged for client reporting.

Optionally, pair the site with a **Guardian Hub** for centralized fleet visibility: check-ins with a full component inventory, login/content/plugin/theme/user activity, and update reports. The hub connection is entirely additive — if the hub is unreachable or never configured, the auto-update policy above keeps running exactly the same. Hub down means no visibility, never no protection.

== Installation ==

1. Upload the `ashford-guardian` folder to `/wp-content/plugins/`, or install the zip via ManageWP / wp-admin.
2. Activate the plugin.
3. Optionally tune delays and denylist under Tools → Guardian.
4. Optionally pair with a Guardian Hub under Tools → Guardian → Guardian Hub (enter the hub URL, pair, then paste the API key once the operator approves the site).

== Changelog ==

= 2.2.0 =
* Automatically apply WordPress same-branch maintenance/security releases (never major or development).
* Surface pending/blocked core updates in Tools → Guardian, email once when core cannot update, and report core blocked/pending state to the hub.

= 2.1.1 =
* Detect premium updates with no download package (typical expired license) and auto-update failures.
* Tag blocked updates in Tools → Guardian, email once per new issue, and report `blocked` state / `update.blocked` to the hub.

= 2.1.0 =
* Add optional Guardian Hub integration: pairing, encrypted API key storage, and a durable local event queue with retry/backoff.
* Emit `agent.checkin` (component inventory), `actor.action` (logins, content saves, plugin/theme/user changes), and `update.applied` (with from/to versions and a manual-vs-policy heuristic) events.
* Process hub commands piggybacked on event delivery: `hold_release` / `release_hold` (against the policy denylist), `resync_inventory`, `run_verification`, and a stub ack for `set_patrol_frequency`.
* New "Guardian Hub" and "Hub activity" sections under Tools → Guardian; auto-update policy behavior is unchanged.

= 2.0.2 =
* Admin UI uses full-width layout and WordPress system fonts.

= 2.0.1 =
* Modernized Tools → Guardian admin UI with clearer Check for updates / Apply due updates actions.

= 2.0.0 =
* Initial public release with policy engine, security fast-track, digest email, and admin log.
