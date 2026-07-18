<?php
/**
 * Plugin Name:       Ashford Guardian
 * Plugin URI:        https://ashfordcreative.com
 * Description:       Self-contained smart auto-updates, with an optional Guardian Hub connection for fleet visibility (check-ins, activity, update reporting). Patch releases apply immediately, minor releases after a safety delay, security-flagged changelogs fast-tracked, majors left for humans. Policy keeps working even if the hub is unreachable.
 * Version:           2.1.1
 * Author:            Ashford Creative
 * License:           GPL-2.0+
 * Requires at least: 6.0
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ASH_GUARDIAN_VERSION', '2.1.1' );
define( 'ASH_GUARDIAN_FILE', __FILE__ );
define( 'ASH_GUARDIAN_DIR', plugin_dir_path( __FILE__ ) );

/*
 * Guardian Hub integration (optional). Everything under includes/ is
 * additive: it emits events to a hub the operator pairs to, but the
 * auto-update policy engine below has zero dependency on any of it and
 * keeps working exactly as before if the hub is never configured or is
 * unreachable.
 */
foreach (
	array(
		'class-ashford-guardian-crypto.php',
		'class-ashford-guardian-hub-settings.php',
		'class-ashford-guardian-event-queue.php',
		'class-ashford-guardian-hub-client.php',
		'class-ashford-guardian-held-releases.php',
		'class-ashford-guardian-inventory.php',
		'class-ashford-guardian-commands.php',
		'class-ashford-guardian-actor-capture.php',
		'class-ashford-guardian-hub.php',
	) as $ash_guardian_include
) {
	require_once ASH_GUARDIAN_DIR . 'includes/' . $ash_guardian_include;
}

/*
 * GitHub-powered updates.
 *
 * Sites check the GitHub repo for new releases and surface them as normal
 * WordPress plugin updates (visible in wp-admin and ManageWP).
 *
 * Set the repo below (or define ASH_GUARDIAN_GITHUB_REPO in wp-config.php).
 * For a private repo, define ASH_GUARDIAN_GITHUB_TOKEN with a read-only
 * fine-grained personal access token.
 */
if ( file_exists( ASH_GUARDIAN_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php' ) ) {
	require_once ASH_GUARDIAN_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';

	$ash_guardian_repo = defined( 'ASH_GUARDIAN_GITHUB_REPO' )
		? ASH_GUARDIAN_GITHUB_REPO
		: 'https://github.com/ashfordcreative/guardian/';

	$ash_guardian_updates = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		$ash_guardian_repo,
		ASH_GUARDIAN_FILE,
		'ashford-guardian'
	);
	$ash_guardian_updates->getVcsApi()->enableReleaseAssets();

	if ( defined( 'ASH_GUARDIAN_GITHUB_TOKEN' ) && ASH_GUARDIAN_GITHUB_TOKEN ) {
		$ash_guardian_updates->setAuthentication( ASH_GUARDIAN_GITHUB_TOKEN );
	}
}

final class Ashford_Guardian {

	const CRON_HOOK      = 'ashford_guardian_hourly_tick';
	const OPT_FIRST_SEEN = 'ag_first_seen';   // "slug|version" => unix timestamp update was first observed
	const OPT_SECURITY   = 'ag_security_flag'; // "slug|version" => 1|0 changelog security detection cache
	const OPT_LOG           = 'ag_log';
	const OPT_SETTINGS      = 'ag_settings';
	const OPT_NOTIFY_Q      = 'ag_notify_queue';
	const OPT_UPDATE_BLOCKS = 'ag_update_blocks'; // slug => blocked/failed update issue
	const LOG_MAX           = 300;

	/** Changelog phrases that mark a release as security-related. */
	const SECURITY_KEYWORDS = array(
		'security', 'vulnerab', 'xss', 'cross-site scripting', 'csrf',
		'sql injection', 'sqli', 'rce', 'remote code execution',
		'privilege escalation', 'cve-', 'exploit', 'object injection',
		'arbitrary file', 'authentication bypass', 'sensitive data',
	);

	private static $instance = null;
	private $settings        = null;

	/** True while wp_maybe_auto_update() is running inside our own tick(). */
	private static $in_policy_tick = false;

	public static function is_policy_tick() {
		return self::$in_policy_tick;
	}

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

