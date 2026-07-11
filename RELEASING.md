# Releasing Ashford Guardian

Repo layout assumption: the repository root **is** the plugin folder
(`ashford-guardian.php` at the root). The workflow lives at
`.github/workflows/release.yml` and is excluded from the built zip.

## One-time setup

1. Commit `release.yml` to `.github/workflows/release.yml` in the repo.
2. In `ashford-guardian.php`, set the repo URL in the update-checker block
   (`https://github.com/ashfordcreative/guardian/`). Commit.
3. **Private repo only:** on each site (or in a mu-plugin shared across your
   maintenance stack), add to `wp-config.php`:

   ```php
   define( 'ASH_GUARDIAN_GITHUB_TOKEN', 'github_pat_XXXX' );
   ```

   Use a fine-grained PAT scoped to this one repo, read-only Contents
   permission. If the repo is public, skip this entirely — no token needed.

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

4. The Action builds `ashford-guardian.zip` and attaches it to a GitHub
   Release. If the tag doesn't match the plugin header, the build fails
   loudly instead of shipping a mismatched version.

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
