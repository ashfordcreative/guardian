<?php
/**
 * Hooks WordPress core actions to emit actor.action events: logins,
 * post/page saves, plugin/theme/user changes. Every hook here only
 * queues an event locally (via Ashford_Guardian_Hub::emit) — it never
 * makes a network call itself, so it can't slow down or block the
 * action it's observing.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ashford_Guardian_Actor_Capture {

	public function __construct() {
		add_action( 'wp_login', array( $this, 'on_login' ), 10, 2 );

		add_action( 'save_post', array( $this, 'on_save_post' ), 10, 3 );

		add_action( 'activated_plugin', array( $this, 'on_plugin_activated' ), 10, 2 );
		add_action( 'deactivated_plugin', array( $this, 'on_plugin_deactivated' ), 10, 2 );

		add_action( 'switch_theme', array( $this, 'on_switch_theme' ), 10, 3 );

		add_action( 'user_register', array( $this, 'on_user_register' ), 10, 1 );
		add_action( 'profile_update', array( $this, 'on_profile_update' ), 10, 2 );
		add_action( 'delete_user', array( $this, 'on_delete_user' ), 10, 1 );
		add_action( 'set_user_role', array( $this, 'on_role_change' ), 10, 3 );
	}

	private function actor_from_user( $user ) {
		if ( $user instanceof WP_User ) {
			return array( 'kind' => 'user', 'id' => $user->user_email ?: $user->user_login );
		}
		$current = wp_get_current_user();
		if ( $current && $current->exists() ) {
			return array( 'kind' => 'user', 'id' => $current->user_email ?: $current->user_login );
		}
		return array( 'kind' => 'system', 'id' => 'wordpress' );
	}

	public function on_login( $user_login, $user ) {
		Ashford_Guardian_Hub::instance()->emit(
			'actor.action',
			'info',
			sprintf( '%s logged in.', $user_login ),
			array(
				'action' => 'user.login',
				'object' => array( 'user_login' => $user_login ),
			),
			$this->actor_from_user( $user )
		);
	}

	public function on_save_post( $post_id, $post, $update ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		$type_object = get_post_type_object( $post->post_type );
		if ( ! $type_object || ! $type_object->public ) {
			return;
		}
		if ( in_array( $post->post_status, array( 'auto-draft', 'trash' ), true ) ) {
			return;
		}

		Ashford_Guardian_Hub::instance()->emit(
			'actor.action',
			'info',
			sprintf( '%s %s "%s".', $update ? 'Updated' : 'Created', $post->post_type, $post->post_title ?: '(untitled)' ),
			array(
				'action' => 'content.saved',
				'object' => array(
					'post_id'   => $post_id,
					'post_type' => $post->post_type,
					'title'     => $post->post_title,
					'status'    => $post->post_status,
					'is_update' => (bool) $update,
				),
			),
			$this->actor_from_user( null )
		);
	}

	public function on_plugin_activated( $plugin, $network_wide ) {
		Ashford_Guardian_Hub::instance()->emit(
			'actor.action',
			'notice',
			sprintf( 'Activated plugin %s.', $plugin ),
			array(
				'action' => 'plugin.activated',
				'object' => array( 'plugin' => $plugin, 'network_wide' => (bool) $network_wide ),
			),
			$this->actor_from_user( null )
		);
	}

	public function on_plugin_deactivated( $plugin, $network_wide ) {
		Ashford_Guardian_Hub::instance()->emit(
			'actor.action',
			'notice',
			sprintf( 'Deactivated plugin %s.', $plugin ),
			array(
				'action' => 'plugin.deactivated',
				'object' => array( 'plugin' => $plugin, 'network_wide' => (bool) $network_wide ),
			),
			$this->actor_from_user( null )
		);
	}

	public function on_switch_theme( $new_name, $new_theme = null, $old_theme = null ) {
		Ashford_Guardian_Hub::instance()->emit(
			'actor.action',
			'notice',
			sprintf( 'Switched theme to %s.', $new_name ),
			array(
				'action' => 'theme.switched',
				'object' => array(
					'to'   => $new_name,
					'from' => ( $old_theme instanceof WP_Theme ) ? $old_theme->get( 'Name' ) : null,
				),
			),
			$this->actor_from_user( null )
		);
	}

	public function on_user_register( $user_id ) {
		$user = get_userdata( $user_id );
		Ashford_Guardian_Hub::instance()->emit(
			'actor.action',
			'notice',
			sprintf( 'New user account created: %s.', $user ? $user->user_login : $user_id ),
			array(
				'action' => 'user.created',
				'object' => array( 'user_id' => $user_id, 'user_login' => $user ? $user->user_login : null ),
			),
			$this->actor_from_user( null )
		);
	}

	public function on_profile_update( $user_id, $old_user_data ) {
		$user = get_userdata( $user_id );
		Ashford_Guardian_Hub::instance()->emit(
			'actor.action',
			'info',
			sprintf( 'User profile updated: %s.', $user ? $user->user_login : $user_id ),
			array(
				'action' => 'user.updated',
				'object' => array( 'user_id' => $user_id, 'user_login' => $user ? $user->user_login : null ),
			),
			$this->actor_from_user( null )
		);
	}

	public function on_delete_user( $user_id ) {
		$user = get_userdata( $user_id );
		Ashford_Guardian_Hub::instance()->emit(
			'actor.action',
			'notice',
			sprintf( 'User account deleted: %s.', $user ? $user->user_login : $user_id ),
			array(
				'action' => 'user.deleted',
				'object' => array( 'user_id' => $user_id, 'user_login' => $user ? $user->user_login : null ),
			),
			$this->actor_from_user( null )
		);
	}

	public function on_role_change( $user_id, $role, $old_roles ) {
		$user = get_userdata( $user_id );
		Ashford_Guardian_Hub::instance()->emit(
			'actor.action',
			'notice',
			sprintf( 'Role changed for %s: %s.', $user ? $user->user_login : $user_id, $role ),
			array(
				'action' => 'user.role_changed',
				'object' => array(
					'user_id'   => $user_id,
					'user_login'=> $user ? $user->user_login : null,
					'new_role'  => $role,
					'old_roles' => $old_roles,
				),
			),
			$this->actor_from_user( null )
		);
	}
}
