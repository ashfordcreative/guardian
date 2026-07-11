<?php
/**
 * Plugin Name:       Ashford Guardian
 * Plugin URI:        https://ashfordcreative.com
 * Description:       Self-contained smart auto-updates. Patch releases apply immediately, minor releases after a safety delay, security-flagged changelogs fast-tracked, majors left for humans. No external services. Full log for client reporting.
 * Version:           2.0.0
 * Author:            Ashford Creative
 * License:           GPL-2.0+
 * Requires at least: 6.0
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optional: GitHub-based updates for this plugin itself.
 * Drop the plugin-update-checker library into /plugin-update-checker
 * and set your repo URL below.
 */
if ( file_exists( __DIR__ . '/plugin-update-checker/plugin-update-checker.php' ) ) {
	require __DIR__ . '/plugin-update-checker/plugin-update-checker.php';
	if ( class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
		\YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			'https://github.com/YOURNAME/ashford-guardian/', // <-- change me
			__FILE__,
			'ashford-guardian'
		);
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
		wp_update_plugins(); // refresh update data

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

		// Hand off to core. Our decide() filter approves or holds each one.
		if ( function_exists( 'wp_maybe_auto_update' ) && ! wp_installing() ) {
			wp_maybe_auto_update();
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
		?>
		<div class="wrap">
			<h1>Ashford Guardian</h1>
			<p>
				Self-contained update policy: patch releases apply after <?php echo (int) $s['patch_delay_days']; ?> day(s), minor after <?php echo (int) $s['minor_delay_days']; ?> day(s), majors are <?php echo $s['allow_major'] ? 'auto after ' . (int) $s['major_delay_days'] . ' day(s)' : 'manual'; ?>. Security-flagged changelogs are fast-tracked.
				&nbsp;<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ag_run_now' ), 'ag_run_now' ) ); ?>">Check &amp; run now</a>
			</p>

			<h2>Pending updates</h2>
			<?php if ( empty( $pending ) ) : ?>
				<p>✅ Everything is up to date.</p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:1000px">
					<thead><tr><th>Plugin</th><th>Installed</th><th>Available</th><th>Type</th><th>Seen (days ago)</th><th>Decision</th></tr></thead>
					<tbody>
					<?php foreach ( $pending as $slug => $info ) :
						$type   = $this->classify( $info['current'], $info['new_version'] );
						$age    = $this->update_age_days( $slug, $info['new_version'] );
						$is_sec = $s['security_fast'] ? $this->is_security_release( $slug, $info['new_version'], $info['item'] ) : false;
						$denied = in_array( $slug, $this->get_denylist(), true );

						if ( $denied ) {
							$decision = 'Denylisted — manual only';
						} elseif ( $is_sec && in_array( $type, array( 'patch', 'minor' ), true ) ) {
							$decision = 'Security release — updating now';
						} elseif ( 'patch' === $type ) {
							$decision = $age >= $s['patch_delay_days'] ? 'Updating now' : 'Scheduled';
						} elseif ( 'minor' === $type ) {
							$decision = $age >= $s['minor_delay_days'] ? 'Updating now' : sprintf( 'Waiting (%.1f of %d days)', $age, $s['minor_delay_days'] );
						} elseif ( 'major' === $type ) {
							$decision = $s['allow_major'] ? ( $age >= $s['major_delay_days'] ? 'Updating now' : sprintf( 'Waiting (%.1f of %d days)', $age, $s['major_delay_days'] ) ) : 'Major — manual';
						} else {
							$decision = 'Unrecognized versioning — manual';
						}
					?>
						<tr>
							<td><?php echo esc_html( $info['name'] ); ?></td>
							<td><?php echo esc_html( $info['current'] ); ?></td>
							<td><?php echo esc_html( $info['new_version'] ); ?></td>
							<td><?php echo esc_html( $type . ( $is_sec ? ' · security' : '' ) ); ?></td>
							<td><?php echo esc_html( number_format( $age, 1 ) ); ?></td>
							<td><?php echo esc_html( $decision ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2>Policy settings</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:1000px">
				<?php wp_nonce_field( 'ag_save_settings' ); ?>
				<input type="hidden" name="action" value="ag_save_settings" />
				<table class="form-table">
					<tr>
						<th scope="row">Patch releases (x.y.<strong>Z</strong>)</th>
						<td>Apply after <input type="number" name="ag_patch_delay" min="0" max="30" value="<?php echo (int) $s['patch_delay_days']; ?>" style="width:60px" /> day(s). <span class="description">0 = immediately.</span></td>
					</tr>
					<tr>
						<th scope="row">Minor releases (x.<strong>Y</strong>.z)</th>
						<td>Apply after <input type="number" name="ag_minor_delay" min="0" max="30" value="<?php echo (int) $s['minor_delay_days']; ?>" style="width:60px" /> day(s).</td>
					</tr>
					<tr>
						<th scope="row">Major releases (<strong>X</strong>.y.z)</th>
						<td>
							<label><input type="checkbox" name="ag_allow_major" value="1" <?php checked( $s['allow_major'], 1 ); ?> /> Auto-apply after</label>
							<input type="number" name="ag_major_delay" min="0" max="60" value="<?php echo (int) $s['major_delay_days']; ?>" style="width:60px" /> day(s). <span class="description">Off = majors always manual.</span>
						</td>
					</tr>
					<tr>
						<th scope="row">Security fast-track</th>
						<td><label><input type="checkbox" name="ag_security_fast" value="1" <?php checked( $s['security_fast'], 1 ); ?> /> Skip the delay when the release changelog/upgrade notice mentions a security fix (patch and minor releases only)</label></td>
					</tr>
					<tr>
						<th scope="row"><label for="ag_denylist">Never auto-update (one slug per line)</label></th>
						<td>
							<textarea id="ag_denylist" name="ag_denylist" rows="4" class="large-text code"><?php echo esc_textarea( $s['denylist'] ); ?></textarea>
							<p class="description">Hard block — overrides everything, including auto-updates enabled elsewhere.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Email notifications</th>
						<td><label><input type="checkbox" name="ag_email_notify" value="1" <?php checked( $s['email_notify'], 1 ); ?> /> Send one digest email per update run</label></td>
					</tr>
				</table>
				<?php submit_button( 'Save policy' ); ?>
			</form>

			<h2>Activity log</h2>
			<table class="widefat striped" style="max-width:1000px">
				<thead><tr><th style="width:170px">Time</th><th style="width:90px">Type</th><th>Message</th></tr></thead>
				<tbody>
				<?php if ( empty( $log ) ) : ?>
					<tr><td colspan="3">No activity yet.</td></tr>
				<?php else : ?>
					<?php foreach ( array_slice( $log, 0, 100 ) as $entry ) : ?>
						<tr>
							<td><?php echo esc_html( $entry['time'] ); ?></td>
							<td><?php echo esc_html( $entry['type'] ); ?></td>
							<td><?php echo esc_html( $entry['message'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}

Ashford_Guardian::instance();
