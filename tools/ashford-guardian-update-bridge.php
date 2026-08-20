<?php
/**
 * Plugin Name: Ashford Guardian — temporary GitHub update token
 * Description: One-time bridge so pre-2.4.3 Guardian can check GitHub without API 403s. Remove after every site is on 2.4.3+.
 * Version: 1.0.0
 *
 * Install as an mu-plugin (wp-content/mu-plugins/ashford-guardian-update-bridge.php)
 * or push via ManageWP / MainWP to the whole fleet, then run a plugin update check.
 *
 * 1. Create a fine-grained GitHub PAT with read-only access to ashfordcreative/guardian.
 * 2. Paste it below (or define ASH_GUARDIAN_GITHUB_TOKEN in wp-config.php instead).
 * 3. Dashboard → Updates → Check again; update Ashford Guardian to 2.4.3+.
 * 4. Delete this mu-plugin (2.4.3+ no longer needs the token).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'ASH_GUARDIAN_GITHUB_TOKEN' ) ) {
	// Paste the read-only PAT between the quotes, then deploy.
	define( 'ASH_GUARDIAN_GITHUB_TOKEN', '' );
}
