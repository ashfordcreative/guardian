<?php
/**
 * Coordinates the hub integration: emitting events, flushing the local
 * queue with retry/backoff, processing piggybacked commands, capturing
 * update.applied, and the Tools → Guardian "Hub" admin section.
 *
 * Everything here is additive to Ashford_Guardian. If the hub is
 * unreachable, every method here fails soft — the policy engine (in
 * ashford-guardian.php) never depends on any of this working.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ashford_Guardian_Hub {

	const CRON_HOOK    = 'ashford_guardian_hub_flush';
	const CRON_SCHEDULE = 'ag_five_minutes';
	const OPT_HUB_LOG  = 'ag_hub_log';
	const LOG_MAX      = 200;
	const FLUSH_BATCHES_PER_RUN = 4;
	const BATCH_SIZE   = 25;

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		register_activation_hook( ASH_GUARDIAN_FILE, array( __CLASS__, 'activate' ) );

		add_filter( 'cron_schedules', array( $this, 'register_cron_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval

		add_action( 'init', array( $this, 'maybe_schedule_flush' ) );
		add_action( self::CRON_HOOK, array( $this, 'flush' ) );

		// Piggyback a check-in on the existing hourly policy tick so we don't
		// need a second cadence just for inventory snapshots.
		add_action( 'ashford_guardian_after_tick', array( $this, 'emit_checkin' ) );

		// Merge hub-held slugs into the policy engine's denylist. This is the
		// only point where hub state can affect auto-update decisions, and it
		// works entirely from local storage — no hub round-trip required.
		add_filter( 'ashford_guardian_denylist', array( $this, 'filter_denylist' ) );

		// Render the "Hub" section on Tools → Guardian.
		add_action( 'ashford_guardian_admin_sections', array( $this, 'render_admin_section' ) );

		add_action( 'admin_post_ag_hub_save_url', array( $this, 'handle_save_url' ) );
		add_action( 'admin_post_ag_hub_pair', array( $this, 'handle_pair' ) );
		add_action( 'admin_post_ag_hub_save_key', array( $this, 'handle_save_key' ) );
		add_action( 'admin_post_ag_hub_unpair', array( $this, 'handle_unpair' ) );
		add_action( 'admin_post_ag_hub_flush_now', array( $this, 'handle_flush_now' ) );

		// update.applied capture — independent of the policy engine's own logging.
		add_action( 'upgrader_pre_install', array( $this, 'snapshot_pre_update_version' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'on_upgrader_complete' ), 20, 2 );

		// One-shot update.blocked when the policy engine records a new issue.
		add_action( 'ashford_guardian_update_blocked', array( $this, 'on_update_blocked' ), 10, 2 );

		new Ashford_Guardian_Actor_Capture();
	}

	public static function activate() {
		Ashford_Guardian_Event_Queue::install();
	}

	public function register_cron_schedule( $schedules ) {
		$schedules[ self::CRON_SCHEDULE ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes (Ashford Guardian hub sync)', 'ashford-guardian' ),
		);
		return $schedules;
	}

	public function maybe_schedule_flush() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + wp_rand( 30, 240 ), self::CRON_SCHEDULE, self::CRON_HOOK );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/* ------------------------------------------------------------------ */
	/* Emitting events                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Build a full event envelope and push it onto the local queue.
	 * Never makes a network call — safe to use from any hook, at any time.
	 *
	 * @return string The generated event id.
	 */
	public function emit( $type, $severity, $summary, array $data = array(), $actor = null, $correlation_id = null, $resolves = null ) {
		$event = array(
			'id'          => 'evt_' . str_replace( '-', '', wp_generate_uuid4() ),
			'source'      => 'guardian-wp',
			'type'        => $type,
			'severity'    => $severity,
			'occurred_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'actor'       => $actor ?: array( 'kind' => 'system', 'id' => 'ashford-guardian' ),
			'summary'     => $summary,
			'data'        => $data,
			'evidence'    => array(),
		);
		if ( $correlation_id ) {
			$event['correlation_id'] = $correlation_id;
		}
		if ( $resolves ) {
			$event['resolves'] = $resolves;
		}

		Ashford_Guardian_Event_Queue::enqueue( $event );
		return $event['id'];
	}

	/**
	 * Emit an agent.checkin with the full component inventory.
	 */
	public function emit_checkin() {
		$components = Ashford_Guardian_Inventory::build();
		$this->emit(
			'agent.checkin',
			'info',
			sprintf( 'Check-in: %d component(s) inventoried.', count( $components ) ),
			array( 'components' => $components )
		);
		Ashford_Guardian_Hub_Settings::update( array( 'last_checkin_at' => time() ) );
	}

	/* ------------------------------------------------------------------ */
	/* Flushing the queue                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Send whatever's ready in the local queue, process any piggybacked
	 * commands, and stop cleanly (with backoff) if the hub is unreachable.
	 * Safe to call as often as you like — it's a no-op when nothing is due.
	 */
	public function flush() {
		if ( ! Ashford_Guardian_Hub_Settings::is_active() ) {
			return;
		}

		for ( $i = 0; $i < self::FLUSH_BATCHES_PER_RUN; $i++ ) {
			$rows = Ashford_Guardian_Event_Queue::get_batch( self::BATCH_SIZE );
			if ( empty( $rows ) ) {
				return;
			}

			$events = array();
			foreach ( $rows as $row ) {
				$decoded = json_decode( $row['payload'], true );
				if ( is_array( $decoded ) ) {
					$events[] = $decoded;
				}
			}

			$result = Ashford_Guardian_Hub_Client::send_events( $events );

			if ( is_wp_error( $result ) ) {
				Ashford_Guardian_Event_Queue::mark_failed( wp_list_pluck( $rows, 'id' ) );
				$this->on_flush_error( $result );
				return; // Hub unreachable — stop for this run, retry later.
			}

			Ashford_Guardian_Event_Queue::delete_ids( wp_list_pluck( $rows, 'id' ) );
			Ashford_Guardian_Hub_Settings::update(
				array(
					'last_flush_at' => time(),
					'last_flush_ok' => true,
					'last_error'    => '',
					'pairing_state' => Ashford_Guardian_Hub_Settings::STATE_ACTIVE,
				)
			);
			$this->log( 'flush', sprintf( 'Sent %d event(s), %d accepted, %d duplicate(s).', count( $events ), (int) ( $result['accepted'] ?? 0 ), count( $result['duplicates'] ?? array() ) ) );

			if ( ! empty( $result['commands'] ) && is_array( $result['commands'] ) ) {
				Ashford_Guardian_Commands::process( $result['commands'] );
			}

			if ( count( $rows ) < self::BATCH_SIZE ) {
				return; // drained the queue
			}
		}
	}

	private function on_flush_error( WP_Error $error ) {
		$code = $error->get_error_code();
		Ashford_Guardian_Hub_Settings::update(
			array(
				'last_flush_at' => time(),
				'last_flush_ok' => false,
				'last_error'    => $error->get_error_message(),
			)
		);
		// Distinguish "credentials rejected" from "hub unreachable" for the admin UI.
		if ( 0 === strpos( (string) $code, 'ag_hub_http_401' ) ) {
			Ashford_Guardian_Hub_Settings::update( array( 'pairing_state' => Ashford_Guardian_Hub_Settings::STATE_ERROR ) );
		}
		$this->log( 'error', sprintf( 'Flush failed: %s', $error->get_error_message() ) );
	}

	/* ------------------------------------------------------------------ */
	/* Policy engine integration (denylist only)                          */
	/* ------------------------------------------------------------------ */

	public function filter_denylist( array $denylist ) {
		return array_values( array_unique( array_merge( $denylist, Ashford_Guardian_Held_Releases::slugs() ) ) );
	}

	/* ------------------------------------------------------------------ */
	/* update.blocked capture                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * @param string $slug  Plugin slug.
	 * @param array  $entry Block record from Ashford_Guardian.
	 */
	public function on_update_blocked( $slug, $entry ) {
		$reason  = (string) ( $entry['reason'] ?? 'failed' );
		$version = (string) ( $entry['version'] ?? '' );
		$message = (string) ( $entry['message'] ?? 'Update blocked.' );

		$this->emit(
			'update.blocked',
			'warning',
			sprintf(
				'Blocked update for %s (%s)%s.',
				$slug,
				$reason,
				$version ? " → {$version}" : ''
			),
			array(
				'kind'    => 'plugin',
				'slug'    => $slug,
				'reason'  => $reason,
				'version' => $version,
				'message' => $message,
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* update.applied capture                                             */
	/* ------------------------------------------------------------------ */

	public function snapshot_pre_update_version( $response, $hook_extra ) {
		$type = $hook_extra['type'] ?? '';
		$map  = get_transient( 'ag_pre_update_versions' ) ?: array();

		if ( 'plugin' === $type && ! empty( $hook_extra['plugin'] ) ) {
			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$file = $hook_extra['plugin'];
			$path = WP_PLUGIN_DIR . '/' . $file;
			if ( file_exists( $path ) ) {
				$data              = get_plugin_data( $path, false, false );
				$map[ 'plugin:' . $file ] = $data['Version'] ?? '';
			}
		} elseif ( 'theme' === $type && ! empty( $hook_extra['theme'] ) ) {
			$stylesheet = $hook_extra['theme'];
			$theme      = wp_get_theme( $stylesheet );
			if ( $theme->exists() ) {
				$map[ 'theme:' . $stylesheet ] = $theme->get( 'Version' );
			}
		} elseif ( 'core' === $type ) {
			$map['core'] = get_bloginfo( 'version' );
		}

		set_transient( 'ag_pre_update_versions', $map, 15 * MINUTE_IN_SECONDS );
		return $response;
	}

	public function on_upgrader_complete( $upgrader, $hook_extra ) {
		$type = $hook_extra['type'] ?? '';
		if ( ! in_array( $type, array( 'plugin', 'theme', 'core' ), true ) ) {
			return;
		}

		$map            = get_transient( 'ag_pre_update_versions' ) ?: array();
		$correlation_id = 'batch_' . substr( md5( microtime() . wp_rand() ), 0, 12 );
		$is_migrator    = ! Ashford_Guardian::is_policy_tick();
		$actor          = $is_migrator ? $this->current_actor() : array( 'kind' => 'system', 'id' => 'ashford-guardian-policy' );

		if ( 'plugin' === $type ) {
			$files = array();
			if ( ! empty( $hook_extra['plugins'] ) ) {
				$files = (array) $hook_extra['plugins'];
			} elseif ( ! empty( $hook_extra['plugin'] ) ) {
				$files = array( $hook_extra['plugin'] );
			}
			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			foreach ( $files as $file ) {
				$slug = strpos( $file, '/' ) !== false ? dirname( $file ) : basename( $file, '.php' );
				$path = WP_PLUGIN_DIR . '/' . $file;
				$to   = file_exists( $path ) ? ( get_plugin_data( $path, false, false )['Version'] ?? '' ) : '';
				$from = $map[ 'plugin:' . $file ] ?? '';

				$this->emit(
					'update.applied',
					'notice',
					sprintf( 'Updated plugin %s%s.', $slug, ( $from && $to ) ? " ({$from} → {$to})" : '' ),
					array(
						'kind'        => 'plugin',
						'slug'        => $slug,
						'from'        => $from,
						'to'          => $to,
						'is_migrator' => $is_migrator,
					),
					$actor,
					$correlation_id
				);

				// When Guardian's policy treats this as a security release, also
				// emit a vuln.patched event so the hub Security surface can project it.
				$item = (object) array( 'slug' => $slug, 'new_version' => $to );
				if ( $to && Ashford_Guardian::instance()->is_security_release( $slug, $to, $item ) ) {
					$corr = 'vuln_' . $slug . '_' . preg_replace( '/[^a-zA-Z0-9._-]/', '', $to );
					$this->emit(
						'vuln.patched',
						'notice',
						sprintf( 'Security patch applied: %s %s.', $slug, $to ),
						array(
							'component' => $slug,
							'kind'      => 'plugin',
							'from'      => $from,
							'to'        => $to,
							'emergency' => $is_migrator ? false : true,
							'body'      => sprintf( 'Guardian applied a security-flagged release of %s (%s → %s).', $slug, $from ?: '?', $to ),
						),
						$actor,
						$corr
					);
				}
			}
		} elseif ( 'theme' === $type ) {
			$stylesheets = ! empty( $hook_extra['themes'] ) ? (array) $hook_extra['themes'] : ( ! empty( $hook_extra['theme'] ) ? array( $hook_extra['theme'] ) : array() );
			foreach ( $stylesheets as $stylesheet ) {
				$theme = wp_get_theme( $stylesheet );
				$to    = $theme->exists() ? $theme->get( 'Version' ) : '';
				$from  = $map[ 'theme:' . $stylesheet ] ?? '';

				$this->emit(
					'update.applied',
					'notice',
					sprintf( 'Updated theme %s%s.', $stylesheet, ( $from && $to ) ? " ({$from} → {$to})" : '' ),
					array(
						'kind'        => 'theme',
						'slug'        => $stylesheet,
						'from'        => $from,
						'to'          => $to,
						'is_migrator' => $is_migrator,
					),
					$actor,
					$correlation_id
				);
			}
		} elseif ( 'core' === $type ) {
			$from = $map['core'] ?? '';
			$to   = self::read_core_version_from_file();

			$this->emit(
				'update.applied',
				'notice',
				sprintf( 'Updated WordPress core%s.', ( $from && $to ) ? " ({$from} → {$to})" : '' ),
				array(
					'kind'        => 'core',
					'slug'        => 'wordpress',
					'from'        => $from,
					'to'          => $to,
					'is_migrator' => $is_migrator,
				),
				$actor,
				$correlation_id
			);
		}
	}

	/**
	 * Reads $wp_version straight out of wp-includes/version.php without
	 * re-declaring the constant/variable already loaded for this request —
	 * the in-memory value can be stale immediately after a core update.
	 */
	private static function read_core_version_from_file() {
		$file = ABSPATH . WPINC . '/version.php';
		if ( ! file_exists( $file ) ) {
			return get_bloginfo( 'version' );
		}
		$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents
		if ( $contents && preg_match( '/\$wp_version\s*=\s*\'([^\']+)\'/', $contents, $m ) ) {
			return $m[1];
		}
		return get_bloginfo( 'version' );
	}

	private function current_actor() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return array( 'kind' => 'system', 'id' => 'wp-cli' );
		}
		$user = wp_get_current_user();
		if ( $user && $user->exists() ) {
			return array( 'kind' => 'user', 'id' => $user->user_email ?: $user->user_login );
		}
		return array( 'kind' => 'system', 'id' => 'unknown' );
	}

	/* ------------------------------------------------------------------ */
	/* Hub activity log (separate from the policy log)                    */
	/* ------------------------------------------------------------------ */

	public function log( $type, $message ) {
		$log   = get_option( self::OPT_HUB_LOG, array() );
		$log[] = array(
			'time'    => current_time( 'mysql' ),
			'type'    => $type,
			'message' => $message,
		);
		update_option( self::OPT_HUB_LOG, array_slice( $log, -self::LOG_MAX ), false );
	}

	/* ------------------------------------------------------------------ */
	/* Admin: Tools → Guardian → Hub section                              */
	/* ------------------------------------------------------------------ */

	public function render_admin_section( $policy_settings ) {
		$hub      = Ashford_Guardian_Hub_Settings::get();
		$state    = Ashford_Guardian_Hub_Settings::get_pairing_state();
		$log      = array_reverse( get_option( self::OPT_HUB_LOG, array() ) );
		$held     = Ashford_Guardian_Held_Releases::all();
		$pending  = Ashford_Guardian_Event_Queue::pending_count();

		$badge_class = array(
			Ashford_Guardian_Hub_Settings::STATE_ACTIVE   => 'ag-chip--paired',
			Ashford_Guardian_Hub_Settings::STATE_PENDING  => 'ag-chip--pending',
			Ashford_Guardian_Hub_Settings::STATE_ERROR    => 'ag-chip--error',
			Ashford_Guardian_Hub_Settings::STATE_UNPAIRED => 'ag-chip--unpaired',
		);
		?>
		<section class="ag-section">
			<div class="ag-section__head">
				<h2 class="ag-section__title">Guardian Hub</h2>
				<span class="ag-chip <?php echo esc_attr( $badge_class[ $state ] ?? 'ag-chip--unpaired' ); ?>"><?php echo esc_html( Ashford_Guardian_Hub_Settings::state_label() ); ?></span>
			</div>

			<?php if ( ! empty( $_GET['hub_paired'] ) ) : ?>
				<div class="ag-notice">Pairing request sent. Approve this site in the hub, then paste the API key below.</div>
			<?php elseif ( ! empty( $_GET['hub_key_saved'] ) ) : ?>
				<div class="ag-notice">
					<?php echo Ashford_Guardian_Hub_Settings::is_active() ? 'API key saved and verified — this site is paired.' : 'API key saved, but the hub rejected it. Check the key and try again.'; ?>
				</div>
			<?php elseif ( ! empty( $_GET['hub_unpaired'] ) ) : ?>
				<div class="ag-notice">Unpaired. Local policy continues to run; nothing is sent to the hub until re-paired.</div>
			<?php elseif ( ! empty( $_GET['hub_flushed'] ) ) : ?>
				<div class="ag-notice">Queue flush triggered.</div>
			<?php endif; ?>

			<div class="ag-policy">
				<form class="ag-field" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'ag_hub_save_url' ); ?>
					<input type="hidden" name="action" value="ag_hub_save_url" />
					<div class="ag-field__label">
						Hub URL
						<span class="ag-field__hint">e.g. http://localhost:3000</span>
					</div>
					<div class="ag-field__control">
						<input class="ag-input" type="url" name="ag_hub_url" style="min-width:280px;padding:4px 8px;min-height:30px;" value="<?php echo esc_attr( $hub['hub_url'] ); ?>" placeholder="https://hub.example.com" />
						<button type="submit" class="ag-btn ag-btn--secondary">Save URL</button>
						<?php if ( '' !== $hub['hub_url'] && Ashford_Guardian_Hub_Settings::STATE_UNPAIRED === $state ) : ?>
							<a class="ag-btn ag-btn--primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ag_hub_pair' ), 'ag_hub_pair' ) ); ?>">Pair with hub</a>
						<?php endif; ?>
					</div>
				</form>

				<?php if ( Ashford_Guardian_Hub_Settings::STATE_UNPAIRED !== $state ) : ?>
					<div class="ag-field">
						<div class="ag-field__label">Site ID</div>
						<div class="ag-field__control"><code><?php echo esc_html( $hub['site_id'] ?: '—' ); ?></code></div>
					</div>
				<?php endif; ?>

				<?php if ( in_array( $state, array( Ashford_Guardian_Hub_Settings::STATE_PENDING, Ashford_Guardian_Hub_Settings::STATE_ERROR ), true ) || ( Ashford_Guardian_Hub_Settings::STATE_ACTIVE === $state ) ) : ?>
					<form class="ag-field" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'ag_hub_save_key' ); ?>
						<input type="hidden" name="action" value="ag_hub_save_key" />
						<div class="ag-field__label">
							API key
							<span class="ag-field__hint">Pasted after operator approval; shown once by the hub</span>
						</div>
						<div class="ag-field__control">
							<input class="ag-input" type="password" name="ag_hub_api_key" style="min-width:280px;padding:4px 8px;min-height:30px;" placeholder="<?php echo esc_attr( '' !== Ashford_Guardian_Hub_Settings::get_api_key() ? '•••••••••••••••••••• (saved)' : 'gh_...' ); ?>" autocomplete="off" />
							<button type="submit" class="ag-btn ag-btn--primary">Save &amp; verify</button>
						</div>
					</form>
				<?php endif; ?>

				<div class="ag-field">
					<div class="ag-field__label">Status</div>
					<div class="ag-field__control ag-field__control--stack">
						<span>Queued events waiting to send: <strong><?php echo (int) $pending; ?></strong></span>
						<span>Last check-in sent: <?php echo esc_html( $hub['last_checkin_at'] ? human_time_diff( $hub['last_checkin_at'] ) . ' ago' : 'never' ); ?></span>
						<span>Last flush: <?php echo esc_html( $hub['last_flush_at'] ? ( human_time_diff( $hub['last_flush_at'] ) . ' ago — ' . ( $hub['last_flush_ok'] ? 'ok' : 'failed' ) ) : 'never' ); ?></span>
						<?php if ( ! empty( $hub['last_error'] ) ) : ?>
							<span class="ag-field__hint" style="color:var(--ag-block)">Last error: <?php echo esc_html( $hub['last_error'] ); ?></span>
						<?php endif; ?>
						<?php if ( ! empty( $held ) ) : ?>
							<span>Held by hub command: <?php echo esc_html( implode( ', ', array_keys( $held ) ) ); ?></span>
						<?php endif; ?>
					</div>
				</div>

				<div class="ag-policy__footer" style="gap:8px">
					<a class="ag-btn ag-btn--secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ag_hub_flush_now' ), 'ag_hub_flush_now' ) ); ?>">Flush queue now</a>
					<?php if ( Ashford_Guardian_Hub_Settings::STATE_UNPAIRED !== $state ) : ?>
						<a class="ag-btn ag-btn--secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ag_hub_unpair' ), 'ag_hub_unpair' ) ); ?>" onclick="return confirm('Unpair this site? Local policy keeps running either way.');">Unpair</a>
					<?php endif; ?>
				</div>
			</div>
		</section>

		<section class="ag-section">
			<div class="ag-section__head">
				<h2 class="ag-section__title">Hub activity</h2>
			</div>
			<div class="ag-log">
				<?php if ( empty( $log ) ) : ?>
					<p class="ag-log__empty">No hub activity yet.</p>
				<?php else : ?>
					<?php foreach ( array_slice( $log, 0, 50 ) as $entry ) : ?>
						<div class="ag-log__row">
							<span class="ag-log__time"><?php echo esc_html( $entry['time'] ); ?></span>
							<span class="ag-log__type ag-log__type--<?php echo esc_attr( $entry['type'] ); ?>"><?php echo esc_html( $entry['type'] ); ?></span>
							<p class="ag-log__msg"><?php echo esc_html( $entry['message'] ); ?></p>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Admin: form handlers                                               */
	/* ------------------------------------------------------------------ */

	public function handle_save_url() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ag_hub_save_url' ) ) {
			wp_die( 'Not allowed.' );
		}
		$url = sanitize_text_field( wp_unslash( $_POST['ag_hub_url'] ?? '' ) );
		Ashford_Guardian_Hub_Settings::update( array( 'hub_url' => untrailingslashit( $url ) ) );
		wp_safe_redirect( admin_url( 'tools.php?page=ashford-guardian' ) );
		exit;
	}

	public function handle_pair() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ag_hub_pair' ) ) {
			wp_die( 'Not allowed.' );
		}
		$result = Ashford_Guardian_Hub_Client::pair();

		if ( is_wp_error( $result ) ) {
			Ashford_Guardian_Hub_Settings::update( array( 'pairing_state' => Ashford_Guardian_Hub_Settings::STATE_ERROR, 'last_error' => $result->get_error_message() ) );
			$this->log( 'error', 'Pairing failed: ' . $result->get_error_message() );
			wp_safe_redirect( admin_url( 'tools.php?page=ashford-guardian' ) );
			exit;
		}

		$status  = $result['status'] ?? 'pending';
		$site_id = $result['site_id'] ?? '';
		Ashford_Guardian_Hub_Settings::update(
			array(
				'site_id'       => $site_id,
				'pairing_state' => 'already_paired' === $status ? Ashford_Guardian_Hub_Settings::STATE_ACTIVE : Ashford_Guardian_Hub_Settings::STATE_PENDING,
				'last_pair_at'  => time(),
				'last_error'    => '',
			)
		);
		$this->log( 'pair', sprintf( 'Pairing request sent (site_id=%s, status=%s).', $site_id, $status ) );
		wp_safe_redirect( admin_url( 'tools.php?page=ashford-guardian&hub_paired=1' ) );
		exit;
	}

	public function handle_save_key() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ag_hub_save_key' ) ) {
			wp_die( 'Not allowed.' );
		}
		$key = trim( wp_unslash( $_POST['ag_hub_api_key'] ?? '' ) );
		if ( '' !== $key ) {
			Ashford_Guardian_Hub_Settings::set_api_key( $key );
			Ashford_Guardian_Hub_Settings::update( array( 'pairing_state' => Ashford_Guardian_Hub_Settings::STATE_ACTIVE, 'last_error' => '' ) );
		}

		// Verify immediately with a real check-in rather than trusting the paste blindly.
		$this->emit_checkin();
		$this->flush();

		$this->log( 'pair', Ashford_Guardian_Hub_Settings::is_active() ? 'API key verified against the hub.' : 'API key saved but not yet verified.' );
		wp_safe_redirect( admin_url( 'tools.php?page=ashford-guardian&hub_key_saved=1' ) );
		exit;
	}

	public function handle_unpair() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ag_hub_unpair' ) ) {
			wp_die( 'Not allowed.' );
		}
		$hub_url = Ashford_Guardian_Hub_Settings::get_hub_url();
		Ashford_Guardian_Hub_Settings::reset();
		Ashford_Guardian_Hub_Settings::update( array( 'hub_url' => $hub_url ) );
		$this->log( 'pair', 'Unpaired locally.' );
		wp_safe_redirect( admin_url( 'tools.php?page=ashford-guardian&hub_unpaired=1' ) );
		exit;
	}

	public function handle_flush_now() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ag_hub_flush_now' ) ) {
			wp_die( 'Not allowed.' );
		}
		$this->flush();
		wp_safe_redirect( admin_url( 'tools.php?page=ashford-guardian&hub_flushed=1' ) );
		exit;
	}
}
