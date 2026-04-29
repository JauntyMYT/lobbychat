<?php
/**
 * LobbyChat — Admin settings
 *
 * @package LobbyChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LobbyChat_Admin {

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_action( 'wp_ajax_lobbychat_bot_test', [ __CLASS__, 'ajax_test' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( LBC_FILE ), [ __CLASS__, 'plugin_action_links' ] );
	}

	public static function plugin_action_links( $links ) {
		$settings = '<a href="' . esc_url( admin_url( 'options-general.php?page=lobbychat' ) ) . '">' . esc_html__( 'Settings', 'lobbychat' ) . '</a>';
		$donate   = '<a href="' . esc_url( LBC_DONATE_URL ) . '" target="_blank" rel="noopener" style="color:#d63384">' . esc_html__( '♥ Donate', 'lobbychat' ) . '</a>';
		array_unshift( $links, $settings );
		$links[] = $donate;
		return $links;
	}

	public static function ajax_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized', 'lobbychat' ) ], 403 );
			wp_die();
		}
		check_ajax_referer( 'lobbychat_bot_test' );
		$result = LobbyChat_Bot::manual_trigger();
		if ( ! empty( $result['ok'] ) ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
		wp_die();
	}

	public static function add_menu() {
		add_options_page(
			__( 'LobbyChat', 'lobbychat' ),
			__( 'LobbyChat', 'lobbychat' ),
			'manage_options',
			'lobbychat',
			[ __CLASS__, 'render_main_page' ]
		);
		add_options_page(
			__( 'LobbyChat AI Bot', 'lobbychat' ),
			'',
			'manage_options',
			'lobbychat-bot',
			[ __CLASS__, 'render_bot_page' ]
		);
	}

	public static function register_settings() {
		// Main chat settings.
		$main = [
			'lobbychat_max_length'    => 'absint',
			'lobbychat_rate_logged'   => 'absint',
			'lobbychat_rate_guest'    => 'absint',
			'lobbychat_link_cooldown' => 'absint',
			'lobbychat_allow_guests'  => 'absint',
			'lobbychat_allow_links'   => 'absint',
			'lobbychat_poll_interval' => 'absint',
			'lobbychat_prune_days'    => 'absint',
			'lobbychat_show_branding' => 'absint',
			'lobbychat_blocklist'     => 'sanitize_textarea_field',
		];
		foreach ( $main as $key => $cb ) {
			register_setting( 'lobbychat_main', $key, [ 'sanitize_callback' => $cb ] );
		}

		// Bot settings.
		$bot = [
			'lobbychat_bot_enabled'         => 'absint',
			'lobbychat_bot_user_id'         => 'absint',
			'lobbychat_bot_name'            => 'sanitize_text_field',
			'lobbychat_bot_gemini_key'      => [ __CLASS__, 'sanitize_api_key' ],
			'lobbychat_bot_gemini_model'    => 'sanitize_text_field',
			'lobbychat_bot_openai_key'      => [ __CLASS__, 'sanitize_api_key' ],
			'lobbychat_bot_openai_model'    => 'sanitize_text_field',
			'lobbychat_bot_random_chance'   => 'absint',
			'lobbychat_bot_question_chance' => 'absint',
			'lobbychat_bot_reply_questions' => 'absint',
			'lobbychat_bot_cooldown'        => 'absint',
			'lobbychat_bot_max_per_hour'    => 'absint',
			'lobbychat_bot_max_per_day'     => 'absint',
			'lobbychat_bot_active_hours'    => 'sanitize_text_field',
			'lobbychat_bot_max_chars'       => 'absint',
			'lobbychat_bot_custom_prompt'   => 'sanitize_textarea_field',
			'lobbychat_bot_debug'           => 'absint',
		];
		foreach ( $bot as $key => $cb ) {
			register_setting( 'lobbychat_bot', $key, [ 'sanitize_callback' => $cb ] );
		}
	}

	/**
	 * Trim only — never sanitize the body of an API key.
	 */
	public static function sanitize_api_key( $val ) {
		return trim( wp_unslash( (string) $val ) );
	}

	/* ─────────────────────────────────────────────────────────
	   MAIN SETTINGS PAGE
	───────────────────────────────────────────────────────── */
	public static function render_main_page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }

		$max_length    = (int) get_option( 'lobbychat_max_length', 500 );
		$rate_logged   = (int) get_option( 'lobbychat_rate_logged', 5 );
		$rate_guest    = (int) get_option( 'lobbychat_rate_guest', 15 );
		$link_cooldown = (int) get_option( 'lobbychat_link_cooldown', 300 );
		$allow_guests  = (int) get_option( 'lobbychat_allow_guests', 1 );
		$allow_links   = (int) get_option( 'lobbychat_allow_links', 1 );
		$poll_interval = (int) get_option( 'lobbychat_poll_interval', 30000 );
		$prune_days    = (int) get_option( 'lobbychat_prune_days', 30 );
		$show_branding = (int) get_option( 'lobbychat_show_branding', 1 );
		$blocklist     = (string) get_option( 'lobbychat_blocklist', '' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'LobbyChat Settings', 'lobbychat' ); ?></h1>

			<div class="notice notice-info">
				<p>
					<strong><?php esc_html_e( 'How to display the chat:', 'lobbychat' ); ?></strong>
					<?php esc_html_e( 'Add the shortcode', 'lobbychat' ); ?>
					<code>[lobbychat]</code>
					<?php esc_html_e( 'to any page, post, or widget area.', 'lobbychat' ); ?>
					&nbsp;&middot;&nbsp;
					<a href="<?php echo esc_url( admin_url( 'options-general.php?page=lobbychat-bot' ) ); ?>">
						<?php esc_html_e( 'Configure AI Bot →', 'lobbychat' ); ?>
					</a>
				</p>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'lobbychat_main' ); ?>

				<h2><?php esc_html_e( 'Posting', 'lobbychat' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="lobbychat_allow_guests"><?php esc_html_e( 'Allow guest posts', 'lobbychat' ); ?></label></th>
						<td>
							<label>
								<input type="checkbox" name="lobbychat_allow_guests" id="lobbychat_allow_guests" value="1" <?php checked( $allow_guests, 1 ); ?>>
								<?php esc_html_e( 'Visitors can post without an account (they pick a display name)', 'lobbychat' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><label for="lobbychat_allow_links"><?php esc_html_e( 'Allow link sharing', 'lobbychat' ); ?></label></th>
						<td>
							<label>
								<input type="checkbox" name="lobbychat_allow_links" id="lobbychat_allow_links" value="1" <?php checked( $allow_links, 1 ); ?>>
								<?php esc_html_e( 'Logged-in members can share URLs (with link previews)', 'lobbychat' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><label for="lobbychat_max_length"><?php esc_html_e( 'Max message length', 'lobbychat' ); ?></label></th>
						<td>
							<input type="number" name="lobbychat_max_length" id="lobbychat_max_length"
								   value="<?php echo esc_attr( $max_length ); ?>" min="50" max="2000" style="width:100px"> <?php esc_html_e( 'characters', 'lobbychat' ); ?>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Rate Limits', 'lobbychat' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="lobbychat_rate_logged"><?php esc_html_e( 'Logged-in users', 'lobbychat' ); ?></label></th>
						<td>
							<input type="number" name="lobbychat_rate_logged" id="lobbychat_rate_logged"
								   value="<?php echo esc_attr( $rate_logged ); ?>" min="0" max="120" style="width:100px"> <?php esc_html_e( 'seconds between messages', 'lobbychat' ); ?>
						</td>
					</tr>
					<tr>
						<th><label for="lobbychat_rate_guest"><?php esc_html_e( 'Guests', 'lobbychat' ); ?></label></th>
						<td>
							<input type="number" name="lobbychat_rate_guest" id="lobbychat_rate_guest"
								   value="<?php echo esc_attr( $rate_guest ); ?>" min="0" max="600" style="width:100px"> <?php esc_html_e( 'seconds between messages', 'lobbychat' ); ?>
						</td>
					</tr>
					<tr>
						<th><label for="lobbychat_link_cooldown"><?php esc_html_e( 'Link cooldown', 'lobbychat' ); ?></label></th>
						<td>
							<input type="number" name="lobbychat_link_cooldown" id="lobbychat_link_cooldown"
								   value="<?php echo esc_attr( $link_cooldown ); ?>" min="0" max="3600" style="width:100px"> <?php esc_html_e( 'seconds between link shares (per user)', 'lobbychat' ); ?>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Performance', 'lobbychat' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="lobbychat_poll_interval"><?php esc_html_e( 'Refresh interval', 'lobbychat' ); ?></label></th>
						<td>
							<input type="number" name="lobbychat_poll_interval" id="lobbychat_poll_interval"
								   value="<?php echo esc_attr( $poll_interval ); ?>" min="5000" max="120000" step="1000" style="width:100px"> <?php esc_html_e( 'milliseconds', 'lobbychat' ); ?>
							<p class="description"><?php esc_html_e( 'How often the chat checks for new messages. 30000 = 30 seconds. Lower = more responsive but more server load.', 'lobbychat' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="lobbychat_prune_days"><?php esc_html_e( 'Auto-delete old messages', 'lobbychat' ); ?></label></th>
						<td>
							<input type="number" name="lobbychat_prune_days" id="lobbychat_prune_days"
								   value="<?php echo esc_attr( $prune_days ); ?>" min="0" max="3650" style="width:100px"> <?php esc_html_e( 'days (0 = never)', 'lobbychat' ); ?>
						</td>
					</tr>
					<tr>
						<th><label for="lobbychat_show_branding"><?php esc_html_e( 'Show "Powered by" link', 'lobbychat' ); ?></label></th>
						<td>
							<label>
								<input type="checkbox" name="lobbychat_show_branding" id="lobbychat_show_branding" value="1" <?php checked( $show_branding, 1 ); ?>>
								<?php esc_html_e( 'Display a small "Powered by LobbyChat ♥" link at the bottom of the chat (helps people discover the plugin)', 'lobbychat' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Moderation', 'lobbychat' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="lobbychat_blocklist"><?php esc_html_e( 'Word blocklist', 'lobbychat' ); ?></label></th>
						<td>
							<textarea name="lobbychat_blocklist" id="lobbychat_blocklist" rows="3" cols="60" placeholder="word1, word2, word3"><?php echo esc_textarea( $blocklist ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'Comma-separated. Messages containing any of these (case-insensitive substring match) are rejected.', 'lobbychat' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Moderators', 'lobbychat' ); ?></th>
						<td>
							<p>
								<?php
								printf(
									/* translators: %s: link to user list */
									esc_html__( 'Assign the %s role to any user via Users → All Users to give them pin/delete powers.', 'lobbychat' ),
									'<strong>' . esc_html__( 'LobbyChat Moderator', 'lobbychat' ) . '</strong>'
								);
								?>
							</p>
							<p class="description">
								<?php esc_html_e( 'Administrators are automatic moderators. Mods can pin messages and delete any message; regular users can only delete their own within 60 seconds.', 'lobbychat' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr>
			<p style="color:#666;font-size:13px;margin-top:24px">
				<?php
				printf(
					/* translators: %s: Wise donation link */
					esc_html__( 'LobbyChat is free and developed in spare time. If it helps your community, you can %s — every bit is appreciated. ♥', 'lobbychat' ),
					'<a href="' . esc_url( LBC_DONATE_URL ) . '" target="_blank" rel="noopener">' . esc_html__( 'support development', 'lobbychat' ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/* ─────────────────────────────────────────────────────────
	   AI BOT SETTINGS PAGE
	───────────────────────────────────────────────────────── */
	public static function render_bot_page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }

		$enabled         = (int)    get_option( 'lobbychat_bot_enabled',          0 );
		$bot_uid         = (int)    get_option( 'lobbychat_bot_user_id',          0 );
		$bot_name        = (string) get_option( 'lobbychat_bot_name',             'Helper' );
		$gemini_key      = (string) get_option( 'lobbychat_bot_gemini_key',       '' );
		$gemini_model    = (string) get_option( 'lobbychat_bot_gemini_model',     'gemini-2.5-flash' );
		$openai_key      = (string) get_option( 'lobbychat_bot_openai_key',       '' );
		$openai_model    = (string) get_option( 'lobbychat_bot_openai_model',     'gpt-4o-mini' );
		$random_chance   = (int)    get_option( 'lobbychat_bot_random_chance',    8 );
		$question_chance = (int)    get_option( 'lobbychat_bot_question_chance',  75 );
		$reply_questions = (int)    get_option( 'lobbychat_bot_reply_questions',  1 );
		$cooldown        = (int)    get_option( 'lobbychat_bot_cooldown',         30 );
		$max_per_hour    = (int)    get_option( 'lobbychat_bot_max_per_hour',     30 );
		$max_per_day     = (int)    get_option( 'lobbychat_bot_max_per_day',      200 );
		$active_hours    = (string) get_option( 'lobbychat_bot_active_hours',     '0-23' );
		$max_chars       = (int)    get_option( 'lobbychat_bot_max_chars',        200 );
		$custom_prompt   = (string) get_option( 'lobbychat_bot_custom_prompt',    '' );
		$debug           = (int)    get_option( 'lobbychat_bot_debug',            0 );

		$daily_bucket = get_option( 'lobbychat_bot_daily_bucket', [ 'day' => '', 'count' => 0 ] );
		$today_count  = ( is_array( $daily_bucket ) && isset( $daily_bucket['day'] ) && $daily_bucket['day'] === current_time( 'Y-m-d' ) )
						 ? (int) $daily_bucket['count'] : 0;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'LobbyChat — AI Bot', 'lobbychat' ); ?></h1>
			<p style="max-width:760px;color:#555">
				<?php esc_html_e( 'Add an optional AI chat companion that replies in your shoutbox. The bot uses your own API keys — bring your own Google Gemini key (free tier available) and/or OpenAI key. Disabled by default.', 'lobbychat' ); ?>
			</p>

			<p>
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=lobbychat' ) ); ?>" class="button">
					← <?php esc_html_e( 'Back to LobbyChat Settings', 'lobbychat' ); ?>
				</a>
			</p>

			<!-- Setup guide -->
			<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px;margin:20px 0;max-width:760px">
				<h3 style="margin-top:0">📖 <?php esc_html_e( 'Setup guide', 'lobbychat' ); ?></h3>
				<ol style="margin-bottom:0;line-height:1.8">
					<li>
						<strong><?php esc_html_e( 'Create a WordPress user for the bot.', 'lobbychat' ); ?></strong><br>
						<?php
						printf(
							/* translators: %s: link to add new user */
							esc_html__( 'Go to %s and create a Subscriber-role user. Pick a display name (e.g. "Helper"). Optionally upload a Gravatar avatar for a face.', 'lobbychat' ),
							'<a href="' . esc_url( admin_url( 'user-new.php' ) ) . '">' . esc_html__( 'Users → Add New', 'lobbychat' ) . '</a>'
						);
						?>
					</li>
					<li>
						<strong><?php esc_html_e( 'Get a Gemini API key (recommended — free tier).', 'lobbychat' ); ?></strong><br>
						<?php esc_html_e( 'Visit', 'lobbychat' ); ?>
						<a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">aistudio.google.com/apikey</a> →
						<?php esc_html_e( 'sign in with a Google account → "Create API key" → copy the key and paste it below.', 'lobbychat' ); ?>
					</li>
					<li>
						<strong><?php esc_html_e( 'Optionally add an OpenAI key as fallback.', 'lobbychat' ); ?></strong><br>
						<?php esc_html_e( 'OpenAI is paid (no free tier). Get a key at', 'lobbychat' ); ?>
						<a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener">platform.openai.com/api-keys</a>.
						<?php esc_html_e( 'Used only when Gemini fails or is rate-limited.', 'lobbychat' ); ?>
					</li>
					<li>
						<strong><?php esc_html_e( 'Select the bot user and enable.', 'lobbychat' ); ?></strong><br>
						<?php esc_html_e( 'Pick the user you created in step 1 from the dropdown below, then check "Enable bot".', 'lobbychat' ); ?>
					</li>
					<li>
						<strong><?php esc_html_e( 'Test it.', 'lobbychat' ); ?></strong><br>
						<?php esc_html_e( 'Post a message in your chat (front-end), then click "Send Test Reply" below to verify the bot can post.', 'lobbychat' ); ?>
					</li>
				</ol>
			</div>

			<!-- Status panel -->
			<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px;margin:20px 0;max-width:760px">
				<h3 style="margin-top:0"><?php esc_html_e( 'Status', 'lobbychat' ); ?></h3>
				<ul style="margin:0">
					<li><strong><?php esc_html_e( 'Bot:', 'lobbychat' ); ?></strong>
						<?php echo $enabled ? '<span style="color:#0a0">✓ ' . esc_html__( 'Enabled', 'lobbychat' ) . '</span>' : '<span style="color:#999">○ ' . esc_html__( 'Disabled', 'lobbychat' ) . '</span>'; ?>
					</li>
					<li><strong>Gemini:</strong>
						<?php echo $gemini_key ? '<span style="color:#0a0">✓ ' . esc_html__( 'Key set', 'lobbychat' ) . '</span>' : '<span style="color:#c00">✗ ' . esc_html__( 'Not configured', 'lobbychat' ) . '</span>'; ?>
					</li>
					<li><strong>OpenAI:</strong>
						<?php echo $openai_key ? '<span style="color:#0a0">✓ ' . esc_html__( 'Key set (fallback)', 'lobbychat' ) . '</span>' : '<span style="color:#999">○ ' . esc_html__( 'Not set (optional)', 'lobbychat' ) . '</span>'; ?>
					</li>
					<li><strong><?php esc_html_e( 'Bot user:', 'lobbychat' ); ?></strong>
						<?php
						if ( $bot_uid ) {
							$u = get_userdata( $bot_uid );
							echo $u
								? '<span style="color:#0a0">✓ ' . esc_html( $u->display_name . ' (@' . $u->user_login . ')' ) . '</span>'
								: '<span style="color:#c00">✗ ' . esc_html__( 'User not found', 'lobbychat' ) . '</span>';
						} else {
							echo '<span style="color:#c00">✗ ' . esc_html__( 'Not set', 'lobbychat' ) . '</span>';
						}
						?>
					</li>
					<li><strong><?php esc_html_e( 'Replies today:', 'lobbychat' ); ?></strong> <?php echo (int) $today_count; ?> / <?php echo (int) $max_per_day; ?></li>
				</ul>
			</div>

			<!-- Test bot button -->
			<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px;margin:20px 0;max-width:760px">
				<h3 style="margin-top:0">🧪 <?php esc_html_e( 'Test bot now', 'lobbychat' ); ?></h3>
				<p><?php esc_html_e( 'Force the bot to reply to recent chat messages (bypasses random chance).', 'lobbychat' ); ?></p>
				<button type="button" class="button button-primary" id="lobbychat-bot-test-btn"><?php esc_html_e( 'Send Test Reply', 'lobbychat' ); ?></button>
				<span id="lobbychat-bot-test-result" style="margin-left:12px"></span>
				<script>
				jQuery(function($){
					$('#lobbychat-bot-test-btn').on('click', function(){
						var $btn = $(this), $out = $('#lobbychat-bot-test-result');
						$btn.prop('disabled', true);
						$out.html('<span style="color:#888"><?php echo esc_js( __( 'Calling AI…', 'lobbychat' ) ); ?></span>');
						$.post(ajaxurl, {
							action: 'lobbychat_bot_test',
							_ajax_nonce: '<?php echo esc_js( wp_create_nonce( 'lobbychat_bot_test' ) ); ?>'
						}).done(function(r){
							if (r.success) {
								$out.html('<span style="color:#0a7d0a">✓ <?php echo esc_js( __( 'Posted:', 'lobbychat' ) ); ?> "' + r.data.reply + '"</span>');
							} else {
								$out.html('<span style="color:#c00">✗ ' + (r.data && r.data.error ? r.data.error : '<?php echo esc_js( __( 'Unknown error', 'lobbychat' ) ); ?>') + '</span>');
							}
						}).fail(function(xhr){
							$out.html('<span style="color:#c00">✗ HTTP ' + xhr.status + '</span>');
						}).always(function(){ $btn.prop('disabled', false); });
					});
				});
				</script>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'lobbychat_bot' ); ?>

				<h2><?php esc_html_e( 'Activation', 'lobbychat' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Enable bot', 'lobbychat' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="lobbychat_bot_enabled" value="1" <?php checked( $enabled, 1 ); ?>>
								<?php esc_html_e( 'Bot will reply to messages based on the rules below', 'lobbychat' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><label for="lobbychat_bot_name"><?php esc_html_e( 'Bot display name', 'lobbychat' ); ?></label></th>
						<td>
							<input type="text" name="lobbychat_bot_name" id="lobbychat_bot_name"
								   value="<?php echo esc_attr( $bot_name ); ?>" maxlength="50" style="width:240px">
							<p class="description"><?php esc_html_e( 'Used in the default persona prompt. Should match your bot WP user’s display name.', 'lobbychat' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="lobbychat_bot_user_id"><?php esc_html_e( 'Bot WordPress user', 'lobbychat' ); ?></label></th>
						<td>
							<select name="lobbychat_bot_user_id" id="lobbychat_bot_user_id" style="width:280px">
								<option value="0">— <?php esc_html_e( 'Select a user', 'lobbychat' ); ?> —</option>
								<?php
								$users = get_users( [ 'fields' => [ 'ID', 'display_name', 'user_login' ], 'number' => 200 ] );
								foreach ( $users as $u ) {
									printf(
										'<option value="%d"%s>%s (@%s)</option>',
										(int) $u->ID,
										selected( $bot_uid, $u->ID, false ),
										esc_html( $u->display_name ),
										esc_html( $u->user_login )
									);
								}
								?>
							</select>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'API Keys', 'lobbychat' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="lobbychat_bot_gemini_key">Gemini <?php esc_html_e( 'API key', 'lobbychat' ); ?></label></th>
						<td>
							<input type="password" name="lobbychat_bot_gemini_key" id="lobbychat_bot_gemini_key"
								   value="<?php echo esc_attr( $gemini_key ); ?>" style="width:380px" autocomplete="off"
								   placeholder="AIza...">
							<p class="description">
								<?php esc_html_e( 'Get one free at', 'lobbychat' ); ?>
								<a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">aistudio.google.com/apikey</a>.
								<?php esc_html_e( 'Stored in your WordPress database (wp_options).', 'lobbychat' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th><label for="lobbychat_bot_gemini_model">Gemini <?php esc_html_e( 'model', 'lobbychat' ); ?></label></th>
						<td>
							<input type="text" name="lobbychat_bot_gemini_model" id="lobbychat_bot_gemini_model"
								   value="<?php echo esc_attr( $gemini_model ); ?>" style="width:240px">
							<p class="description"><?php esc_html_e( 'Default: gemini-2.5-flash. Other options: gemini-2.5-flash-lite, gemini-2.0-flash.', 'lobbychat' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="lobbychat_bot_openai_key">OpenAI <?php esc_html_e( 'API key (fallback)', 'lobbychat' ); ?></label></th>
						<td>
							<input type="password" name="lobbychat_bot_openai_key" id="lobbychat_bot_openai_key"
								   value="<?php echo esc_attr( $openai_key ); ?>" style="width:380px" autocomplete="off"
								   placeholder="sk-...">
							<p class="description">
								<?php esc_html_e( 'Optional. Used only when Gemini fails. Get one at', 'lobbychat' ); ?>
								<a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener">platform.openai.com/api-keys</a>.
							</p>
						</td>
					</tr>
					<tr>
						<th><label for="lobbychat_bot_openai_model">OpenAI <?php esc_html_e( 'model', 'lobbychat' ); ?></label></th>
						<td>
							<input type="text" name="lobbychat_bot_openai_model" id="lobbychat_bot_openai_model"
								   value="<?php echo esc_attr( $openai_model ); ?>" style="width:240px">
							<p class="description"><?php esc_html_e( 'Default: gpt-4o-mini. Cheap and fast.', 'lobbychat' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Reply Triggers', 'lobbychat' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="lobbychat_bot_random_chance"><?php esc_html_e( 'Random reply chance', 'lobbychat' ); ?></label></th>
						<td>
							<input type="number" name="lobbychat_bot_random_chance" id="lobbychat_bot_random_chance"
								   value="<?php echo esc_attr( $random_chance ); ?>" min="0" max="100" style="width:80px"> %
							<p class="description"><?php esc_html_e( 'Chance the bot replies to ANY non-question message. Recommended: 5–15%.', 'lobbychat' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Reply to questions', 'lobbychat' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="lobbychat_bot_reply_questions" value="1" <?php checked( $reply_questions, 1 ); ?>>
								<?php esc_html_e( 'Detect questions and reply more often', 'lobbychat' ); ?>
							</label>
							<br><br>
							<label><?php esc_html_e( 'Question reply chance:', 'lobbychat' ); ?>
								<input type="number" name="lobbychat_bot_question_chance" value="<?php echo esc_attr( $question_chance ); ?>" min="0" max="100" style="width:80px"> %
							</label>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Mentions', 'lobbychat' ); ?></th>
						<td>
							<p>
								<?php
								printf(
									/* translators: 1: @bot, 2: @<botname> */
									esc_html__( 'The bot always replies when someone writes %1$s or %2$s in chat.', 'lobbychat' ),
									'<code>@bot</code>',
									'<code>@' . esc_html( strtolower( str_replace( ' ', '', $bot_name ) ) ) . '</code>'
								);
								?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Rate Limits', 'lobbychat' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="lobbychat_bot_cooldown"><?php esc_html_e( 'Cooldown', 'lobbychat' ); ?></label></th>
						<td>
							<input type="number" name="lobbychat_bot_cooldown" id="lobbychat_bot_cooldown"
								   value="<?php echo esc_attr( $cooldown ); ?>" min="0" max="3600" style="width:100px"> <?php esc_html_e( 'seconds', 'lobbychat' ); ?>
							<p class="description"><?php esc_html_e( 'Minimum seconds between bot replies. Prevents spam.', 'lobbychat' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'Max replies per hour', 'lobbychat' ); ?></label></th>
						<td>
							<input type="number" name="lobbychat_bot_max_per_hour"
								   value="<?php echo esc_attr( $max_per_hour ); ?>" min="1" max="500" style="width:100px">
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'Max replies per day', 'lobbychat' ); ?></label></th>
						<td>
							<input type="number" name="lobbychat_bot_max_per_day"
								   value="<?php echo esc_attr( $max_per_day ); ?>" min="1" max="5000" style="width:100px">
							<p class="description"><?php esc_html_e( 'Hard cap — protects against runaway API costs.', 'lobbychat' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="lobbychat_bot_active_hours"><?php esc_html_e( 'Active hours', 'lobbychat' ); ?></label></th>
						<td>
							<input type="text" name="lobbychat_bot_active_hours" id="lobbychat_bot_active_hours"
								   value="<?php echo esc_attr( $active_hours ); ?>" placeholder="0-23" style="width:120px">
							<p class="description">
								<?php esc_html_e( 'Format: start-end (in your site timezone). Examples:', 'lobbychat' ); ?>
								<code>0-23</code> <?php esc_html_e( 'always on', 'lobbychat' ); ?>,
								<code>9-22</code> 9am→10pm,
								<code>22-6</code> <?php esc_html_e( 'overnight only', 'lobbychat' ); ?>.
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Reply Quality', 'lobbychat' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="lobbychat_bot_max_chars"><?php esc_html_e( 'Max characters per reply', 'lobbychat' ); ?></label></th>
						<td>
							<input type="number" name="lobbychat_bot_max_chars" id="lobbychat_bot_max_chars"
								   value="<?php echo esc_attr( $max_chars ); ?>" min="50" max="500" style="width:100px">
							<p class="description"><?php esc_html_e( 'Replies past this are trimmed at the last sentence break.', 'lobbychat' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="lobbychat_bot_custom_prompt"><?php esc_html_e( 'Custom system prompt', 'lobbychat' ); ?></label></th>
						<td>
							<textarea name="lobbychat_bot_custom_prompt" id="lobbychat_bot_custom_prompt"
									  rows="8" cols="80" style="font-family:monospace;font-size:12px"
									  placeholder="<?php esc_attr_e( 'Leave blank to use the default persona.', 'lobbychat' ); ?>"><?php echo esc_textarea( $custom_prompt ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'Override the default persona with your own. Leave blank to use the built-in friendly persona.', 'lobbychat' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Diagnostics', 'lobbychat' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Debug logging', 'lobbychat' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="lobbychat_bot_debug" value="1" <?php checked( $debug, 1 ); ?>>
								<?php esc_html_e( 'Log every bot decision to', 'lobbychat' ); ?> <code>wp-content/debug.log</code>
							</label>
							<p class="description">
								<?php esc_html_e( 'Useful for diagnosing why the bot did or didn’t reply. Requires', 'lobbychat' ); ?>
								<code>WP_DEBUG_LOG</code> <?php esc_html_e( 'in wp-config. Disable in production.', 'lobbychat' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Default persona', 'lobbychat' ); ?></h2>
			<details>
				<summary style="cursor:pointer;font-weight:600"><?php esc_html_e( 'View the default persona prompt', 'lobbychat' ); ?></summary>
				<div style="background:#f7f7f7;border-left:3px solid #4f46e5;padding:14px;margin-top:10px;font-family:monospace;font-size:12px;white-space:pre-wrap;max-width:760px"><?php
					$prev = get_option( 'lobbychat_bot_custom_prompt' );
					delete_option( 'lobbychat_bot_custom_prompt' );
					echo esc_html( LobbyChat_Bot::get_system_prompt() );
					if ( false !== $prev ) {
						update_option( 'lobbychat_bot_custom_prompt', $prev );
					}
				?></div>
			</details>
		</div>
		<?php
	}
}

LobbyChat_Admin::init();
