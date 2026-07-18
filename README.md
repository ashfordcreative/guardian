# Ashford Guardian 2.1

Self-contained smart auto-updates for WordPress plugins, with an optional connection to a **Guardian Hub** for fleet-wide visibility. The auto-update policy engine needs no external service and keeps working unchanged if the hub is never configured or goes offline — hub down means no visibility, never no protection.

## The policy engine

Every hour (WP-Cron), Guardian refreshes update data and lets WordPress core run auto-updates — but Guardian decides what's approved:

| Release type | Default behavior |
|---|---|
| Patch (x.y.**Z**) | Apply immediately |
| Minor (x.**Y**.z) | Apply after 3-day safety delay |
| Major (**X**.y.z) | Manual only (optional auto after 7 days) |
| Security-flagged changelog | Fast-tracked — delay skipped (patch/minor) |
| Denylisted slug | Never touched, hard block |

Security detection = keyword scan of the release's wordpress.org changelog entry and the update's upgrade notice ("security", "XSS", "CVE", "SQL injection", etc.). Data every WP site already consumes — no new vendors.

## Why this eliminates the daily vulnerability reports

Vulnerability reports flag plugins where a fix exists but isn't applied. With patch releases applying same-day and security releases fast-tracked, the window between fix and deployment collapses to hours. ManageWP's free vulnerability detection remains your safety net for the rare unpatchable case.

## Install

Zip → ManageWP bulk install → activate. Policy defaults are sane; tune under **Tools → Guardian**.

## Safety rails

- **WP 6.6+ auto-rollback**: core automatically reverts a plugin auto-update that causes a fatal error.
- **Delays absorb bad releases**: a broken minor release is usually re-patched within days — your 3-day delay means fleet sites often never see it.
- **Denylist** anything fragile (page builders on brittle sites).
- **Backups**: keep ManageWP scheduled backups on; the log identifies rollback targets.
- **Digest email** per update run + full activity log for client reporting.

## Notes

- Premium plugins update only if their license/updater is active; Guardian approves, their updater supplies the package.
- Reliable timing needs working cron. For maintenance clients: real server cron hitting wp-cron.php every 15 min.
- Non-semver plugins (date-based versions etc.) classify as "unrecognized" and are left manual.

## Self-updating

GitHub Releases power WordPress/ManageWP updates via the embedded
[plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker).
See [RELEASING.md](RELEASING.md) for the tag → zip → update flow.

## Guardian Hub integration (optional)

Under **Tools → Guardian → Guardian Hub** you can pair this site with a Guardian Hub:

1. Enter the hub URL (e.g. `http://localhost:3000` for local development) and save.
2. Click **Pair with hub**. The site registers itself and shows "Pending approval."
3. In the hub, approve the site under its pending pairings. The hub shows a one-time API key.
4. Paste that key into **API key** on the Guardian Hub section and save — the plugin immediately sends a real check-in to verify the key, and flips to "Paired" once the hub accepts it.

Once paired, the site emits to the hub on a 5-minute cron cycle (`ag_five_minutes`), piggybacked on the hourly policy tick for check-ins:

* `agent.checkin` — full component inventory (core, PHP, every plugin, every theme).
* `actor.action` — logins, post/page saves, plugin/theme activation or deactivation, theme switches, user account changes.
* `update.applied` — emitted when core/plugin/theme updates complete, with from/to versions, a correlation id per update batch, and a best-effort `is_migrator` flag (true when the update happened outside Guardian's own policy tick — e.g. a human clicking "Update now" or WP-CLI — false when Guardian's policy engine applied it).

Events are written to a local queue table (`{prefix}ashford_guardian_queue`) before any network call, so a hub outage or a flaky cron run never loses an event — delivery just retries with backoff. The queue is capped (oldest `info`-severity events are pruned first) so a prolonged outage can't grow the table unbounded.

Commands piggybacked on the hub's response to an event batch are processed on the next flush:

* `hold_release` / `release_hold` — resolved against installed plugins where possible and enforced through the same denylist the policy engine already checks, so a hold survives hub downtime once set.
* `resync_inventory` — forces an immediate inventory rebuild.
* `run_verification` — reports basic agent vitals (WP/PHP version, cron health, active theme, plugin count).
* `set_patrol_frequency` — stub: logged and acknowledged, since patrol scheduling is a hub-side concept this agent doesn't enforce locally.

Every command is acknowledged by emitting a related event (the hub has no separate ack endpoint), carrying forward `correlation_id`/`resolves` from the command payload where available.

### Verifying a check-in reached the hub

With the hub running locally (`npm run dev` in the hub repo, default `http://localhost:3000`):

1. Pair and key the site as above (or trigger **Flush queue now** on Tools → Guardian to send immediately instead of waiting for cron).
2. In the hub, open the site's page — `lastCheckinAt` should update and the component inventory should populate from the `agent.checkin` payload.
3. Tools → Guardian → **Hub activity** on the WordPress side logs every pairing attempt, flush, and error locally for quick troubleshooting.
