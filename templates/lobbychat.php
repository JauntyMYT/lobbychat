<?php
/**
 * LobbyChat — Shortcode template
 *
 * @package LobbyChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$lbc_uid          = get_current_user_id();
$lbc_logged_in    = (bool) $lbc_uid;
$lbc_allow_guests = (int) get_option( 'lobbychat_allow_guests', 1 );
$lbc_allow_links  = (int) get_option( 'lobbychat_allow_links', 1 );
$lbc_max_length   = (int) get_option( 'lobbychat_max_length', 500 );
$lbc_show_brand   = (int) get_option( 'lobbychat_show_branding', 1 );
$lbc_user         = $lbc_uid ? get_userdata( $lbc_uid ) : null;
$lbc_is_mod       = $lbc_uid && (
	current_user_can( 'manage_options' )
	|| current_user_can( 'lobbychat_moderate' )
	|| ( $lbc_user && in_array( 'lobbychat_moderator', (array) $lbc_user->roles, true ) )
);
?>

<div id="lobbychat" class="lobbychat-wrap">

	<!-- ══ HEADER ══════════════════════════════════════════ -->
	<div class="lobbychat-header">
		<div class="lobbychat-header-left">
			<span class="lobbychat-live-dot" aria-hidden="true"></span>
			<span class="lobbychat-title"><?php esc_html_e( 'Chat', 'lobbychat' ); ?></span>
			<span class="lobbychat-online">
				<span id="lobbychat-online-count">0</span> <?php esc_html_e( 'online', 'lobbychat' ); ?>
			</span>
		</div>
		<div class="lobbychat-header-right">
			<?php if ( $lbc_logged_in ) : ?>
				<a class="lobbychat-icon-btn lobbychat-profile-btn"
				   href="<?php echo esc_url( get_author_posts_url( $lbc_uid ) ); ?>"
				   title="<?php esc_attr_e( 'My Profile', 'lobbychat' ); ?>">
					<img src="<?php echo esc_url( get_avatar_url( $lbc_uid, [ 'size' => 20, 'default' => 'identicon' ] ) ); ?>"
						 width="20" height="20" alt="">
				</a>
			<?php else : ?>
				<a class="lobbychat-icon-btn"
				   href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>"
				   title="<?php esc_attr_e( 'Login', 'lobbychat' ); ?>">👤</a>
			<?php endif; ?>
			<button type="button" class="lobbychat-icon-btn" id="lobbychat-sound-btn" title="<?php esc_attr_e( 'Toggle sound', 'lobbychat' ); ?>">🔔</button>
			<button type="button" class="lobbychat-icon-btn" id="lobbychat-fs-btn" title="<?php esc_attr_e( 'Fullscreen', 'lobbychat' ); ?>">⛶</button>
			<button type="button" class="lobbychat-icon-btn" id="lobbychat-toggle-btn" title="<?php esc_attr_e( 'Collapse', 'lobbychat' ); ?>">▲</button>
		</div>
	</div>

	<!-- ══ BODY ═════════════════════════════════════════════ -->
	<div class="lobbychat-body" id="lobbychat-body">

		<!-- Pinned message -->
		<div id="lobbychat-pinned" class="lobbychat-pinned" style="display:none">
			<span class="lobbychat-pin-icon" aria-hidden="true">📌</span>
			<span id="lobbychat-pinned-text"></span>
			<?php if ( $lbc_is_mod ) : ?>
				<button type="button" class="lobbychat-unpin-btn" id="lobbychat-unpin-btn" aria-label="<?php esc_attr_e( 'Unpin', 'lobbychat' ); ?>">✕</button>
			<?php endif; ?>
		</div>

		<!-- Messages feed -->
		<div id="lobbychat-feed" class="lobbychat-feed" aria-live="polite">
			<div class="lobbychat-loading"><?php esc_html_e( 'Loading…', 'lobbychat' ); ?></div>
		</div>

	</div>

	<!-- ══ INPUT AREA ════════════════════════════════════════ -->
	<div class="lobbychat-input-area" id="lobbychat-input-area">

		<?php if ( ! $lbc_logged_in && $lbc_allow_guests ) : ?>
		<!-- Guest name -->
		<div class="lobbychat-guest-row">
			<input type="text" id="lobbychat-guest-name" class="lobbychat-name-input"
				   placeholder="<?php esc_attr_e( 'Your name (guest)', 'lobbychat' ); ?>" maxlength="30">
		</div>
		<?php elseif ( ! $lbc_logged_in ) : ?>
		<div class="lobbychat-locked">
			<?php
			printf(
				/* translators: %s: login link */
				esc_html__( 'Please %s to post.', 'lobbychat' ),
				'<a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'log in', 'lobbychat' ) . '</a>'
			);
			?>
		</div>
		<?php endif; ?>

		<?php if ( $lbc_logged_in || $lbc_allow_guests ) : ?>

		<!-- Message row -->
		<div class="lobbychat-msg-row">
			<?php if ( $lbc_logged_in ) : ?>
				<img class="lobbychat-my-avatar"
					 src="<?php echo esc_url( get_avatar_url( $lbc_uid, [ 'size' => 28, 'default' => 'identicon' ] ) ); ?>"
					 width="28" height="28" alt="">
			<?php endif; ?>
			<input type="text" id="lobbychat-message" class="lobbychat-msg-input"
				   placeholder="<?php esc_attr_e( 'Say something…', 'lobbychat' ); ?>"
				   maxlength="<?php echo esc_attr( $lbc_max_length ); ?>">
			<span class="lobbychat-char-count" id="lobbychat-char-count"><?php echo esc_html( $lbc_max_length ); ?></span>
		</div>

		<!-- Link row (logged in only, if links enabled) -->
		<?php if ( $lbc_logged_in && $lbc_allow_links ) : ?>
		<div class="lobbychat-link-row" id="lobbychat-link-row" style="display:none">
			<input type="url" id="lobbychat-link-url" class="lobbychat-link-input"
				   placeholder="<?php esc_attr_e( 'Paste a URL…', 'lobbychat' ); ?>">
			<button type="button" class="lobbychat-icon-btn" id="lobbychat-link-clear" title="<?php esc_attr_e( 'Remove link', 'lobbychat' ); ?>">✕</button>
		</div>
		<?php endif; ?>

		<!-- Action buttons -->
		<div class="lobbychat-actions">
			<div class="lobbychat-actions-left">
				<?php if ( $lbc_logged_in && $lbc_allow_links ) : ?>
					<button type="button" class="lobbychat-action-btn" id="lobbychat-link-btn" title="<?php esc_attr_e( 'Share a link', 'lobbychat' ); ?>">🔗</button>
				<?php endif; ?>
			</div>
			<button type="button" class="lobbychat-send-btn" id="lobbychat-send-btn"><?php esc_html_e( 'Send', 'lobbychat' ); ?></button>
		</div>

		<?php endif; ?>

	</div>

	<!-- ══ WHO'S ONLINE STRIP ═══════════════════════════════════ -->
	<div class="lobbychat-whos-online" id="lobbychat-whos-online">
		<div class="lobbychat-wo-inner">
			<span class="lobbychat-wo-section" id="lobbychat-wo-members" style="display:none">
				<span class="lobbychat-wo-icon lobbychat-wo-icon-member" aria-hidden="true"></span>
				<span class="lobbychat-wo-label"><?php esc_html_e( 'Members:', 'lobbychat' ); ?></span>
				<span class="lobbychat-wo-list" id="lobbychat-wo-members-list"></span>
			</span>
			<span class="lobbychat-wo-section" id="lobbychat-wo-guests" style="display:none">
				<span class="lobbychat-wo-icon lobbychat-wo-icon-guest" aria-hidden="true"></span>
				<span class="lobbychat-wo-label"><?php esc_html_e( 'Guests:', 'lobbychat' ); ?></span>
				<span class="lobbychat-wo-count" id="lobbychat-wo-guests-count">0</span>
			</span>
			<span class="lobbychat-wo-section" id="lobbychat-wo-bots" style="display:none">
				<span class="lobbychat-wo-icon lobbychat-wo-icon-bot" aria-hidden="true"></span>
				<span class="lobbychat-wo-label"><?php esc_html_e( 'Bots:', 'lobbychat' ); ?></span>
				<span class="lobbychat-wo-list" id="lobbychat-wo-bots-list"></span>
			</span>
			<span class="lobbychat-wo-empty" id="lobbychat-wo-empty"><?php esc_html_e( 'Waiting for activity…', 'lobbychat' ); ?></span>
		</div>
	</div>

	<?php if ( $lbc_show_brand ) : ?>
	<div class="lobbychat-branding">
		<a href="<?php echo esc_url( LBC_DONATE_URL ); ?>" target="_blank" rel="noopener nofollow">
			<?php esc_html_e( 'Powered by LobbyChat', 'lobbychat' ); ?> <span aria-hidden="true">♥</span>
		</a>
	</div>
	<?php endif; ?>

</div>
