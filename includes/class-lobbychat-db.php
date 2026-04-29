<?php
/**
 * LobbyChat database access layer.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class LobbyChat_DB {

    // LobbyChat owns its own tables (custom plugin tables, not WordPress core
    // tables). All table names come from internal constants — never user input —
    // so the dynamic-table-name and direct-DB-call warnings below are expected
    // by design. Caching is intentionally not used for live chat (would break
    // real-time message delivery). All user-supplied values are passed through
    // $wpdb->prepare() with placeholders.
    //
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
    // phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter

    const TABLE_MESSAGES = 'lobbychat_messages';
    const TABLE_REPORTS  = 'lobbychat_reports';
    const TABLE_ONLINE   = 'lobbychat_online';

    /**
     * Create / upgrade tables and seed default options.
     */
    public static function install() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $p       = $wpdb->prefix;

        $messages = "CREATE TABLE IF NOT EXISTS {$p}" . self::TABLE_MESSAGES . " (
            id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id      BIGINT UNSIGNED DEFAULT 0,
            guest_name   VARCHAR(50) DEFAULT NULL,
            message      VARCHAR(2000) NOT NULL,
            link_url     VARCHAR(500) DEFAULT NULL,
            link_preview TEXT DEFAULT NULL,
            reactions    TEXT DEFAULT NULL,
            is_pinned    TINYINT(1) DEFAULT 0,
            is_deleted   TINYINT(1) DEFAULT 0,
            ip_address   VARCHAR(45) DEFAULT NULL,
            created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_created (created_at),
            INDEX idx_status (is_deleted, is_pinned)
        ) $charset;";

        $reports = "CREATE TABLE IF NOT EXISTS {$p}" . self::TABLE_REPORTS . " (
            id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            message_id BIGINT UNSIGNED NOT NULL,
            user_id    BIGINT UNSIGNED DEFAULT 0,
            ip_address VARCHAR(45) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_report (message_id, ip_address)
        ) $charset;";

        $online = "CREATE TABLE IF NOT EXISTS {$p}" . self::TABLE_ONLINE . " (
            id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id      BIGINT UNSIGNED DEFAULT 0,
            ip_address   VARCHAR(45) NOT NULL,
            user_type    VARCHAR(20) DEFAULT 'guest',
            display_name VARCHAR(100) DEFAULT NULL,
            user_agent   VARCHAR(255) DEFAULT NULL,
            last_seen    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_visitor (ip_address)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $messages );
        dbDelta( $reports );
        dbDelta( $online );

        // Drop the `tag` column if it exists from an older 1.x dev install.
        $msg_table = $p . self::TABLE_MESSAGES;
        $cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$msg_table}`" );
        if ( in_array( 'tag', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE `{$msg_table}` DROP COLUMN `tag`" );
        }

        // Seed defaults (only if not set — never overwrites user preferences).
        add_option( 'lobbychat_max_length',     500 );
        add_option( 'lobbychat_rate_logged',    5 );
        add_option( 'lobbychat_rate_guest',     15 );
        add_option( 'lobbychat_link_cooldown',  300 );
        add_option( 'lobbychat_allow_guests',   1 );
        add_option( 'lobbychat_allow_links',    1 );
        add_option( 'lobbychat_poll_interval',  30000 );
        add_option( 'lobbychat_prune_days',     30 );
        add_option( 'lobbychat_show_branding',  1 );
        add_option( 'lobbychat_blocklist',      'fuck,shit,cunt,nigger,faggot' );
    }

    /* ── Messages ──────────────────────────────────────── */

    public static function get_messages( $limit = 40, $since_id = 0 ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_MESSAGES;
        if ( $since_id > 0 ) {
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT s.*, u.display_name, u.user_email
                 FROM {$table} s
                 LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
                 WHERE s.is_deleted = 0 AND s.id > %d AND s.is_pinned = 0
                 ORDER BY s.is_pinned DESC, s.created_at DESC
                 LIMIT %d",
                $since_id, $limit
            ) );
        }
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT s.*, u.display_name, u.user_email
             FROM {$table} s
             LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
             WHERE s.is_deleted = 0
             ORDER BY s.is_pinned DESC, s.created_at DESC
             LIMIT %d",
            $limit
        ) );
    }

    public static function get_pinned() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_MESSAGES;
        return $wpdb->get_row(
            "SELECT s.*, u.display_name FROM {$table} s
             LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
             WHERE s.is_pinned = 1 AND s.is_deleted = 0
             ORDER BY s.created_at DESC LIMIT 1"
        );
    }

    public static function insert( $data ) {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . self::TABLE_MESSAGES, $data );
        return $wpdb->insert_id;
    }

    public static function get_message( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_MESSAGES;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d", $id
        ) );
    }

    public static function delete_message( $id ) {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . self::TABLE_MESSAGES, [ 'is_deleted' => 1 ], [ 'id' => $id ] );
    }

    public static function pin_message( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_MESSAGES;
        $wpdb->update( $table, [ 'is_pinned' => 0 ], [ 'is_pinned' => 1 ] );
        $wpdb->update( $table, [ 'is_pinned' => 1 ], [ 'id' => $id ] );
    }

    public static function unpin_all() {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . self::TABLE_MESSAGES, [ 'is_pinned' => 0 ], [ 'is_pinned' => 1 ] );
    }

    public static function update_reactions( $id, $reactions ) {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . self::TABLE_MESSAGES,
            [ 'reactions' => wp_json_encode( $reactions ) ],
            [ 'id' => $id ]
        );
    }

    /* ── Rate limiting (timezone-safe) ─────────────────── */

    public static function count_recent( $ip, $user_id, $seconds = 10 ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_MESSAGES;
        // Use current_time('mysql') to match the timezone used on insert.
        $since = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - $seconds );
        if ( $user_id ) {
            return (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                 WHERE user_id = %d AND created_at > %s AND is_deleted = 0",
                $user_id, $since
            ) );
        }
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE ip_address = %s AND created_at > %s AND is_deleted = 0",
            $ip, $since
        ) );
    }

    public static function count_recent_links( $user_id, $seconds = 300 ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_MESSAGES;
        $since = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - $seconds );
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE user_id = %d AND link_url IS NOT NULL AND created_at > %s AND is_deleted = 0",
            $user_id, $since
        ) );
    }

    /* ── Online presence ───────────────────────────────── */

    public static function ping_online( $user_id, $ip, $user_type = 'guest', $display_name = null, $user_agent = null ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_ONLINE;
        $wpdb->replace( $table, [
            'user_id'      => $user_id,
            'ip_address'   => $ip,
            'user_type'    => $user_type,
            'display_name' => $display_name,
            'user_agent'   => $user_agent ? substr( $user_agent, 0, 255 ) : null,
            'last_seen'    => current_time( 'mysql' ),
        ] );
        // Clean entries older than 5 minutes.
        $cutoff = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - 300 );
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE last_seen < %s",
            $cutoff
        ) );
    }

    public static function get_online_count() {
        global $wpdb;
        $table  = $wpdb->prefix . self::TABLE_ONLINE;
        $cutoff = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - 300 );
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE last_seen >= %s",
            $cutoff
        ) );
    }

    public static function get_online_breakdown() {
        global $wpdb;
        $table  = $wpdb->prefix . self::TABLE_ONLINE;
        $cutoff = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - 300 );
        $rows   = $wpdb->get_results( $wpdb->prepare(
            "SELECT user_id, user_type, display_name FROM {$table}
             WHERE last_seen >= %s ORDER BY user_type, display_name",
            $cutoff
        ) );
        $out = [
            'admins'  => [],
            'mods'    => [],
            'members' => [],
            'guests'  => 0,
            'bots'    => [],
            'total'   => 0,
        ];
        foreach ( $rows as $r ) {
            $out['total']++;
            switch ( $r->user_type ) {
                case 'admin':
                    $out['admins'][] = [ 'id' => (int) $r->user_id, 'name' => $r->display_name ];
                    break;
                case 'mod':
                    $out['mods'][] = [ 'id' => (int) $r->user_id, 'name' => $r->display_name ];
                    break;
                case 'member':
                    $out['members'][] = [ 'id' => (int) $r->user_id, 'name' => $r->display_name ];
                    break;
                case 'bot':
                    $name = $r->display_name ?: 'Bot';
                    if ( ! in_array( $name, array_column( $out['bots'], 'name' ), true ) ) {
                        $out['bots'][] = [ 'name' => $name ];
                    }
                    break;
                case 'guest':
                default:
                    $out['guests']++;
                    break;
            }
        }
        return $out;
    }

    /* ── Reports ───────────────────────────────────────── */

    public static function report_message( $message_id, $user_id, $ip ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_REPORTS;
        $wpdb->replace( $table, [
            'message_id' => $message_id,
            'user_id'    => $user_id,
            'ip_address' => $ip,
        ] );
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE message_id = %d",
            $message_id
        ) );
    }

    /* ── Cleanup ───────────────────────────────────────── */

    public static function prune( $days = 30 ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}" . self::TABLE_MESSAGES . "
             WHERE is_pinned = 0 AND created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ) );
    }
}
