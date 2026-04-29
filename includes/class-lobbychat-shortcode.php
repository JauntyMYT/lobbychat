<?php
/**
 * LobbyChat — Shortcode and frontend asset enqueue
 *
 * @package LobbyChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LobbyChat_Shortcode {

	public static function init() {
		add_shortcode( 'lobbychat', [ __CLASS__, 'render' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'register' ] );
	}

	/**
	 * Register frontend assets AND localize data.
	 *
	 * We localize during wp_enqueue_scripts (not inside the shortcode render)
	 * so the LobbyChat global is always defined before lobbychat.js runs,
	 * even on themes that print scripts in the header or that defer them.
	 */
	public static function register() {
		wp_register_style(
			'lobbychat',
			LBC_URL . 'assets/css/lobbychat.css',
			[],
			LBC_VERSION
		);
		wp_register_script(
			'lobbychat',
			LBC_URL . 'assets/js/lobbychat.js',
			[ 'jquery' ],
			LBC_VERSION,
			true
		);

		$uid  = get_current_user_id();
		$user = $uid ? get_userdata( $uid ) : null;

		wp_localize_script( 'lobbychat', 'LobbyChat', [
			'ajax_url'      => admin_url( 'admin-ajax.php' ),
			'nonce'         => wp_create_nonce( 'lobbychat_nonce' ),
			'user_id'       => $uid,
			'user_name'     => $user ? $user->display_name : '',
			'logged_in'     => (bool) $uid,
			'is_mod'        => $uid && (
				current_user_can( 'manage_options' )
				|| current_user_can( 'lobbychat_moderate' )
				|| ( $user && in_array( 'lobbychat_moderator', (array) $user->roles, true ) )
			),
			'avatar'        => $uid ? get_avatar_url( $uid, [ 'size' => 32, 'default' => 'identicon' ] ) : '',
			'login_url'     => wp_login_url(),
			'allow_guests'  => (int) get_option( 'lobbychat_allow_guests', 1 ),
			'allow_links'   => (int) get_option( 'lobbychat_allow_links', 1 ),
			'max_length'    => (int) get_option( 'lobbychat_max_length', 500 ),
			'poll_interval' => (int) get_option( 'lobbychat_poll_interval', 30000 ),
			'rate_logged'   => (int) get_option( 'lobbychat_rate_logged', 5 ),
			'rate_guest'    => (int) get_option( 'lobbychat_rate_guest',  15 ),
			'i18n'          => [
				'loading'        => __( 'Loading…', 'lobbychat' ),
				'send'           => __( 'Send', 'lobbychat' ),
				'sending'        => __( 'Sending…', 'lobbychat' ),
				'wait'           => __( 'Wait', 'lobbychat' ),
				'guest_name'     => __( 'Your name (guest)', 'lobbychat' ),
				'placeholder'    => __( 'Say something…', 'lobbychat' ),
				'link_share'     => __( 'Share a link', 'lobbychat' ),
				'link_url_ph'    => __( 'Paste a URL…', 'lobbychat' ),
				'remove_link'    => __( 'Remove link', 'lobbychat' ),
				'collapse'       => __( 'Collapse', 'lobbychat' ),
				'expand'         => __( 'Expand', 'lobbychat' ),
				'fullscreen'     => __( 'Fullscreen', 'lobbychat' ),
				'sound_toggle'   => __( 'Toggle sound', 'lobbychat' ),
				'login'          => __( 'Login', 'lobbychat' ),
				'profile'        => __( 'My Profile', 'lobbychat' ),
				'reply'          => __( 'Reply', 'lobbychat' ),
				'react'          => __( 'React', 'lobbychat' ),
				'report'         => __( 'Report', 'lobbychat' ),
				'delete_label'   => __( 'Delete', 'lobbychat' ),
				'pin_label'      => __( 'Pin', 'lobbychat' ),
				'pinned'         => __( 'Pinned.', 'lobbychat' ),
				'unpinned'       => __( 'Unpinned.', 'lobbychat' ),
				'confirm_delete' => __( 'Delete this message?', 'lobbychat' ),
				'confirm_report' => __( 'Report this message?', 'lobbychat' ),
				'confirm_pin'    => __( 'Pin this message?', 'lobbychat' ),
				'reported'       => __( 'Reported. Thanks.', 'lobbychat' ),
				'already_reported' => __( 'Already reported.', 'lobbychat' ),
				'type_first'     => __( 'Type a message first.', 'lobbychat' ),
				'enter_name'     => __( 'Enter your name.', 'lobbychat' ),
				'send_error'     => __( 'Error sending message.', 'lobbychat' ),
				'network_error'  => __( 'Network error. Try again.', 'lobbychat' ),
				'load_error'     => __( 'Could not load messages.', 'lobbychat' ),
				'no_messages'    => __( 'No messages yet — be the first to say something!', 'lobbychat' ),
				'members'        => __( 'Members', 'lobbychat' ),
				'guests'         => __( 'Guests', 'lobbychat' ),
				'bots'           => __( 'Bots', 'lobbychat' ),
				'online'         => __( 'online', 'lobbychat' ),
				'waiting'        => __( 'Waiting for activity…', 'lobbychat' ),
				'sound_on'       => __( 'Sound on', 'lobbychat' ),
				'sound_off'      => __( 'Sound off', 'lobbychat' ),
				'exit_fs'        => __( 'Exit fullscreen', 'lobbychat' ),
			],
		] );
	}

	public static function render( $atts ) {
		// Only enqueue when the shortcode is actually used on this page.
		wp_enqueue_style( 'lobbychat' );
		wp_enqueue_script( 'lobbychat' );

		ob_start();
		include LBC_DIR . 'templates/lobbychat.php';
		return ob_get_clean();
	}
}
