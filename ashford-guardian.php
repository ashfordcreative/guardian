<?php
/**
 * Plugin Name:       Ashford Guardian
 * Plugin URI:        https://ashfordcreative.com
 * Description:       Self-contained smart auto-updates. Patch releases apply immediately, minor releases after a safety delay, security-flagged changelogs fast-tracked, majors left for humans. No external services. Full log for client reporting.
 * Version:           2.0.1
 * Author:            Ashford Creative
 * License:           GPL-2.0+
 * Requires at least: 6.0
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ASH_GUARDIAN_VERSION', '2.0.1' );
define( 'ASH_GUARDIAN_FILE', __FILE__ );
define( 'ASH_GUARDIAN_DIR', plugin_dir_path( __FILE__ ) );

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
	const OPT_LOG        = 'ag_log';
	const OPT_SETTINGS   = 'ag_settings';
	const OPT_NOTIFY_Q   = 'ag_notify_queue';
	const LOG_MAX        = 300;

	/** Changelog phrases that mark a release as security-related. */
	const SECURITY_KEYWORDS = array(
		'security', 'vulnerab', 'xss', 'cross-site scripting', 'csrf',
		'sql injection', 'sqli', 'rce', 'remote code execution',
		'privilege escalation', 'cve-', 'exploit', 'object injection',
		'arbitrary file', 'authentication bypass', 'sensitive data',
	);

	private static $instance = null;
	private $settings        = null;

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
		add_action( 'automatic_updates_complete', array( $this, 'flush_notify_queue' ) );

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
		$s = $this->get_settings();
		return array_filter( array_map( 'trim', explode( "\n", (string) $s['denylist'] ) ) );
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
			wp_maybe_auto_update();
		}
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
		}
		update_option( self::OPT_NOTIFY_Q, array_unique( $queue ), false );
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
			'ashford-guardian-fonts',
			'https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,560;0,9..40,650;1,9..40,400&family=Instrument+Serif:ital@0;1&display=swap',
			array(),
			null
		);
		wp_enqueue_style(
			'ashford-guardian-admin',
			plugins_url( 'assets/css/admin.css', ASH_GUARDIAN_FILE ),
			array( 'ashford-guardian-fonts' ),
			ASH_GUARDIAN_VERSION
		);
	}

	/**
	 * Evaluate a pending update for display: type, age, decision label, status bucket.
	 *
	 * @return array{type:string,age:float,is_sec:bool,decision:string,status:string}
	 */
	private function evaluate_update( $slug, $info ) {
		$s       = $this->get_settings();
		$type    = $this->classify( $info['current'], $info['new_version'] );
		$age     = $this->update_age_days( $slug, $info['new_version'] );
		$is_sec  = $s['security_fast'] ? $this->is_security_release( $slug, $info['new_version'], $info['item'] ) : false;
		$denied  = in_array( $slug, $this->get_denylist(), true );

		if ( $denied ) {
			return array(
				'type'     => $type,
				'age'      => $age,
				'is_sec'   => $is_sec,
				'decision' => 'Denylisted — manual only',
				'status'   => 'block',
			);
		}

		if ( $is_sec && in_array( $type, array( 'patch', 'minor' ), true ) ) {
			return array(
				'type'     => $type,
				'age'      => $age,
				'is_sec'   => true,
				'decision' => 'Security release — updating now',
				'status'   => 'due',
			);
		}

		if ( 'patch' === $type ) {
			$due = $age >= (int) $s['patch_delay_days'];
			return array(
				'type'     => $type,
				'age'      => $age,
				'is_sec'   => $is_sec,
				'decision' => $due ? 'Updating now' : 'Scheduled',
				'status'   => $due ? 'due' : 'wait',
			);
		}

		if ( 'minor' === $type ) {
			$due = $age >= (int) $s['minor_delay_days'];
			return array(
				'type'     => $type,
				'age'      => $age,
				'is_sec'   => $is_sec,
				'decision' => $due
					? 'Updating now'
					: sprintf( 'Waiting (%.1f of %d days)', $age, (int) $s['minor_delay_days'] ),
				'status'   => $due ? 'due' : 'wait',
			);
		}

		if ( 'major' === $type ) {
			if ( $s['allow_major'] ) {
				$due = $age >= (int) $s['major_delay_days'];
				return array(
					'type'     => $type,
					'age'      => $age,
					'is_sec'   => $is_sec,
					'decision' => $due
						? 'Updating now'
						: sprintf( 'Waiting (%.1f of %d days)', $age, (int) $s['major_delay_days'] ),
					'status'   => $due ? 'due' : 'wait',
				);
			}
			return array(
				'type'     => $type,
				'age'      => $age,
				'is_sec'   => $is_sec,
				'decision' => 'Major — manual',
				'status'   => 'manual',
			);
		}

		return array(
			'type'     => $type,
			'age'      => $age,
			'is_sec'   => $is_sec,
			'decision' => 'Unrecognized versioning — manual',
			'status'   => 'manual',
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
		$log     = array_reverse( get_option( self::OPT_LOG, array() ) );

		$evaluated = array();
		$counts    = array(
			'pending' => 0,
			'due'     => 0,
			'wait'    => 0,
			'manual'  => 0,
		);
		foreach ( $pending as $slug => $info ) {
			$ev               = $this->evaluate_update( $slug, $info );
			$evaluated[ $slug ] = $ev;
			$counts['pending']++;
			if ( 'due' === $ev['status'] ) {
				$counts['due']++;
			} elseif ( 'wait' === $ev['status'] ) {
				$counts['wait']++;
			} else {
				$counts['manual']++;
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
								<span>Send one digest email per update run</span>
							</label>
						</div>
					</div>

					<div class="ag-policy__footer">
						<button type="submit" class="ag-btn ag-btn--primary">Save policy</button>
					</div>
				</form>
			</section>

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