		add_action( self::CRON_HOOK, array( $this, 'tick' ) );
		add_filter( 'auto_update_plugin', array( $this, 'decide' ), 20, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'log_completed_updates' ), 10, 2 );
		add_action( 'automatic_updates_complete', array( $this, 'on_automatic_updates_complete' ) );

		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_post_ag_check_updates', array( $this, 'handle_check_updates' ) );
		add_action( 'admin_post_ag_run_now', array( $this, 'handle_run_now' ) );
		add_action( 'admin_post_ag_save_settings', array( $this, 'handle_save_settings' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	public function get_settings() {
		if ( null === $this->settings ) {
			$this->settings = wp_parse_args(
				get_option( self::OPT_SETTINGS, array() ),
				array(
					'patch_delay_days'  => 0,   // x.y.Z bumps: immediate
					'minor_delay_days'  => 3,   // x.Y.z bumps: wait for ecosystem fallout
					'allow_major'       => 0,   // X.y.z bumps: manual by default
					'major_delay_days'  => 7,
					'security_fast'     => 1,   // changelog says "security" => skip the delay (patch/minor)
					'email_notify'      => 1,
					'denylist'          => '',  // one slug per line, never auto-updated
				)
			);
		}
		return $this->settings;
	}

	private function get_denylist() {
		$s    = $this->get_settings();
		$list = array_filter( array_map( 'trim', explode( "\n", (string) $s['denylist'] ) ) );

		/**
		 * Filters the effective denylist. Used by the hub integration to
		 * merge in slugs held via a `hold_release` command, without this
		 * class needing to know anything about the hub.
		 */
		return apply_filters( 'ashford_guardian_denylist', $list );
	}

	/* ------------------------------------------------------------------ */
	/* Lifecycle                                                           */
	/* ------------------------------------------------------------------ */

	public function activate() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + wp_rand( 120, 3000 ), 'hourly', self::CRON_HOOK );
		}
	}

	public function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/* ------------------------------------------------------------------ */
	/* Hourly tick: observe updates, then let core run auto-updates        */
	/* ------------------------------------------------------------------ */

	public function tick() {
		$this->refresh_and_observe();

		// Hand off to core. Our decide() filter approves or holds each one.
		if ( function_exists( 'wp_maybe_auto_update' ) && ! wp_installing() ) {
			self::$in_policy_tick = true;
			wp_maybe_auto_update();
			self::$in_policy_tick = false;
		}

		/**
		 * Fires after each policy tick. Used by the hub integration to
		 * piggyback an agent.checkin without needing its own hourly cron.
		 * Purely additive — nothing here affects the policy engine itself.
		 */
		do_action( 'ashford_guardian_after_tick' );
	}

	/**
	 * Refresh plugin update data and record first-seen timestamps.
	 * Does not apply updates.
	 */
	private function refresh_and_observe() {
		wp_update_plugins();

		$updates = $this->get_pending_updates();
		$seen    = get_option( self::OPT_FIRST_SEEN, array() );
		$now     = time();
		$fresh   = array();

		foreach ( $updates as $slug => $u ) {
			$key = $slug . '|' . $u['new_version'];
			if ( ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = $now;
				$fresh[]      = $slug . ' → ' . $u['new_version'];
			}
		}

		// Prune entries for updates that no longer exist (superseded or applied).
		$valid_keys = array();
		foreach ( $updates as $slug => $u ) {
			$valid_keys[] = $slug . '|' . $u['new_version'];
		}
		$seen = array_intersect_key( $seen, array_flip( $valid_keys ) );
		update_option( self::OPT_FIRST_SEEN, $seen, false );

		if ( $fresh ) {
			$this->log( 'observe', 'New updates available: ' . implode( ', ', $fresh ) );
		}

		$this->sync_license_blocks( $updates );
		$this->maybe_notify_blocked_updates();
	}

	/**
	 * Public accessor for the hub's inventory builder. Same data as
	 * get_pending_updates(), kept private for the policy engine itself.
	 */
	public function get_pending_updates_public() {
		return $this->get_pending_updates();
	}

	/**
	 * Public accessor for the hub's inventory builder.
	 */
	public function evaluate_update_public( $slug, $info ) {
		return $this->evaluate_update( $slug, $info );
	}

	/**
	 * Active license-blocked / failed update issues for UI + inventory.
	 *
	 * @return array<string, array{reason:string,message:string,version:string,name:string,since:int,notified:int}>
	 */
	public function get_update_blocks_public() {
		return $this->get_update_blocks();
	}

	/**
	 * True when the update object has no downloadable package (typical expired license).
	 *
	 * @param object|null $item Update transient item.
	 */
	public static function package_is_missing( $item ) {
		if ( ! is_object( $item ) ) {
			return true;
		}
		if ( ! isset( $item->package ) ) {
			return true;
		}
		$package = $item->package;
		return ( '' === $package || false === $package || null === $package );
	}

	/**
	 * Pending plugin updates: slug => [file, current, new_version, item].
	 */
	private function get_pending_updates() {
		$transient = get_site_transient( 'update_plugins' );
		$out       = array();
		if ( empty( $transient->response ) ) {
			return $out;
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$installed = get_plugins();
		foreach ( $transient->response as $file => $item ) {
			$slug         = $item->slug ?? ( strpos( $file, '/' ) !== false ? dirname( $file ) : basename( $file, '.php' ) );
			$out[ $slug ] = array(
				'file'        => $file,
				'name'        => $installed[ $file ]['Name'] ?? $slug,
				'current'     => $installed[ $file ]['Version'] ?? '0',
				'new_version' => $item->new_version ?? '',
				'item'        => $item,
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* The policy engine                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Classify a version bump: 'patch', 'minor', 'major', or 'unknown'.
	 * Handles 2-part versions (1.4 → treated as major.minor).
	 */
	public function classify( $current, $new ) {
		$c = $this->version_parts( $current );
		$n = $this->version_parts( $new );
		if ( null === $c || null === $n ) {
			return 'unknown';
		}
		if ( $n[0] !== $c[0] ) {
			return 'major';
		}
		if ( $n[1] !== $c[1] ) {
			return 'minor';
		}
		return 'patch';
	}

	private function version_parts( $v ) {
		// Strip suffixes like -beta1; require a leading numeric segment.
		if ( ! preg_match( '/^(\d+)(?:\.(\d+))?(?:\.(\d+))?/', trim( (string) $v ), $m ) ) {
			return null;
		}
		return array( (int) $m[1], (int) ( $m[2] ?? 0 ), (int) ( $m[3] ?? 0 ) );
	}

	/**
	 * Core asks: "should plugin X auto-update?" Policy answer.
	 */
	public function decide( $update, $item ) {
		if ( empty( $item->slug ) || empty( $item->new_version ) ) {
			return $update;
		}

		// Hard block for denylisted plugins, even if auto-updates enabled elsewhere.
		if ( in_array( $item->slug, $this->get_denylist(), true ) ) {
			return false;
		}

		// No package URL — typically a premium updater with an inactive/expired license.
		if ( self::package_is_missing( $item ) ) {
			return false;
		}

		$pending = $this->get_pending_updates();
		if ( empty( $pending[ $item->slug ] ) ) {
			return $update;
		}

		$s       = $this->get_settings();
		$info    = $pending[ $item->slug ];
		$type    = $this->classify( $info['current'], $item->new_version );
		$age     = $this->update_age_days( $item->slug, $item->new_version );
		$is_sec  = $s['security_fast'] ? $this->is_security_release( $item->slug, $item->new_version, $item ) : false;

		// Security fast-track: patch or minor bump with a security-flagged changelog goes now.
		if ( $is_sec && in_array( $type, array( 'patch', 'minor' ), true ) ) {
			$this->queue_log( 'approve', sprintf( 'Approved %s %s → %s (security release, fast-tracked).', $item->slug, $info['current'], $item->new_version ) );
			return true;
		}

		switch ( $type ) {
			case 'patch':
				if ( $age >= (int) $s['patch_delay_days'] ) {
					$this->queue_log( 'approve', sprintf( 'Approved %s %s → %s (patch release).', $item->slug, $info['current'], $item->new_version ) );
					return true;
				}
				return $update;

			case 'minor':
				if ( $age >= (int) $s['minor_delay_days'] ) {
					$this->queue_log( 'approve', sprintf( 'Approved %s %s → %s (minor release, %d-day delay elapsed).', $item->slug, $info['current'], $item->new_version, (int) $s['minor_delay_days'] ) );
					return true;
				}
				return $update;

			case 'major':
				if ( $s['allow_major'] && $age >= (int) $s['major_delay_days'] ) {
					$this->queue_log( 'approve', sprintf( 'Approved %s %s → %s (major release, %d-day delay elapsed).', $item->slug, $info['current'], $item->new_version, (int) $s['major_delay_days'] ) );
					return true;
				}
				return $update; // manual territory

			default:
				return $update; // unparseable version scheme — leave to defaults
		}
	}

	private function update_age_days( $slug, $version ) {
		$seen = get_option( self::OPT_FIRST_SEEN, array() );
		$key  = $slug . '|' . $version;
		if ( empty( $seen[ $key ] ) ) {
			return 0;
		}
		return ( time() - (int) $seen[ $key ] ) / DAY_IN_SECONDS;
	}

	/**
	 * Does the changelog for this release mention security? (wordpress.org data only;
	 * premium plugins fall back to the upgrade notice or version heuristic.)
	 * Result cached per slug|version.
	 */
	public function is_security_release( $slug, $version, $item = null ) {
		$cache = get_option( self::OPT_SECURITY, array() );
		$key   = $slug . '|' . $version;
		if ( isset( $cache[ $key ] ) ) {
			return (bool) $cache[ $key ];
		}

		$haystack = '';

		// 1) Upgrade notice shipped with the update data itself (works for some premium plugins too).
		if ( $item && ! empty( $item->upgrade_notice ) ) {
			$haystack .= ' ' . $item->upgrade_notice;
		}

		// 2) wordpress.org changelog section for this version.
		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}
		$api = plugins_api(
			'plugin_information',
			array(
				'slug'   => $slug,
				'fields' => array( 'sections' => true ),
			)
		);
		if ( ! is_wp_error( $api ) && ! empty( $api->sections['changelog'] ) ) {
			$changelog = wp_strip_all_tags( $api->sections['changelog'] );
			// Try to isolate this version's entry; otherwise take the top of the changelog.
			$pos = strpos( $changelog, $version );
			$haystack .= ' ' . ( false !== $pos
				? substr( $changelog, $pos, 1500 )
				: substr( $changelog, 0, 1500 ) );
		}

		$haystack = strtolower( $haystack );
		$flag     = 0;
		foreach ( self::SECURITY_KEYWORDS as $kw ) {
			if ( false !== strpos( $haystack, $kw ) ) {
				$flag = 1;
				break;
			}
		}

		$cache[ $key ] = $flag;
		update_option( self::OPT_SECURITY, array_slice( $cache, -200, null, true ), false );
		return (bool) $flag;
	}

	/* ------------------------------------------------------------------ */
	/* Blocked / failed updates (license + auto-update failures)           */
	/* ------------------------------------------------------------------ */

	/**
	 * @return array<string, array{reason:string,message:string,version:string,name:string,since:int,notified:int}>
	 */
	private function get_update_blocks() {
		$blocks = get_option( self::OPT_UPDATE_BLOCKS, array() );
		return is_array( $blocks ) ? $blocks : array();
	}

	/**
	 * Record or refresh a blocked/failed update. Resets notified when reason
	 * or target version changes so a new digest can fire.
	 *
	 * @return bool True when a new/changed issue was stored.
	 */
	private function record_update_block( $slug, $reason, $message, $version = '', $name = '' ) {
		$slug    = (string) $slug;
		$reason  = (string) $reason;
		$version = (string) $version;
		$blocks  = $this->get_update_blocks();
		$existing = isset( $blocks[ $slug ] ) ? $blocks[ $slug ] : null;

		if (
			$existing
			&& (string) ( $existing['reason'] ?? '' ) === $reason
			&& (string) ( $existing['version'] ?? '' ) === $version
		) {
			return false;
		}

		$entry = array(
			'reason'   => $reason,
			'message'  => (string) $message,
			'version'  => $version,
			'name'     => $name ? (string) $name : (string) ( $existing['name'] ?? $slug ),
			'since'    => time(),
			'notified' => 0,
		);
		$blocks[ $slug ] = $entry;
		update_option( self::OPT_UPDATE_BLOCKS, $blocks, false );

		$this->log(
			'blocked',
			sprintf(
				'%s: %s%s',
				$slug,
				$message,
				$version ? ' (→ ' . $version . ')' : ''
			)
		);

		/**
		 * Fires when a new or changed blocked/failed update is recorded.
		 * Hub integration emits update.blocked from this hook.
		 *
		 * @param string $slug  Plugin slug.
		 * @param array  $entry Block record.
		 */
		do_action( 'ashford_guardian_update_blocked', $slug, $entry );

		return true;
	}

	private function clear_update_block( $slug ) {
		$blocks = $this->get_update_blocks();
		if ( ! isset( $blocks[ $slug ] ) ) {
			return;
		}
		unset( $blocks[ $slug ] );
		update_option( self::OPT_UPDATE_BLOCKS, $blocks, false );
	}

	/**
	 * Mark pending updates with an empty package as license-blocked; clear
	 * resolved license/failed issues when a package becomes available.
	 *
	 * @param array<string, array> $updates From get_pending_updates().
	 */
	private function sync_license_blocks( array $updates ) {
		foreach ( $updates as $slug => $u ) {
			$item = $u['item'] ?? null;
			if ( self::package_is_missing( $item ) ) {
				$this->record_update_block(
					$slug,
					'license',
					'Update available but no download — check license.',
					(string) ( $u['new_version'] ?? '' ),
					(string) ( $u['name'] ?? $slug )
				);
				continue;
			}

			$blocks = $this->get_update_blocks();
			if ( ! isset( $blocks[ $slug ] ) ) {
				continue;
			}
			// Usable package again — clear license or prior failed attempts.
			if ( in_array( $blocks[ $slug ]['reason'] ?? '', array( 'license', 'failed' ), true ) ) {
				$this->clear_update_block( $slug );
			}
		}

		foreach ( $this->get_update_blocks() as $slug => $block ) {
			if ( 'license' === ( $block['reason'] ?? '' ) && ! isset( $updates[ $slug ] ) ) {
				$this->clear_update_block( $slug );
			}
		}
	}

	/**
	 * @param array|mixed $results From WP_Automatic_Updater::$update_results.
	 */
	private function record_auto_update_failures( $results ) {
		if ( empty( $results['plugin'] ) || ! is_array( $results['plugin'] ) ) {
			return;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$installed = get_plugins();

		foreach ( $results['plugin'] as $row ) {
			$row = (object) $row;
			if ( true === ( $row->result ?? null ) ) {
				continue;
			}

			$item = isset( $row->item ) ? $row->item : null;
			$file = '';
			if ( is_object( $item ) && ! empty( $item->plugin ) ) {
				$file = (string) $item->plugin;
			} elseif ( is_object( $item ) && ! empty( $item->file ) ) {
				$file = (string) $item->file;
			}

			$slug = '';
			if ( is_object( $item ) && ! empty( $item->slug ) ) {
				$slug = (string) $item->slug;
			} elseif ( $file ) {
				$slug = strpos( $file, '/' ) !== false ? dirname( $file ) : basename( $file, '.php' );
			}
			if ( '' === $slug ) {
				continue;
			}

			$message = is_wp_error( $row->result )
				? $row->result->get_error_message()
				: 'Automatic update failed.';
			$version = is_object( $item ) ? (string) ( $item->new_version ?? '' ) : '';
			$name    = (string) ( $row->name ?? ( $installed[ $file ]['Name'] ?? $slug ) );

			$this->record_update_block( $slug, 'failed', $message, $version, $name );
		}
	}

	/**
	 * One digest for newly recorded blocked/failed updates (notified=0).
	 * Same issue is not re-mailed until reason or target version changes.
	 */
	private function maybe_notify_blocked_updates() {
		if ( ! $this->get_settings()['email_notify'] ) {
			return;
		}

		$blocks = $this->get_update_blocks();
		$fresh  = array();
		foreach ( $blocks as $slug => $block ) {
			if ( empty( $block['notified'] ) ) {
				$fresh[ $slug ] = $block;
			}
		}
		if ( empty( $fresh ) ) {
			return;
		}

		$host  = wp_parse_url( home_url(), PHP_URL_HOST );
		$lines = array();
		foreach ( $fresh as $slug => $block ) {
			$label   = ! empty( $block['name'] ) ? $block['name'] : $slug;
			$reason  = 'license' === ( $block['reason'] ?? '' ) ? 'license' : 'failed';
			$version = ! empty( $block['version'] ) ? ' → ' . $block['version'] : '';
			$lines[] = sprintf(
				'- %s (%s)%s: %s',
				$label,
				$reason,
				$version,
				(string) ( $block['message'] ?? '' )
			);
		}

		wp_mail(
			get_option( 'admin_email' ),
			sprintf( '[%s] Guardian: %d plugin update(s) blocked', $host, count( $fresh ) ),
			"Ashford Guardian could not apply these plugin updates:\n\n"
			. implode( "\n", $lines )
			. "\n\nSite: " . home_url()
			. "\nTime: " . current_time( 'mysql' )
			. "\nLog: " . admin_url( 'tools.php?page=ashford-guardian' )
		);

		foreach ( array_keys( $fresh ) as $slug ) {
			$blocks[ $slug ]['notified'] = 1;
		}
		update_option( self::OPT_UPDATE_BLOCKS, $blocks, false );
	}

	/* ------------------------------------------------------------------ */
	/* Logging + notification                                              */
	/* ------------------------------------------------------------------ */

	private function log( $type, $message ) {
		$log   = get_option( self::OPT_LOG, array() );
		$log[] = array(
			'time'    => current_time( 'mysql' ),
			'type'    => $type,
			'message' => $message,
		);
		update_option( self::OPT_LOG, array_slice( $log, -self::LOG_MAX ), false );
	}

	/**
	 * decide() runs many times per update cycle; dedupe log lines per key.
	 */
	private function queue_log( $type, $message ) {
		static $done = array();
		if ( isset( $done[ $message ] ) ) {
			return;
		}
		$done[ $message ] = true;
		$this->log( $type, $message );
	}

	public function log_completed_updates( $upgrader, $hook_extra ) {
		if ( ( $hook_extra['type'] ?? '' ) !== 'plugin' ) {
			return;
		}
		$plugins = array();
		if ( ! empty( $hook_extra['plugins'] ) ) {
			$plugins = (array) $hook_extra['plugins'];
		} elseif ( ! empty( $hook_extra['plugin'] ) ) {
			$plugins = array( $hook_extra['plugin'] );
		}

		$queue = get_option( self::OPT_NOTIFY_Q, array() );
		foreach ( $plugins as $file ) {
			$slug = strpos( $file, '/' ) !== false ? dirname( $file ) : basename( $file, '.php' );
			$this->log( 'updated', sprintf( 'Updated: %s.', $slug ) );
			$queue[] = $slug;
			$this->clear_update_block( $slug );
		}
		update_option( self::OPT_NOTIFY_Q, array_unique( $queue ), false );
	}

	/**
	 * After core finishes an automatic update run: capture failures, then
	 * send success + blocked digests as needed.
	 *
	 * @param array $results WP_Automatic_Updater::$update_results.
	 */
	public function on_automatic_updates_complete( $results ) {
		$this->record_auto_update_failures( $results );
		$this->flush_notify_queue();
		$this->maybe_notify_blocked_updates();
	}

	/**
	 * One digest email per auto-update run (not one per plugin).
	 */
	public function flush_notify_queue() {
		$queue = get_option( self::OPT_NOTIFY_Q, array() );
		if ( empty( $queue ) ) {
			return;
		}
		update_option( self::OPT_NOTIFY_Q, array(), false );

		if ( ! $this->get_settings()['email_notify'] ) {
			return;
		}
		wp_mail(
			get_option( 'admin_email' ),
			sprintf( '[%s] Guardian auto-updated %d plugin(s)', wp_parse_url( home_url(), PHP_URL_HOST ), count( $queue ) ),
			"Ashford Guardian applied updates per policy:\n\n- " . implode( "\n- ", $queue ) . "\n\nSite: " . home_url() . "\nTime: " . current_time( 'mysql' ) . "\nLog: " . admin_url( 'tools.php?page=ashford-guardian' )
		);
	}

	/* ------------------------------------------------------------------ */
	/* Admin UI                                                            */
	/* ------------------------------------------------------------------ */

	public function admin_menu() {
		add_management_page( 'Ashford Guardian', 'Guardian', 'manage_options', 'ashford-guardian', array( $this, 'render_admin_page' ) );
	}

	public function enqueue_admin_assets( $hook ) {
		if ( 'tools_page_ashford-guardian' !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'ashford-guardian-admin',
			plugins_url( 'assets/css/admin.css', ASH_GUARDIAN_FILE ),
			array(),
			ASH_GUARDIAN_VERSION
		);
	}

	/**
	 * Evaluate a pending update for display: type, age, decision label, status bucket.
	 *
	 * @return array{type:string,age:float,is_sec:bool,is_license:bool,decision:string,status:string}
	 */
	private function evaluate_update( $slug, $info ) {
		$s       = $this->get_settings();
		$type    = $this->classify( $info['current'], $info['new_version'] );
		$age     = $this->update_age_days( $slug, $info['new_version'] );
		$is_sec  = $s['security_fast'] ? $this->is_security_release( $slug, $info['new_version'], $info['item'] ) : false;
		$denied  = in_array( $slug, $this->get_denylist(), true );

		if ( self::package_is_missing( $info['item'] ?? null ) ) {
			return array(
				'type'       => $type,
				'age'        => $age,
				'is_sec'     => $is_sec,
				'is_license' => true,
				'decision'   => 'Update available but no download — check license',
				'status'     => 'block',
			);
		}

		if ( $denied ) {
			return array(
				'type'       => $type,
				'age'        => $age,
				'is_sec'     => $is_sec,
				'is_license' => false,
				'decision'   => 'Denylisted — manual only',
				'status'     => 'block',
			);
		}

		if ( $is_sec && in_array( $type, array( 'patch', 'minor' ), true ) ) {
			return array(
				'type'       => $type,
				'age'        => $age,
				'is_sec'     => true,
				'is_license' => false,
				'decision'   => 'Security release — updating now',
				'status'     => 'due',
			);
		}

		if ( 'patch' === $type ) {
			$due = $age >= (int) $s['patch_delay_days'];
			return array(
				'type'       => $type,
				'age'        => $age,
				'is_sec'     => $is_sec,
				'is_license' => false,
				'decision'   => $due ? 'Updating now' : 'Scheduled',
				'status'     => $due ? 'due' : 'wait',
			);
		}

		if ( 'minor' === $type ) {
			$due = $age >= (int) $s['minor_delay_days'];
			return array(
				'type'       => $type,
				'age'        => $age,
				'is_sec'     => $is_sec,
				'is_license' => false,
				'decision'   => $due
					? 'Updating now'
					: sprintf( 'Waiting (%.1f of %d days)', $age, (int) $s['minor_delay_days'] ),
				'status'     => $due ? 'due' : 'wait',
			);
		}

		if ( 'major' === $type ) {
			if ( $s['allow_major'] ) {
				$due = $age >= (int) $s['major_delay_days'];
				return array(
					'type'       => $type,
					'age'        => $age,
					'is_sec'     => $is_sec,
					'is_license' => false,
					'decision'   => $due
						? 'Updating now'
						: sprintf( 'Waiting (%.1f of %d days)', $age, (int) $s['major_delay_days'] ),
					'status'     => $due ? 'due' : 'wait',
				);
			}
			return array(
				'type'       => $type,
				'age'        => $age,
				'is_sec'     => $is_sec,
				'is_license' => false,
				'decision'   => 'Major — manual',
				'status'     => 'manual',
			);
		}

		return array(
			'type'       => $type,
			'age'        => $age,
			'is_sec'     => $is_sec,
			'is_license' => false,
			'decision'   => 'Unrecognized versioning — manual',
			'status'     => 'manual',
		);
	}

	public function handle_check_updates() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ag_check_updates' ) ) {
			wp_die( 'Not allowed.' );
		}
		$this->refresh_and_observe();
		wp_safe_redirect( admin_url( 'tools.php?page=ashford-guardian&checked=1' ) );
		exit;
	}

	public function handle_run_now() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ag_run_now' ) ) {
			wp_die( 'Not allowed.' );
		}
		$this->tick();
		wp_safe_redirect( admin_url( 'tools.php?page=ashford-guardian&ran=1' ) );
		exit;
	}

	public function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ag_save_settings' ) ) {
			wp_die( 'Not allowed.' );
		}
		$settings = array(
			'patch_delay_days' => max( 0, (int) ( $_POST['ag_patch_delay'] ?? 0 ) ),
			'minor_delay_days' => max( 0, (int) ( $_POST['ag_minor_delay'] ?? 3 ) ),
			'allow_major'      => empty( $_POST['ag_allow_major'] ) ? 0 : 1,
			'major_delay_days' => max( 0, (int) ( $_POST['ag_major_delay'] ?? 7 ) ),
			'security_fast'    => empty( $_POST['ag_security_fast'] ) ? 0 : 1,
			'email_notify'     => empty( $_POST['ag_email_notify'] ) ? 0 : 1,
			'denylist'         => sanitize_textarea_field( wp_unslash( $_POST['ag_denylist'] ?? '' ) ),
		);
		update_option( self::OPT_SETTINGS, $settings );
		wp_safe_redirect( admin_url( 'tools.php?page=ashford-guardian&saved=1' ) );
		exit;
	}

	public function render_admin_page() {
		$s       = $this->get_settings();
		$pending = $this->get_pending_updates();
		$blocks  = $this->get_update_blocks();
		$log     = array_reverse( get_option( self::OPT_LOG, array() ) );

		$evaluated = array();
		$counts    = array(
			'pending' => 0,
			'due'     => 0,
			'wait'    => 0,
			'manual'  => 0,
			'blocked' => count( $blocks ),
		);
		foreach ( $pending as $slug => $info ) {
			$ev                 = $this->evaluate_update( $slug, $info );
			$evaluated[ $slug ] = $ev;
			$counts['pending']++;
			if ( ! empty( $ev['is_license'] ) ) {
				// License-blocked pending rows are counted via $blocks, not Manual.
				continue;
			}
			if ( 'due' === $ev['status'] ) {
				$counts['due']++;
			} elseif ( 'wait' === $ev['status'] ) {
				$counts['wait']++;
			} elseif ( 'block' === $ev['status'] ) {
				// Denylist — treat as manual hold for the strip.
				$counts['manual']++;
			} else {
				$counts['manual']++;
			}
		}

		// Failed (or license) issues no longer advertised in update_plugins.
		$orphan_blocks = array();
		foreach ( $blocks as $slug => $block ) {
			if ( ! isset( $pending[ $slug ] ) ) {
				$orphan_blocks[ $slug ] = $block;
			}
		}

		$major_label = $s['allow_major']
			? sprintf( 'auto after %d day(s)', (int) $s['major_delay_days'] )
			: 'manual';
		$summary     = sprintf(
			'Patch after %d day(s) · minor after %d day(s) · majors %s%s',
			(int) $s['patch_delay_days'],
			(int) $s['minor_delay_days'],
			$major_label,
			$s['security_fast'] ? ' · security fast-tracked' : ''
		);

		$check_url = wp_nonce_url( admin_url( 'admin-post.php?action=ag_check_updates' ), 'ag_check_updates' );
		$run_url   = wp_nonce_url( admin_url( 'admin-post.php?action=ag_run_now' ), 'ag_run_now' );
		?>
		<div class="wrap ag-app">
			<header class="ag-header">
				<div class="ag-brand">
					<h1 class="ag-brand__mark">Guardian</h1>
					<p class="ag-brand__summary"><?php echo esc_html( $summary ); ?></p>
				</div>
				<div class="ag-header__actions">
					<?php if ( $counts['due'] > 0 ) : ?>
						<a class="ag-btn ag-btn--secondary" href="<?php echo esc_url( $run_url ); ?>">Apply due updates</a>
					<?php endif; ?>
					<a class="ag-btn ag-btn--primary" href="<?php echo esc_url( $check_url ); ?>">Check for updates</a>
				</div>
			</header>

			<?php if ( ! empty( $_GET['checked'] ) ) : ?>
				<div class="ag-notice">Update check complete — pending list refreshed.</div>
			<?php elseif ( ! empty( $_GET['ran'] ) ) : ?>
				<div class="ag-notice">Policy run finished — due updates were handed to WordPress.</div>
			<?php elseif ( ! empty( $_GET['saved'] ) ) : ?>
				<div class="ag-notice">Policy saved.</div>
			<?php endif; ?>

			<div class="ag-status" role="group" aria-label="Update status">
				<div class="ag-status__item">
					<span class="ag-status__label">Pending</span>
					<span class="ag-status__value"><?php echo (int) $counts['pending']; ?></span>
				</div>
				<div class="ag-status__item ag-status__item--due">
					<span class="ag-status__label">Due now</span>
					<span class="ag-status__value"><?php echo (int) $counts['due']; ?></span>
				</div>
				<div class="ag-status__item ag-status__item--wait">
					<span class="ag-status__label">Waiting</span>
					<span class="ag-status__value"><?php echo (int) $counts['wait']; ?></span>
				</div>
				<div class="ag-status__item ag-status__item--manual">
					<span class="ag-status__label">Manual</span>
					<span class="ag-status__value"><?php echo (int) $counts['manual']; ?></span>
				</div>
				<div class="ag-status__item ag-status__item--blocked">
					<span class="ag-status__label">Blocked</span>
					<span class="ag-status__value"><?php echo (int) $counts['blocked']; ?></span>
				</div>
			</div>

			<section class="ag-section">
				<div class="ag-section__head">
					<h2 class="ag-section__title">Updates</h2>
				</div>
				<div class="ag-updates">
					<?php if ( empty( $pending ) ) : ?>
						<div class="ag-empty">
							<p class="ag-empty__title">All clear</p>
							<p class="ag-empty__text">No plugin updates waiting. Check again anytime.</p>
						</div>
					<?php else : ?>
						<?php foreach ( $pending as $slug => $info ) :
							$ev = $evaluated[ $slug ];
							$decision_class = 'ag-update__decision--' . ( 'block' === $ev['status'] ? 'block' : $ev['status'] );
							?>
							<article class="ag-update">
								<div>
									<p class="ag-update__name"><?php echo esc_html( $info['name'] ); ?></p>
									<p class="ag-update__slug"><?php echo esc_html( $slug ); ?> · seen <?php echo esc_html( number_format( $ev['age'], 1 ) ); ?>d ago</p>
								</div>
								<div class="ag-update__versions">
									<?php echo esc_html( $info['current'] ); ?>
									→ <strong><?php echo esc_html( $info['new_version'] ); ?></strong>
								</div>
								<div class="ag-update__meta">
									<span class="ag-chip ag-chip--<?php echo esc_attr( $ev['type'] ); ?>"><?php echo esc_html( $ev['type'] ); ?></span>
									<?php if ( ! empty( $ev['is_license'] ) ) : ?>
										<span class="ag-chip ag-chip--license">license</span>
									<?php endif; ?>
									<?php if ( $ev['is_sec'] ) : ?>
										<span class="ag-chip ag-chip--security">security</span>
									<?php endif; ?>
								</div>
								<div class="ag-update__decision <?php echo esc_attr( $decision_class ); ?>">
									<?php echo esc_html( $ev['decision'] ); ?>
								</div>
							</article>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>

				<?php if ( ! empty( $orphan_blocks ) ) : ?>
					<div class="ag-section__head" style="margin-top:16px">
						<h3 class="ag-section__title">Blocked updates</h3>
					</div>
					<div class="ag-updates ag-updates--blocked">
						<?php foreach ( $orphan_blocks as $slug => $block ) :
							$reason_label = 'license' === ( $block['reason'] ?? '' ) ? 'license' : 'failed';
							$since        = ! empty( $block['since'] ) ? human_time_diff( (int) $block['since'] ) . ' ago' : '';
							?>
							<article class="ag-update">
								<div>
									<p class="ag-update__name"><?php echo esc_html( $block['name'] ?? $slug ); ?></p>
									<p class="ag-update__slug"><?php echo esc_html( $slug ); ?><?php echo $since ? ' · ' . esc_html( $since ) : ''; ?></p>
								</div>
								<div class="ag-update__versions">
									<?php if ( ! empty( $block['version'] ) ) : ?>
										→ <strong><?php echo esc_html( $block['version'] ); ?></strong>
									<?php else : ?>
										—
									<?php endif; ?>
								</div>
								<div class="ag-update__meta">
									<span class="ag-chip ag-chip--<?php echo esc_attr( $reason_label ); ?>"><?php echo esc_html( $reason_label ); ?></span>
								</div>
								<div class="ag-update__decision ag-update__decision--block">
									<?php echo esc_html( $block['message'] ?? 'Update blocked.' ); ?>
								</div>
							</article>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</section>

			<section class="ag-section">
				<div class="ag-section__head">
					<h2 class="ag-section__title">Policy</h2>
				</div>
				<form class="ag-policy" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'ag_save_settings' ); ?>
					<input type="hidden" name="action" value="ag_save_settings" />

					<div class="ag-field">
						<div class="ag-field__label">
							Patch releases
							<span class="ag-field__hint">x.y.Z bumps</span>
						</div>
						<div class="ag-field__control">
							Apply after
							<input class="ag-input ag-input--num" type="number" name="ag_patch_delay" min="0" max="30" value="<?php echo (int) $s['patch_delay_days']; ?>" />
							day(s). <span class="ag-field__hint" style="display:inline;margin:0">0 = immediately</span>
						</div>
					</div>

					<div class="ag-field">
						<div class="ag-field__label">
							Minor releases
							<span class="ag-field__hint">x.Y.z bumps</span>
						</div>
						<div class="ag-field__control">
							Apply after
							<input class="ag-input ag-input--num" type="number" name="ag_minor_delay" min="0" max="30" value="<?php echo (int) $s['minor_delay_days']; ?>" />
							day(s)
						</div>
					</div>

					<div class="ag-field">
						<div class="ag-field__label">
							Major releases
							<span class="ag-field__hint">X.y.z bumps</span>
						</div>
						<div class="ag-field__control">
							<label class="ag-check">
								<input type="checkbox" name="ag_allow_major" value="1" <?php checked( $s['allow_major'], 1 ); ?> />
								<span>Auto-apply after</span>
							</label>
							<input class="ag-input ag-input--num" type="number" name="ag_major_delay" min="0" max="60" value="<?php echo (int) $s['major_delay_days']; ?>" />
							day(s). Off = always manual.
						</div>
					</div>

					<div class="ag-field">
						<div class="ag-field__label">Security fast-track</div>
						<div class="ag-field__control">
							<label class="ag-check">
								<input type="checkbox" name="ag_security_fast" value="1" <?php checked( $s['security_fast'], 1 ); ?> />
								<span>Skip the delay when the changelog mentions a security fix (patch and minor only)</span>
							</label>
						</div>
					</div>

					<div class="ag-field">
						<div class="ag-field__label">
							Never auto-update
							<span class="ag-field__hint">One slug per line</span>
						</div>
						<div class="ag-field__control ag-field__control--stack">
							<textarea class="ag-textarea" id="ag_denylist" name="ag_denylist" rows="4"><?php echo esc_textarea( $s['denylist'] ); ?></textarea>
							<span class="ag-field__hint">Hard block — overrides everything, including auto-updates enabled elsewhere.</span>
						</div>
					</div>

					<div class="ag-field">
						<div class="ag-field__label">Email</div>
						<div class="ag-field__control">
							<label class="ag-check">
								<input type="checkbox" name="ag_email_notify" value="1" <?php checked( $s['email_notify'], 1 ); ?> />
								<span>Send digests for applied updates and newly blocked/failed updates</span>
							</label>
						</div>
					</div>

					<div class="ag-policy__footer">
						<button type="submit" class="ag-btn ag-btn--primary">Save policy</button>
					</div>
				</form>
			</section>

			<?php
			/**
			 * Fires after the Policy section, before Activity. The hub
			 * integration hooks in here to render its own pairing/status
			 * section without this file needing to know anything about it.
			 */
			do_action( 'ashford_guardian_admin_sections', $s );
			?>

			<section class="ag-section">
				<div class="ag-section__head">
					<h2 class="ag-section__title">Activity</h2>
				</div>
				<div class="ag-log">
					<?php if ( empty( $log ) ) : ?>
						<p class="ag-log__empty">No activity yet.</p>
					<?php else : ?>
						<?php foreach ( array_slice( $log, 0, 100 ) as $entry ) : ?>
							<div class="ag-log__row">
								<span class="ag-log__time"><?php echo esc_html( $entry['time'] ); ?></span>
								<span class="ag-log__type ag-log__type--<?php echo esc_attr( $entry['type'] ); ?>"><?php echo esc_html( $entry['type'] ); ?></span>
								<p class="ag-log__msg"><?php echo esc_html( $entry['message'] ); ?></p>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
			</section>
		</div>
		<?php
	}
}

Ashford_Guardian::instance();
Ashford_Guardian_Hub::instance();
