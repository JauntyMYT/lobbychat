<?php
/**
 * LobbyChat — Uninstall handler.
 *
 * Runs only when the user explicitly deletes the plugin from Plugins → Installed Plugins.
 * Drops tables, removes options, and deletes the custom role.
 *
 * @package LobbyChat
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop tables.
// Direct queries are necessary on uninstall — there's no WP API for DROP TABLE.
$lobbychat_tables = [
	$wpdb->prefix . 'lobbychat_messages',
	$wpdb->prefix . 'lobbychat_reports',
	$wpdb->prefix . 'lobbychat_online',
];
foreach ( $lobbychat_tables as $lobbychat_t ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS `{$lobbychat_t}`" );
}

// Delete options.
$lobbychat_options = [
	'lobbychat_max_length',
	'lobbychat_rate_logged',
	'lobbychat_rate_guest',
	'lobbychat_link_cooldown',
	'lobbychat_allow_guests',
	'lobbychat_allow_links',
	'lobbychat_poll_interval',
	'lobbychat_prune_days',
	'lobbychat_show_branding',
	'lobbychat_blocklist',
	'lobbychat_bot_enabled',
	'lobbychat_bot_user_id',
	'lobbychat_bot_name',
	'lobbychat_bot_gemini_key',
	'lobbychat_bot_gemini_model',
	'lobbychat_bot_openai_key',
	'lobbychat_bot_openai_model',
	'lobbychat_bot_random_chance',
	'lobbychat_bot_question_chance',
	'lobbychat_bot_reply_questions',
	'lobbychat_bot_cooldown',
	'lobbychat_bot_max_per_hour',
	'lobbychat_bot_max_per_day',
	'lobbychat_bot_active_hours',
	'lobbychat_bot_max_chars',
	'lobbychat_bot_custom_prompt',
	'lobbychat_bot_debug',
	'lobbychat_bot_last_reply',
	'lobbychat_bot_hourly_bucket',
	'lobbychat_bot_daily_bucket',
];
foreach ( $lobbychat_options as $lobbychat_opt ) {
	delete_option( $lobbychat_opt );
}

// Clear scheduled events.
wp_clear_scheduled_hook( 'lobbychat_bot_send_reply' );
wp_clear_scheduled_hook( 'lobbychat_daily_cleanup' );

// Remove the custom role.
remove_role( 'lobbychat_moderator' );

// Strip the custom cap from administrator.
$lobbychat_admin_role = get_role( 'administrator' );
if ( $lobbychat_admin_role ) {
	$lobbychat_admin_role->remove_cap( 'lobbychat_moderate' );
}
