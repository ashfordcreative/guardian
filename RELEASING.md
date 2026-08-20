# Releasing Ashford Guardian

Repo layout assumption: the repository root **is** the plugin folder
(`ashford-guardian.php` at the root). The workflow lives at
`.github/workflows/release.yml` and is excluded from the built zip.

## One-time setup

1. Commit `release.yml` to `.github/workflows/release.yml` in the repo.
2. Sites check for updates via the release metadata file (no GitHub API):

   `https://github.com/ashfordcreative/guardian/releases/latest/download/update-info.json`

   Override with `ASH_GUARDIAN_UPDATE_URL` in `wp-config.php` only if you host
   metadata elsewhere.

## Migrating a fleet stuck on GitHub API 403s (pre-2.4.3)

Sites still on ≤2.4.2 talk to the GitHub API and can hit shared-host rate
limits. They cannot self-update to 2.4.3 until one of these happens once:

1. **Temporary token (best for many sites):** create a fine-grained read-only
   PAT for `ashfordcreative/guardian`, put it in
   `tools/ashford-guardian-update-bridge.php`, push that file to
   `wp-content/mu-plugins/` on every site (ManageWP / MainWP), check for
   updates, install 2.4.3+, then delete the mu-plugin.
2. **Bulk zip:** push
   `https://github.com/ashfordcreative/guardian/releases/download/v2.4.3/ashford-guardian.zip`
   to all sites via ManageWP / MainWP “install plugin from zip”.
3. **Per-site wp-config:** `define( 'ASH_GUARDIAN_GITHUB_TOKEN', '…' );`
   then check for updates (same idea as option 1).

After 2.4.3+, updates use `update-info.json` and no longer need a token.

## Shipping a release

1. Make your changes.
2. Bump the version in **two places** (they must match the tag):
   - `ashford-guardian.php` → `Version:` header **and** the
     `ASH_GUARDIAN_VERSION` constant
   - `readme.txt` → `Stable tag:`
3. Commit, then tag and push:

   ```bash
   git commit -am "Tighten security keyword matching"
   git tag v2.0.1
   git push && git push --tags
   ```

4. The Action builds `ashford-guardian.zip` and `update-info.json`, then
   attaches both to a GitHub Release. If the tag doesn't match the plugin
   header, the build fails loudly instead of shipping a mismatched version.

## What sites see

- WordPress checks for plugin updates on its normal schedule (~every 12
  hours). The new version appears under **Dashboard → Updates** and in
  the Plugins list like any other update. To force a check immediately:
  Dashboard → Updates → the update-check link, or `wp plugin update
  ashford-guardian` via WP-CLI.
- **ManageWP picks these up too** — the update shows in your dashboard
  across every connected site running the plugin, so rolling a fix to the
  whole maintenance book is the same one-click flow as any other plugin.

## Versioning convention

- Patch (`2.0.x`) — fixes, policy tweaks
- Minor (`2.x.0`) — new features (admin UI, notification options)
- Major (`x.0.0`) — anything requiring manual steps on sites (note the
  steps in the release description; they show on the release page and in
  the update details modal)
