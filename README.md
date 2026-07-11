# Ashford Guardian 2.0

Self-contained smart auto-updates for WordPress plugins. **No external services, feeds, or API keys.** Built for the Ashford Creative maintenance fleet.

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

Drop [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker) into `/plugin-update-checker`, set your repo URL in the main file, push tagged releases.
