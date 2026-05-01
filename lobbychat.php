<?php
/**
 * Plugin Name:       LobbyChat
 * Plugin URI:        https://bejaunty.com/plugins/lobbychat
 * Description:       A live, casual shoutbox for your community. Real-time messages, emoji reactions, link previews, moderator tools, and an optional AI chat companion. No third-party dependencies.
 * Version:           1.0.2
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Author:            Asad Khalil
 * Author URI:        https://profiles.wordpress.org/jauntymellifluous/
 * Donate link:       https://wise.com/pay/me/asadk372
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lobbychat
 *
 * @package LobbyChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LBC_VERSION', '1.0.2' );
define( 'LBC_FILE',    __FILE__ );
define( 'LBC_DIR',     plugin_dir_path( __FILE__ ) );
define( 'LBC_URL',     plugin_dir_url( __FILE__ ) );
define( 'LBC_DONATE_URL', 'https://wise.com/pay/me/asadk372' );

require_once LBC_DIR . 'includes/class-lobbychat-db.php';
require_once LBC_DIR . 'includes/class-lobbychat-ajax.php';
require_once LBC_DIR . 'includes/class-lobbychat-shortcode.php';
require_once LBC_DIR . 'includes/class-lobbychat-bot.php';
if ( is_admin() ) {
	require_once LBC_DIR . 'includes/class-lobbychat-admin.php';
}

/* ─── Activation ─────────────────────────────────────────────────── */

register_activation_hook( __FILE__, [ 'LobbyChat_DB', 'install' ] );
register_activation_hook( __FILE__, 'lobbychat_register_role' );
register_activation_hook( __FILE__, 'lobbychat_schedule_cron' );
register_deactivation_hook( __FILE__, 'lobbychat_clear_cron' );

/**
 * Register the custom moderator role and grant the cap to admins.
 * Idempotent — safe to run on every activation.
 */
function lobbychat_register_role() {
	if ( ! get_role( 'lobbychat_moderator' ) ) {
		add_role(
			'lobbychat_moderator',
			__( 'LobbyChat Moderator', 'lobbychat' ),
			[
				'read'               => true,
				'lobbychat_moderate' => true,
			]
		);
	}
	$admin = get_role( 'administrator' );
	if ( $admin && ! $admin->has_cap( 'lobbychat_moderate' ) ) {
		$admin->add_cap( 'lobbychat_moderate' );
	}
}

/**
 * Schedule the daily cleanup cron event.
 */
function lobbychat_schedule_cron() {
	if ( ! wp_next_scheduled( 'lobbychat_daily_cleanup' ) ) {
		wp_schedule_event( time() + 3600, 'daily', 'lobbychat_daily_cleanup' );
	}
}

/**
 * Clear all scheduled events on deactivation.
 */
function lobbychat_clear_cron() {
	wp_clear_scheduled_hook( 'lobbychat_daily_cleanup' );
	wp_clear_scheduled_hook( 'lobbychat_bot_send_reply' );
}

/**
 * Daily cleanup callback — prunes old messages per user setting.
 */
add_action( 'lobbychat_daily_cleanup', 'lobbychat_run_daily_cleanup' );
function lobbychat_run_daily_cleanup() {
	$days = (int) get_option( 'lobbychat_prune_days', 30 );
	if ( $days > 0 ) {
		LobbyChat_DB::prune( $days );
	}
}

/* ─── Boot ───────────────────────────────────────────────────────── */

/**
 * Boot the plugin.
 */
function lobbychat_init() {
	LobbyChat_Ajax::init();
	LobbyChat_Shortcode::init();
	LobbyChat_Bot::init();
}
add_action( 'plugins_loaded', 'lobbychat_init' );
