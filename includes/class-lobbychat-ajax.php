<?php
/**
 * LobbyChat AJAX endpoints.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class LobbyChat_Ajax {

    public static function init() {
        $actions = [
            'lobbychat_get', 'lobbychat_ping', 'lobbychat_send',
            'lobbychat_react', 'lobbychat_report', 'lobbychat_delete',
            'lobbychat_pin', 'lobbychat_unpin',
        ];
        foreach ( $actions as $a ) {
            add_action( "wp_ajax_{$a}",        [ __CLASS__, $a ] );
            add_action( "wp_ajax_nopriv_{$a}", [ __CLASS__, $a ] );
        }
    }

    /* ── Helpers ───────────────────────────────────────── */

    private static function ok( $data = [] )      { wp_send_json_success( $data ); wp_die(); }
    private static function err( $msg, $c = 400 ) { wp_send_json_error( [ 'message' => $msg ], $c ); wp_die(); }

    private static function get_ip() {
        return isset( $_SERVER['REMOTE_ADDR'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
            : '0.0.0.0';
    }

    private static function get_ua() {
        return isset( $_SERVER['HTTP_USER_AGENT'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
            : '';
    }

    /**
     * Verify the AJAX nonce. Aborts with a 403 if invalid.
     * After this call returns, the request is guaranteed nonce-verified —
     * any $_POST reads after this point can safely use the
     * phpcs:ignore WordPress.Security.NonceVerification.* annotation.
     */
    private static function check_nonce() {
        $nonce = isset( $_POST['nonce'] )
            ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) )
            : ( isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '' );
        if ( ! wp_verify_nonce( $nonce, 'lobbychat_nonce' ) ) {
            self::err( __( 'Security check failed.', 'lobbychat' ), 403 );
        }
    }

    /**
     * Is the user a moderator? Site admins always are.
     * Filterable so other plugins can grant mod rights.
     */
    public static function is_mod( $uid ) {
        if ( ! $uid ) return false;
        $u = get_userdata( $uid );
        if ( ! $u ) return false;
        $is_mod = in_array( 'administrator', (array) $u->roles, true )
               || in_array( 'lobbychat_moderator', (array) $u->roles, true )
               || user_can( $u, 'lobbychat_moderate' )
               || user_can( $u, 'manage_options' );
        return (bool) apply_filters( 'lobbychat_is_moderator', $is_mod, $uid, $u );
    }

    /**
     * Word blocklist — configurable from Settings → LobbyChat (comma-separated) + filter.
     */
    private static function bad_words() {
        $stored = (string) get_option( 'lobbychat_blocklist', '' );
        $arr = array_filter( array_map( 'trim', explode( ',', strtolower( $stored ) ) ) );
        return apply_filters( 'lobbychat_blocklist', $arr );
    }

    /* ── Bot detection from User-Agent ─────────────────── */

    private static function detect_bot( $ua ) {
        if ( ! $ua ) return null;
        $ua_lower = strtolower( $ua );
        $bots = [
            'googlebot'             => 'Googlebot',
            'bingbot'               => 'Bingbot',
            'slurp'                 => 'Yahoo',
            'duckduckbot'           => 'DuckDuckBot',
            'baiduspider'           => 'Baidu',
            'yandexbot'             => 'Yandex',
            'facebookexternalhit'   => 'Facebook',
            'facebot'               => 'Facebook',
            'twitterbot'            => 'Twitter',
            'linkedinbot'           => 'LinkedIn',
            'whatsapp'              => 'WhatsApp',
            'applebot'              => 'Applebot',
            'ahrefsbot'             => 'AhrefsBot',
            'semrushbot'            => 'SemrushBot',
            'mj12bot'               => 'MJ12Bot',
            'dotbot'                => 'DotBot',
            'petalbot'              => 'PetalBot',
            'rogerbot'              => 'Moz',
            'uptimerobot'           => 'UptimeRobot',
            'pingdom'               => 'Pingdom',
            'gptbot'                => 'GPTBot',
            'chatgpt-user'          => 'ChatGPT',
            'claudebot'             => 'ClaudeBot',
            'anthropic-ai'          => 'Anthropic',
            'perplexitybot'         => 'Perplexity',
            'ccbot'                 => 'CCBot',
            'bytespider'            => 'ByteSpider',
            'google-inspectiontool' => 'Google',
            'mediapartners-google'  => 'Google Ads',
            'adsbot-google'         => 'Google Ads',
        ];
        foreach ( $bots as $needle => $label ) {
            if ( strpos( $ua_lower, $needle ) !== false ) return $label;
        }
        if ( preg_match( '/\b(bot|crawler|spider|scraper|curl|wget|headlesschrome|phantomjs)\b/i', $ua_lower ) ) {
            return 'Unknown Bot';
        }
        return null;
    }

    private static function ping_presence( $uid, $ip ) {
        $ua       = self::get_ua();
        $bot_name = self::detect_bot( $ua );

        if ( $bot_name ) {
            LobbyChat_DB::ping_online( 0, $ip, 'bot', $bot_name, $ua );
            return;
        }
        if ( ! $uid ) {
            LobbyChat_DB::ping_online( 0, $ip, 'guest', null, $ua );
            return;
        }
        $user = get_userdata( $uid );
        $name = $user ? $user->display_name : 'User';
        $type = 'member';
        if ( $user && in_array( 'administrator', (array) $user->roles, true ) ) {
            $type = 'admin';
        } elseif ( $user && in_array( 'lobbychat_moderator', (array) $user->roles, true ) ) {
            $type = 'mod';
        }
        LobbyChat_DB::ping_online( $uid, $ip, $type, $name, $ua );
    }

    /* ── Formatting ────────────────────────────────────── */

    private static function format_message( $row ) {
        $uid      = (int) $row->user_id;
        $is_guest = $uid === 0;
        $name     = $is_guest
            ? esc_html( $row->guest_name ?: __( 'Guest', 'lobbychat' ) )
            : esc_html( $row->display_name ?: __( 'User', 'lobbychat' ) );

        $avatar    = $is_guest ? '' : get_avatar_url( $uid, [ 'size' => 32, 'default' => 'identicon' ] );
        $reactions = $row->reactions    ? json_decode( $row->reactions,    true ) : [];
        $preview   = $row->link_preview ? json_decode( $row->link_preview, true ) : null;

        // Profile URL — defaults to WP author archive; filterable so themes can override.
        $profile_url = $is_guest ? '' : apply_filters( 'lobbychat_profile_url', get_author_posts_url( $uid ), $uid );

        return [
            'id'          => (int) $row->id,
            'name'        => $name,
            'avatar'      => $avatar,
            'is_guest'    => $is_guest,
            'user_id'     => $uid,
            'profile_url' => $profile_url,
            'message'     => esc_html( $row->message ),
            'is_pinned'   => (bool) $row->is_pinned,
            'link_url'    => esc_url( $row->link_url ?? '' ),
            'preview'     => $preview,
            'reactions'   => $reactions,
            'time_ago'    => self::time_ago( $row->created_at ),
            'timestamp'   => $row->created_at,
        ];
    }

    private static function time_ago( $dt ) {
        $d = current_time( 'timestamp' ) - strtotime( $dt );
        if ( $d < 5 )     return __( 'just now', 'lobbychat' );
        if ( $d < 60 )    return $d . __( 's ago', 'lobbychat' );
        if ( $d < 3600 )  return floor( $d / 60 )    . __( 'm ago', 'lobbychat' );
        if ( $d < 86400 ) return floor( $d / 3600 )  . __( 'h ago', 'lobbychat' );
        return floor( $d / 86400 ) . __( 'd ago', 'lobbychat' );
    }

    /* ── GET: feed + presence ─────────────────────────── */

    public static function lobbychat_get() {
        self::check_nonce();
        $uid = get_current_user_id();
        $ip  = self::get_ip();
        // Nonce already verified above; phpcs can't trace cross-method.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $since_id = isset( $_POST['since_id'] ) ? intval( $_POST['since_id'] ) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $limit = isset( $_POST['limit'] ) ? min( intval( $_POST['limit'] ), 60 ) : 40;

        self::ping_presence( $uid, $ip );

        $rows   = LobbyChat_DB::get_messages( $limit, $since_id );
        $pinned = $since_id ? null : LobbyChat_DB::get_pinned();
        $online = LobbyChat_DB::get_online_count();

        self::ok([
            'messages'  => array_map( [ __CLASS__, 'format_message' ], $rows ),
            'pinned'    => $pinned ? self::format_message( $pinned ) : null,
            'online'    => $online,
            'breakdown' => LobbyChat_DB::get_online_breakdown(),
            'is_mod'    => self::is_mod( $uid ),
            'logged_in' => (bool) $uid,
            'user_id'   => $uid,
        ]);
    }

    /* ── PING: keep-alive ─────────────────────────────── */

    public static function lobbychat_ping() {
        self::check_nonce();
        self::ping_presence( get_current_user_id(), self::get_ip() );
        self::ok([
            'online'    => LobbyChat_DB::get_online_count(),
            'breakdown' => LobbyChat_DB::get_online_breakdown(),
        ]);
    }

    /* ── SEND ─────────────────────────────────────────── */

    public static function lobbychat_send() {
        self::check_nonce();

        $uid = get_current_user_id();
        $ip  = self::get_ip();

        // Nonce verified above; phpcs can't trace cross-method.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $message    = isset( $_POST['message'] )  ? sanitize_text_field( wp_unslash( $_POST['message']  ) ) : '';
        $message    = trim( $message );
        $link_url   = isset( $_POST['link_url'] ) ? esc_url_raw( wp_unslash( $_POST['link_url'] ) ) : '';
        $link_url   = trim( $link_url );
        $guest_name = '';

        $allow_guests = (int) get_option( 'lobbychat_allow_guests', 1 );
        $allow_links  = (int) get_option( 'lobbychat_allow_links', 1 );
        $max_length   = (int) get_option( 'lobbychat_max_length', 500 );
        if ( $max_length < 50 || $max_length > 2000 ) $max_length = 500;

        // Login enforcement.
        if ( ! $uid ) {
            if ( ! $allow_guests ) {
                self::err( __( 'Please log in to chat.', 'lobbychat' ), 401 );
            }
            $guest_name = isset( $_POST['guest_name'] )
                ? sanitize_text_field( wp_unslash( $_POST['guest_name'] ) )
                : '';
            $guest_name = trim( $guest_name );
            if ( ! $guest_name ) self::err( __( 'Please enter a name.', 'lobbychat' ) );
            if ( strlen( $guest_name ) > 30 ) self::err( __( 'Name too long.', 'lobbychat' ) );
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if ( ! $message ) self::err( __( 'Message cannot be empty.', 'lobbychat' ) );
        if ( mb_strlen( $message ) > $max_length ) self::err( __( 'Message too long.', 'lobbychat' ) );

        // Bad words filter.
        $bad = self::bad_words();
        if ( ! empty( $bad ) ) {
            $lc = strtolower( $message );
            foreach ( $bad as $word ) {
                if ( $word !== '' && strpos( $lc, $word ) !== false ) {
                    self::err( __( 'Message contains prohibited content.', 'lobbychat' ) );
                }
            }
        }

        // Rate limiter — config-driven.
        $rate_logged = max( 1, (int) get_option( 'lobbychat_rate_logged', 5 ) );
        $rate_guest  = max( 1, (int) get_option( 'lobbychat_rate_guest',  15 ) );
        $rate        = $uid ? $rate_logged : $rate_guest;
        if ( LobbyChat_DB::count_recent( $ip, $uid, $rate ) > 0 ) {
            self::err( __( 'Slow down! Wait a moment before posting again.', 'lobbychat' ), 429 );
        }

        // Link handling.
        $link_preview_json = null;
        if ( $link_url ) {
            if ( ! $allow_links ) self::err( __( 'Links are disabled.', 'lobbychat' ) );
            if ( ! $uid ) self::err( __( 'Please log in to share links.', 'lobbychat' ) );
            $link_cooldown = max( 0, (int) get_option( 'lobbychat_link_cooldown', 300 ) );
            if ( $link_cooldown > 0 && LobbyChat_DB::count_recent_links( $uid, $link_cooldown ) > 0 ) {
                self::err( sprintf(
                    /* translators: %d minutes */
                    __( 'You can share a link once every %d minutes.', 'lobbychat' ),
                    max( 1, intval( $link_cooldown / 60 ) )
                ) );
            }
            // Optional host whitelist via filter (defaults to allowing all hosts).
            $allowed = apply_filters( 'lobbychat_allowed_link_hosts', null );
            if ( is_array( $allowed ) && ! empty( $allowed ) ) {
                $host = strtolower( wp_parse_url( $link_url, PHP_URL_HOST ) ?? '' );
                if ( ! in_array( $host, $allowed, true ) ) {
                    self::err( __( 'That link host is not allowed.', 'lobbychat' ) );
                }
            }
            $preview = self::fetch_link_preview( $link_url );
            if ( $preview ) $link_preview_json = wp_json_encode( $preview );
        } else {
            $link_url = null;
        }

        // Insert.
        $id = LobbyChat_DB::insert([
            'user_id'      => $uid ?: null,
            'guest_name'   => $guest_name ?: null,
            'message'      => $message,
            'link_url'     => $link_url,
            'link_preview' => $link_preview_json,
            'ip_address'   => $ip,
            'created_at'   => current_time( 'mysql' ),
        ]);

        if ( ! $id ) self::err( __( 'Failed to save message.', 'lobbychat' ), 500 );

        $row = LobbyChat_DB::get_message( $id );
        if ( ! $row ) self::err( __( 'Message saved but could not retrieve it.', 'lobbychat' ), 500 );

        if ( $uid ) {
            $user_obj = get_userdata( $uid );
            $row->display_name = ( $user_obj && $user_obj->display_name ) ? $user_obj->display_name : 'User';
        } else {
            $row->display_name = $guest_name ?: 'Guest';
        }

        // Fire hook defensively — third-party listeners shouldn't break sends.
        try {
            do_action( 'lobbychat_after_send', $uid, $id, $row );
        } catch ( \Throwable $e ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( 'lobbychat_after_send hook failed: ' . $e->getMessage() );
        }

        self::ok([ 'message' => self::format_message( $row ) ]);
    }

    /* ── REACT ────────────────────────────────────────── */

    public static function lobbychat_react() {
        self::check_nonce();
        $uid = get_current_user_id();
        $ip  = self::get_ip();
        // Nonce verified above.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $message_id = isset( $_POST['message_id'] ) ? intval( $_POST['message_id'] ) : 0;
        $emoji      = isset( $_POST['emoji'] )
            ? sanitize_text_field( wp_unslash( $_POST['emoji'] ) )
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $allowed_emojis = (array) apply_filters( 'lobbychat_allowed_reactions', [ '👍', '❤️', '😂', '🔥', '🎉' ] );
        if ( ! in_array( $emoji, $allowed_emojis, true ) ) self::err( __( 'Invalid reaction.', 'lobbychat' ) );

        $row = LobbyChat_DB::get_message( $message_id );
        if ( ! $row ) self::err( __( 'Not found.', 'lobbychat' ) );

        $reactions = $row->reactions ? json_decode( $row->reactions, true ) : [];
        $key       = $uid ? "u_{$uid}" : "ip_{$ip}";

        if ( ! isset( $reactions[ $emoji ] ) ) $reactions[ $emoji ] = [ 'count' => 0, 'users' => [] ];

        if ( in_array( $key, $reactions[ $emoji ]['users'], true ) ) {
            $reactions[ $emoji ]['users'] = array_values( array_filter(
                $reactions[ $emoji ]['users'],
                function( $u ) use ( $key ) { return $u !== $key; }
            ) );
            $reactions[ $emoji ]['count'] = max( 0, $reactions[ $emoji ]['count'] - 1 );
        } else {
            $reactions[ $emoji ]['users'][] = $key;
            $reactions[ $emoji ]['count']++;
        }

        LobbyChat_DB::update_reactions( $message_id, $reactions );
        self::ok([ 'reactions' => $reactions, 'user_key' => $key ]);
    }

    /* ── REPORT ───────────────────────────────────────── */

    public static function lobbychat_report() {
        self::check_nonce();
        $uid = get_current_user_id();
        $ip  = self::get_ip();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $message_id = isset( $_POST['message_id'] ) ? intval( $_POST['message_id'] ) : 0;
        $threshold  = max( 1, (int) apply_filters( 'lobbychat_report_threshold', 5 ) );
        $count      = LobbyChat_DB::report_message( $message_id, $uid, $ip );
        if ( $count >= $threshold ) LobbyChat_DB::delete_message( $message_id );
        self::ok([ 'reported' => true, 'count' => $count ]);
    }

    /* ── DELETE ───────────────────────────────────────── */

    public static function lobbychat_delete() {
        self::check_nonce();
        $uid = get_current_user_id();
        $ip  = self::get_ip();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $message_id = isset( $_POST['message_id'] ) ? intval( $_POST['message_id'] ) : 0;
        $row        = LobbyChat_DB::get_message( $message_id );
        if ( ! $row ) self::err( __( 'Not found.', 'lobbychat' ) );

        $is_owner   = ( $uid && (int) $row->user_id === $uid ) || ( ! $uid && $row->ip_address === $ip );
        $within_min = ( current_time( 'timestamp' ) - strtotime( $row->created_at ) ) < 60;

        if ( ! self::is_mod( $uid ) && ! ( $is_owner && $within_min ) ) {
            self::err( __( 'Not authorised.', 'lobbychat' ), 403 );
        }

        LobbyChat_DB::delete_message( $message_id );
        self::ok([ 'deleted' => $message_id ]);
    }

    /* ── PIN / UNPIN ──────────────────────────────────── */

    public static function lobbychat_pin() {
        self::check_nonce();
        if ( ! self::is_mod( get_current_user_id() ) ) self::err( __( 'Not authorised.', 'lobbychat' ), 403 );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $message_id = isset( $_POST['message_id'] ) ? intval( $_POST['message_id'] ) : 0;
        LobbyChat_DB::pin_message( $message_id );
        self::ok();
    }

    public static function lobbychat_unpin() {
        self::check_nonce();
        if ( ! self::is_mod( get_current_user_id() ) ) self::err( __( 'Not authorised.', 'lobbychat' ), 403 );
        LobbyChat_DB::unpin_all();
        self::ok();
    }

    /* ── Link previews ────────────────────────────────── */

    public static function fetch_preview_public( $url ) { return self::fetch_link_preview( $url ); }

    private static function fetch_link_preview( $url ) {
        $host = strtolower( wp_parse_url( $url, PHP_URL_HOST ) ?? '' );
        if ( in_array( $host, [ 'youtube.com', 'www.youtube.com', 'youtu.be' ], true ) ) {
            return self::youtube_preview( $url );
        }
        return self::og_preview( $url );
    }

    private static function youtube_preview( $url ) {
        preg_match( '/(?:v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $url, $m );
        $vid = $m[1] ?? '';
        if ( ! $vid ) return null;
        $res = wp_remote_get( "https://www.youtube.com/oembed?url=" . urlencode( $url ) . "&format=json", [ 'timeout' => 5 ] );
        if ( is_wp_error( $res ) ) return null;
        $data = json_decode( wp_remote_retrieve_body( $res ), true );
        return [
            'type'     => 'youtube',
            'title'    => sanitize_text_field( $data['title'] ?? '' ),
            'thumb'    => "https://img.youtube.com/vi/{$vid}/mqdefault.jpg",
            'author'   => sanitize_text_field( $data['author_name'] ?? '' ),
            'video_id' => $vid,
            'url'      => $url,
        ];
    }

    private static function og_preview( $url ) {
        $res = wp_remote_get( $url, [
            'timeout'    => 8,
            'user-agent' => 'Mozilla/5.0 (compatible; LobbyChatBot/1.0; +https://wordpress.org/plugins/lobbychat/)',
            'redirection' => 5,
        ] );
        if ( is_wp_error( $res ) ) {
            return self::fallback_preview( $url );
        }

        $html = wp_remote_retrieve_body( $res );
        if ( empty( $html ) ) {
            return self::fallback_preview( $url );
        }

        $title = self::extract_meta( $html, 'og:title' );
        $desc  = self::extract_meta( $html, 'og:description' );
        $thumb = self::extract_meta( $html, 'og:image' );

        // Twitter card fallbacks.
        if ( ! $title ) $title = self::extract_meta( $html, 'twitter:title' );
        if ( ! $desc  ) $desc  = self::extract_meta( $html, 'twitter:description' );
        if ( ! $thumb ) $thumb = self::extract_meta( $html, 'twitter:image' );

        // Plain <title> tag fallback.
        if ( ! $title && preg_match( '#<title[^>]*>([^<]+)</title>#i', $html, $m ) ) {
            $title = sanitize_text_field( html_entity_decode( trim( $m[1] ), ENT_QUOTES, 'UTF-8' ) );
        }

        // Plain <meta name="description"> fallback.
        if ( ! $desc ) {
            $desc = self::extract_meta( $html, 'description', 'name' );
        }

        // If we got NOTHING usable, return a bare-bones preview so the link still renders.
        if ( ! $title && ! $thumb ) {
            return self::fallback_preview( $url );
        }

        // If still no title, use the host as a title.
        if ( ! $title ) {
            $host = wp_parse_url( $url, PHP_URL_HOST ) ?: $url;
            $title = $host;
        }

        return [
            'type'  => 'og',
            'title' => $title,
            'desc'  => mb_substr( $desc, 0, 120 ),
            'thumb' => $thumb ? esc_url_raw( $thumb ) : '',
            'url'   => $url,
        ];
    }

    /**
     * Bare-bones preview when the linked page can't be scraped.
     * Ensures users never silently lose their link.
     */
    private static function fallback_preview( $url ) {
        $host = wp_parse_url( $url, PHP_URL_HOST );
        return [
            'type'  => 'og',
            'title' => $host ?: $url,
            'desc'  => '',
            'thumb' => '',
            'url'   => $url,
        ];
    }

    /**
     * Robust meta-tag extractor — handles either order
     * (property/content vs content/property) and either quote style.
     */
    private static function extract_meta( $html, $key, $attr = 'property' ) {
        $key_q = preg_quote( $key, '#' );
        // attr=key first, then content=value
        if ( preg_match(
            '#<meta[^>]+' . $attr . '\s*=\s*["\']' . $key_q . '["\'][^>]*?content\s*=\s*["\']([^"\'>]+)["\']#i',
            $html, $m
        ) ) {
            return sanitize_text_field( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
        }
        // content=value first, then attr=key
        if ( preg_match(
            '#<meta[^>]+content\s*=\s*["\']([^"\'>]+)["\'][^>]*?' . $attr . '\s*=\s*["\']' . $key_q . '["\']#i',
            $html, $m
        ) ) {
            return sanitize_text_field( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
        }
        return '';
    }
}
