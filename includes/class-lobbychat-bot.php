<?php
/**
 * LobbyChat — AI Bot
 *
 * An optional, opt-in AI assistant that replies in chat. The user supplies their own
 * Gemini and/or OpenAI API key in the bot settings page. Disabled by default.
 *
 * Architecture:
 *   - lobbychat_after_send fires → maybe_reply() runs synchronously (fast checks)
 *   - If trigger matches → wp_schedule_single_event with random 3-12s delay
 *   - send_reply() runs via WP cron, calls AI, posts message as bot user
 *
 * @package LobbyChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LobbyChat_Bot {

	const OPT_LAST_REPLY    = 'lobbychat_bot_last_reply';
	const OPT_HOURLY_BUCKET = 'lobbychat_bot_hourly_bucket';
	const OPT_DAILY_BUCKET  = 'lobbychat_bot_daily_bucket';

	public static function init() {
		add_action( 'lobbychat_after_send',   [ __CLASS__, 'maybe_reply' ], 20, 2 );
		add_action( 'lobbychat_bot_send_reply', [ __CLASS__, 'send_reply' ], 10, 2 );
	}

	private static function dbg( $msg ) {
		if ( get_option( 'lobbychat_bot_debug', 0 ) ) {
			error_log( '[LobbyChat Bot] ' . $msg );
		}
	}

	/* ─────────────────────────────────────────────────────────
	   SYNC: decide whether to schedule a reply.
	   Must be fast and never throw — runs in user's request.
	───────────────────────────────────────────────────────── */
	public static function maybe_reply( $sender_uid, $message_id ) {
		try {
			if ( ! get_option( 'lobbychat_bot_enabled', 0 ) ) { self::dbg( 'skip: bot disabled' ); return; }

			$bot_uid = (int) get_option( 'lobbychat_bot_user_id', 0 );
			if ( ! $bot_uid ) { self::dbg( 'skip: bot user not set' ); return; }

			if ( $sender_uid && (int) $sender_uid === $bot_uid ) { self::dbg( 'skip: sender is bot' ); return; }

			$msg = LobbyChat_DB::get_message( $message_id );
			if ( ! $msg ) { self::dbg( 'skip: message not found id=' . $message_id ); return; }
			if ( (int) $msg->user_id === $bot_uid ) { self::dbg( 'skip: message author is bot' ); return; }

			$text = trim( $msg->message );
			if ( mb_strlen( $text ) < 4 ) { self::dbg( 'skip: too short: ' . $text ); return; }

			if ( ! self::within_active_hours( get_option( 'lobbychat_bot_active_hours', '0-23' ) ) ) {
				self::dbg( 'skip: outside active hours' ); return;
			}

			$cooldown = (int) get_option( 'lobbychat_bot_cooldown', 30 );
			$last     = (int) get_option( self::OPT_LAST_REPLY, 0 );
			if ( $last && ( time() - $last ) < $cooldown ) {
				self::dbg( 'skip: cooldown ' . ( $cooldown - ( time() - $last ) ) . 's left' ); return;
			}

			if ( ! self::under_rate_limit() ) { self::dbg( 'skip: rate limit hit' ); return; }

			if ( ! self::should_trigger( $text ) ) { self::dbg( 'skip: no trigger for: ' . $text ); return; }

			$delay = mt_rand( 3, 12 );
			$scheduled = wp_schedule_single_event(
				time() + $delay,
				'lobbychat_bot_send_reply',
				[ (int) $message_id, (int) $sender_uid ]
			);
			self::dbg( 'scheduled reply in ' . $delay . 's, msg_id=' . $message_id . ', wp_schedule=' . var_export( $scheduled, true ) );

			// Reserve the cooldown slot now to prevent burst-scheduling.
			update_option( self::OPT_LAST_REPLY, time(), false );

		} catch ( \Throwable $e ) {
			error_log( 'LobbyChat Bot maybe_reply failed: ' . $e->getMessage() );
		}
	}

	/* ─────────────────────────────────────────────────────────
	   TRIGGER DETECTION
	───────────────────────────────────────────────────────── */
	private static function should_trigger( $text ) {
		$low = strtolower( $text );

		// Mentions — always reply.
		$bot_uid  = (int) get_option( 'lobbychat_bot_user_id', 0 );
		$bot_user = $bot_uid ? get_userdata( $bot_uid ) : null;
		$mentions = [ '@bot' ];
		if ( $bot_user ) {
			$mentions[] = '@' . strtolower( $bot_user->user_login );
			if ( $bot_user->display_name ) {
				$mentions[] = '@' . strtolower( str_replace( ' ', '', $bot_user->display_name ) );
			}
		}
		foreach ( $mentions as $m ) {
			if ( strpos( $low, $m ) !== false ) {
				return true;
			}
		}

		// Questions.
		if ( get_option( 'lobbychat_bot_reply_questions', 1 ) ) {
			$is_q = ( substr( $text, -1 ) === '?' )
				|| preg_match( '/^(what|who|when|where|why|how|which|is|are|do|does|can|could|would|should)\b/i', $text )
				|| preg_match( '/\b(anyone|anybody|someone)\b/i', $low );
			if ( $is_q ) {
				$chance = (int) get_option( 'lobbychat_bot_question_chance', 75 );
				if ( mt_rand( 1, 100 ) <= $chance ) {
					return true;
				}
			}
		}

		// Random chance for normal messages.
		$chance = (int) get_option( 'lobbychat_bot_random_chance', 8 );
		return ( mt_rand( 1, 100 ) <= $chance );
	}

	/* ─────────────────────────────────────────────────────────
	   ACTIVE HOURS — supports overnight ranges like "22-6"
	───────────────────────────────────────────────────────── */
	private static function within_active_hours( $range ) {
		if ( ! preg_match( '/^(\d{1,2})-(\d{1,2})$/', trim( $range ), $m ) ) {
			return true;
		}
		$start = (int) $m[1];
		$end   = (int) $m[2];
		$now_h = (int) current_time( 'G' );
		if ( $start === $end ) { return true; }
		if ( $start < $end )   { return ( $now_h >= $start && $now_h < $end ); }
		return ( $now_h >= $start || $now_h < $end );
	}

	/* ─────────────────────────────────────────────────────────
	   RATE LIMITS — hourly + daily
	───────────────────────────────────────────────────────── */
	private static function under_rate_limit() {
		$hourly_max = (int) get_option( 'lobbychat_bot_max_per_hour', 30 );
		$daily_max  = (int) get_option( 'lobbychat_bot_max_per_day',  200 );

		$h = get_option( self::OPT_HOURLY_BUCKET, [ 'hour' => 0,  'count' => 0 ] );
		$d = get_option( self::OPT_DAILY_BUCKET,  [ 'day'  => '', 'count' => 0 ] );
		if ( ! is_array( $h ) ) { $h = [ 'hour' => 0,  'count' => 0 ]; }
		if ( ! is_array( $d ) ) { $d = [ 'day'  => '', 'count' => 0 ]; }

		$cur_hour = (int) ( time() / 3600 );
		$cur_day  = current_time( 'Y-m-d' );

		if ( $h['hour'] !== $cur_hour ) { $h = [ 'hour' => $cur_hour, 'count' => 0 ]; }
		if ( $d['day']  !== $cur_day  ) { $d = [ 'day'  => $cur_day,  'count' => 0 ]; }

		if ( $h['count'] >= $hourly_max ) { return false; }
		if ( $d['count'] >= $daily_max  ) { return false; }
		return true;
	}

	private static function bump_rate_counters() {
		$cur_hour = (int) ( time() / 3600 );
		$cur_day  = current_time( 'Y-m-d' );

		$h = get_option( self::OPT_HOURLY_BUCKET, [ 'hour' => 0,  'count' => 0 ] );
		$d = get_option( self::OPT_DAILY_BUCKET,  [ 'day'  => '', 'count' => 0 ] );
		if ( ! is_array( $h ) ) { $h = [ 'hour' => 0,  'count' => 0 ]; }
		if ( ! is_array( $d ) ) { $d = [ 'day'  => '', 'count' => 0 ]; }

		if ( $h['hour'] !== $cur_hour ) { $h = [ 'hour' => $cur_hour, 'count' => 0 ]; }
		if ( $d['day']  !== $cur_day  ) { $d = [ 'day'  => $cur_day,  'count' => 0 ]; }

		$h['count']++;
		$d['count']++;
		update_option( self::OPT_HOURLY_BUCKET, $h, false );
		update_option( self::OPT_DAILY_BUCKET,  $d, false );
	}

	/* ─────────────────────────────────────────────────────────
	   ASYNC: cron fires this — fetch context, call AI, post reply
	───────────────────────────────────────────────────────── */
	public static function send_reply( $message_id, $sender_uid ) {
		try {
			$bot_uid = (int) get_option( 'lobbychat_bot_user_id', 0 );
			if ( ! $bot_uid ) { return; }
			if ( ! get_option( 'lobbychat_bot_enabled', 0 ) ) { self::dbg( 'send_reply skip: bot disabled' ); return; }

			$context = LobbyChat_DB::get_messages( 6, 0 );
			if ( empty( $context ) ) { self::dbg( 'send_reply abort: no context' ); return; }

			$context = array_reverse( $context );

			$convo_lines = [];
			foreach ( $context as $m ) {
				$name = self::display_name_for( $m );
				$msg_clean = trim( preg_replace( '/\s+/', ' ', $m->message ) );
				$convo_lines[] = $name . ': ' . $msg_clean;
			}
			$conversation = implode( "\n", $convo_lines );

			$reply = self::generate_reply( $conversation );
			if ( ! $reply ) { self::dbg( 'send_reply abort: AI returned no reply' ); return; }

			$bot_user = get_userdata( $bot_uid );
			if ( ! $bot_user ) { self::dbg( 'send_reply abort: bot user not found' ); return; }

			$insert_id = LobbyChat_DB::insert( [
				'user_id'      => $bot_uid,
				'guest_name'   => null,
				'message'      => $reply,
				'link_url'     => null,
				'link_preview' => null,
				'ip_address'   => '127.0.0.1',
				'created_at'   => current_time( 'mysql' ),
			] );

			if ( $insert_id ) {
				self::bump_rate_counters();
				self::dbg( 'send_reply SUCCESS msg #' . $insert_id . ': ' . $reply );
			} else {
				self::dbg( 'send_reply: DB insert returned no id' );
			}

		} catch ( \Throwable $e ) {
			error_log( '[LobbyChat Bot] send_reply EXCEPTION: ' . $e->getMessage() );
		}
	}

	/**
	 * Safe display-name lookup — never chain-fatals.
	 */
	private static function display_name_for( $row ) {
		if ( $row->user_id ) {
			$u = get_userdata( $row->user_id );
			return $u && $u->display_name ? $u->display_name : 'User';
		}
		return $row->guest_name ? $row->guest_name : 'Guest';
	}

	/* ─────────────────────────────────────────────────────────
	   AI CALL — Gemini primary, OpenAI fallback
	───────────────────────────────────────────────────────── */
	private static function generate_reply( $conversation ) {
		$persona = self::get_system_prompt();

		$reply = self::call_gemini( $persona, $conversation );
		if ( $reply ) { return self::clean_reply( $reply ); }

		$reply = self::call_openai( $persona, $conversation );
		if ( $reply ) { return self::clean_reply( $reply ); }

		return null;
	}

	public static function get_system_prompt() {
		$custom = get_option( 'lobbychat_bot_custom_prompt', '' );
		if ( $custom ) { return $custom; }

		$bot_name = (string) get_option( 'lobbychat_bot_name', 'Helper' );
		$site     = get_bloginfo( 'name' );

		return "You are {$bot_name}, a friendly chat companion in the live shoutbox on the website \"{$site}\". You're here to chat casually, answer quick questions, and keep the conversation lively.

PERSONALITY RULES:
- Keep replies SHORT — usually under 120 characters. Like a quick chat message, not a paragraph.
- Be casual and warm. Lowercase is fine. Light emoji OK (max 1 per reply), but don't overdo it.
- React to what people are actually saying. Ask a follow-up sometimes.
- Don't lecture. Don't moralize. Don't disclaim. NEVER say 'as an AI', 'I'm a bot', 'I cannot', or break character.
- Don't flirt. Don't be romantic.
- If you genuinely don't know something, just say 'not sure' or 'no idea, sorry'. Don't make things up.
- Don't promote anything. Don't shill. Be a friend, not a tool.
- IMPORTANT: Always finish your sentence properly. No mid-word cutoffs. End with proper punctuation.

You will be given the recent shoutbox conversation. Reply ONLY as {$bot_name} would naturally reply to the latest message. Output just the reply text, nothing else — no quotes, no '{$bot_name}:' prefix, no explanation.";
	}

	/* ── Gemini ─────────────────────────────────────────── */
	private static function call_gemini( $persona, $conversation ) {
		$api_key = get_option( 'lobbychat_bot_gemini_key', '' );
		if ( ! $api_key ) { return null; }

		$model = (string) get_option( 'lobbychat_bot_gemini_model', 'gemini-2.5-flash' );
		$model = $model ? $model : 'gemini-2.5-flash';
		$url   = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent?key=' . $api_key;

		$user_prompt = "Recent shoutbox conversation:\n\n" . $conversation . "\n\nReply naturally to the LAST message. One short reply only. Make sure your sentence is complete.";

		$body = [
			'contents' => [
				[ 'role' => 'user', 'parts' => [ [ 'text' => $user_prompt ] ] ],
			],
			'systemInstruction' => [
				'parts' => [ [ 'text' => $persona ] ],
			],
			'generationConfig' => [
				'temperature'     => 0.95,
				'maxOutputTokens' => 200,
				'topP'            => 0.95,
			],
			'safetySettings' => [
				[ 'category' => 'HARM_CATEGORY_HARASSMENT',        'threshold' => 'BLOCK_ONLY_HIGH' ],
				[ 'category' => 'HARM_CATEGORY_HATE_SPEECH',       'threshold' => 'BLOCK_ONLY_HIGH' ],
				[ 'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_ONLY_HIGH' ],
				[ 'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_ONLY_HIGH' ],
			],
		];

		$response = wp_remote_post( $url, [
			'timeout' => 25,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( $body ),
		] );

		if ( is_wp_error( $response ) ) {
			error_log( 'LobbyChat Bot Gemini error: ' . $response->get_error_message() );
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || isset( $data['error'] ) ) {
			$err = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Unknown';
			error_log( 'LobbyChat Bot Gemini failed (' . $code . '): ' . $err );
			return null;
		}

		$text = isset( $data['candidates'][0]['content']['parts'][0]['text'] )
			? $data['candidates'][0]['content']['parts'][0]['text']
			: '';
		return $text ? trim( $text ) : null;
	}

	/* ── OpenAI fallback ────────────────────────────────── */
	private static function call_openai( $persona, $conversation ) {
		$api_key = get_option( 'lobbychat_bot_openai_key', '' );
		if ( ! $api_key ) { return null; }

		$model = (string) get_option( 'lobbychat_bot_openai_model', 'gpt-4o-mini' );
		$model = $model ? $model : 'gpt-4o-mini';

		$user_prompt = "Recent shoutbox conversation:\n\n" . $conversation . "\n\nReply naturally to the LAST message. One short reply only. Make sure your sentence is complete.";

		$body = [
			'model'       => $model,
			'messages'    => [
				[ 'role' => 'system', 'content' => $persona ],
				[ 'role' => 'user',   'content' => $user_prompt ],
			],
			'temperature' => 0.95,
			'max_tokens'  => 150,
		];

		$response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
			'timeout' => 25,
			'headers' => [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			],
			'body' => wp_json_encode( $body ),
		] );

		if ( is_wp_error( $response ) ) {
			error_log( 'LobbyChat Bot OpenAI error: ' . $response->get_error_message() );
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || isset( $data['error'] ) ) {
			$err = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Unknown';
			error_log( 'LobbyChat Bot OpenAI failed (' . $code . '): ' . $err );
			return null;
		}

		$text = isset( $data['choices'][0]['message']['content'] )
			? $data['choices'][0]['message']['content']
			: '';
		return $text ? trim( $text ) : null;
	}

	/* ─────────────────────────────────────────────────────────
	   CLEAN + VALIDATE REPLY
	───────────────────────────────────────────────────────── */
	private static function clean_reply( $text ) {
		if ( ! $text ) { return null; }

		$text = trim( $text, " \t\n\r\0\x0B\"'`" );

		// Strip "Bot:" or "<bot_name>:" prefix.
		$bot_name = strtolower( (string) get_option( 'lobbychat_bot_name', 'Helper' ) );
		$bot_name_safe = preg_quote( $bot_name, '/' );
		$text = preg_replace( '/^(bot|' . $bot_name_safe . ')\s*:\s*/i', '', $text );

		$text = trim( $text, " *_" );
		$text = preg_replace( '/\s+/', ' ', $text );
		$text = trim( $text );

		// Reject obvious meta replies.
		$low = strtolower( $text );
		$forbidden = [ 'as an ai', "i'm a bot", 'i am a bot', "i can't", 'i cannot', 'i am an ai' ];
		foreach ( $forbidden as $f ) {
			if ( strpos( $low, $f ) !== false ) { return null; }
		}

		// Hard cap length.
		$max_len = (int) get_option( 'lobbychat_bot_max_chars', 200 );
		if ( $max_len < 50 )  { $max_len = 50; }
		if ( $max_len > 500 ) { $max_len = 500; }

		if ( mb_strlen( $text ) > $max_len ) {
			$trimmed = mb_substr( $text, 0, $max_len );
			$last_punct = max(
				(int) mb_strrpos( $trimmed, '.' ),
				(int) mb_strrpos( $trimmed, '!' ),
				(int) mb_strrpos( $trimmed, '?' )
			);
			if ( $last_punct > $max_len * 0.5 ) {
				$text = mb_substr( $trimmed, 0, $last_punct + 1 );
			} else {
				$last_space = mb_strrpos( $trimmed, ' ' );
				$text = ( $last_space !== false )
					? mb_substr( $trimmed, 0, $last_space ) . '…'
					: $trimmed . '…';
			}
		}

		if ( ! self::looks_complete( $text ) ) {
			error_log( 'LobbyChat Bot rejected incomplete reply: ' . $text );
			return null;
		}

		if ( mb_strlen( $text ) < 2 ) { return null; }

		return $text;
	}

	private static function looks_complete( $text ) {
		$text = trim( $text );
		if ( '' === $text ) { return false; }

		$last_char = mb_substr( $text, -1 );

		$terminal = [ '.', '!', '?', '…', ')', ']', '"', "'", '~' ];
		if ( in_array( $last_char, $terminal, true ) ) {
			return self::last_word_ok( $text );
		}

		// Emoji at end — completeness signal.
		if ( preg_match( '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{1F600}-\x{1F64F}\x{1F900}-\x{1F9FF}]$/u', $text ) ) {
			return self::last_word_ok( mb_substr( $text, 0, -1 ) );
		}

		// Short conversational fragments are OK without punctuation.
		$short_ok = [ 'lol', 'lmao', 'ok', 'okay', 'gg', 'nice', 'cool', 'fr', 'ikr', 'yes', 'no', 'yeah', 'nope', 'yep', 'haha' ];
		$low = strtolower( $text );
		foreach ( $short_ok as $s ) {
			if ( $low === $s ) { return true; }
		}

		return false;
	}

	private static function last_word_ok( $text ) {
		$stripped = preg_replace( '/[^\p{L}\p{N}\'\s-]+$/u', '', $text );
		$stripped = trim( $stripped );
		if ( '' === $stripped ) { return true; }

		$words = preg_split( '/\s+/', $stripped );
		$last  = end( $words );
		if ( ! $last ) { return true; }

		$known_single = [ 'a', 'i' ];
		$low = strtolower( $last );
		if ( 1 === mb_strlen( $last ) ) {
			return in_array( $low, $known_single, true );
		}

		if ( 'hm' === $low || 'hmm' === $low ) { return true; }

		$truncation_patterns = [
			'/^th$/i', '/^thi$/i', '/^wh$/i', '/^wha$/i', '/^bec$/i', '/^becau$/i', '/^becaus$/i',
			'/^bcz$/i', '/^prob$/i', '/^def$/i', '/^abso$/i',
		];
		foreach ( $truncation_patterns as $pat ) {
			if ( preg_match( $pat, $last ) ) { return false; }
		}

		return true;
	}

	/**
	 * Manual trigger — used by admin "Test Reply Now" button.
	 */
	public static function manual_trigger() {
		$bot_uid = (int) get_option( 'lobbychat_bot_user_id', 0 );
		if ( ! $bot_uid ) {
			return [ 'ok' => false, 'error' => 'Bot user not configured.' ];
		}

		$context = LobbyChat_DB::get_messages( 6, 0 );
		if ( empty( $context ) ) {
			return [ 'ok' => false, 'error' => 'No messages in chat to reply to. Post something first.' ];
		}
		$context = array_reverse( $context );

		$convo_lines = [];
		foreach ( $context as $m ) {
			if ( (int) $m->user_id === $bot_uid ) { continue; }
			$convo_lines[] = self::display_name_for( $m ) . ': ' . trim( preg_replace( '/\s+/', ' ', $m->message ) );
		}
		if ( empty( $convo_lines ) ) {
			return [ 'ok' => false, 'error' => 'No non-bot messages to reply to.' ];
		}
		$conversation = implode( "\n", $convo_lines );

		$reply = self::generate_reply( $conversation );
		if ( ! $reply ) {
			return [ 'ok' => false, 'error' => 'AI returned no usable reply (check error log for details).' ];
		}

		$insert_id = LobbyChat_DB::insert( [
			'user_id'      => $bot_uid,
			'guest_name'   => null,
			'message'      => $reply,
			'link_url'     => null,
			'link_preview' => null,
			'ip_address'   => '127.0.0.1',
			'created_at'   => current_time( 'mysql' ),
		] );

		if ( ! $insert_id ) {
			return [ 'ok' => false, 'error' => 'DB insert failed.' ];
		}

		self::bump_rate_counters();
		return [ 'ok' => true, 'reply' => $reply, 'msg_id' => $insert_id ];
	}
}
