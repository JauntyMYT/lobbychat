/**
 * LobbyChat — Frontend script
 * No external dependencies beyond jQuery (already a WP core dep).
 */
/* jshint esversion: 6 */
(function ($) {
	'use strict';

	if ( typeof LobbyChat === 'undefined' ) {
		if ( window.console && console.warn ) {
			console.warn( 'LobbyChat: window.LobbyChat is undefined. The script ran but the localized data is missing. This usually means the lobbychat script handle was enqueued without wp_localize_script firing. Check that the LobbyChat plugin is active and that no caching plugin is stripping inline scripts.' );
		}
		return;
	}

	const I18N = LobbyChat.i18n || {};

	const App = {
		sinceId:    0,
		polling:    null,
		collapsed:  false,
		soundOn:    localStorage.getItem('lobbychat_sound') !== 'off',
		isScrolled: false,
		isMod:      false,
		userId:     LobbyChat.user_id,
		activePicker: null,
		linkMode:   false,
		lastSent:   0,
		// client-side cooldown — slightly longer than server rate so the button stays disabled until safe
		cooldown:   ( ( LobbyChat.user_id ? 5 : 15 ) * 1000 ) + 500,
		_lastPing:  0,
	};

	/* ── AJAX helper ───────────────────────────────────── */
	function ajax( action, data ) {
		const payload = $.extend( { action: action, nonce: LobbyChat.nonce }, data || {} );
		return $.ajax({
			url:      LobbyChat.ajax_url,
			method:   'POST',
			data:     payload,
			dataType: 'json',
			// Strip leading PHP notices/warnings before parsing JSON.
			converters: {
				'text json': function ( raw ) {
					const start = raw.indexOf('{');
					if ( start > 0 ) { raw = raw.substring( start ); }
					return JSON.parse( raw );
				}
			}
		});
	}

	/* ── Toast ─────────────────────────────────────────── */
	function toast( msg ) {
		$('.lobbychat-toast').remove();
		const $t = $('<div class="lobbychat-toast">').text( msg ).appendTo('body');
		setTimeout( function () { $t.fadeOut( 300, function () { $t.remove(); } ); }, 3000 );
	}

	/* ── Sound ─────────────────────────────────────────── */
	function playSound() {
		if ( ! App.soundOn ) { return; }
		try {
			const Ctx = window.AudioContext || window.webkitAudioContext;
			if ( ! Ctx ) { return; }
			const ctx  = new Ctx();
			const osc  = ctx.createOscillator();
			const gain = ctx.createGain();
			osc.connect( gain );
			gain.connect( ctx.destination );
			osc.frequency.value = 880;
			gain.gain.setValueAtTime( 0.1, ctx.currentTime );
			gain.gain.exponentialRampToValueAtTime( 0.001, ctx.currentTime + 0.15 );
			osc.start( ctx.currentTime );
			osc.stop( ctx.currentTime + 0.15 );
		} catch ( e ) {}
	}

	/* ── Highlight @mentions ───────────────────────────── */
	function highlightMentions( text ) {
		return text.replace( /@(\w+)/g, '<span class="lobbychat-mention">@$1</span>' );
	}

	/* ── Render link preview ───────────────────────────── */
	function renderPreview( preview ) {
		if ( ! preview ) { return ''; }
		const url = preview.url || '#';

		if ( preview.type === 'youtube' && preview.video_id ) {
			return '<div class="lobbychat-preview">'
				+ '<div class="lobbychat-yt-wrap" data-videoid="' + escapeAttr( preview.video_id ) + '">'
					+ '<img class="lobbychat-preview-img" src="' + escapeAttr( preview.thumb ) + '" alt="" loading="lazy">'
					+ '<div class="lobbychat-yt-play" aria-hidden="true">▶</div>'
				+ '</div>'
				+ '<div class="lobbychat-preview-body">'
					+ '<div class="lobbychat-preview-title">' + escapeHtml( preview.title || 'YouTube' ) + '</div>'
					+ '<div class="lobbychat-preview-type">▶ YouTube · ' + escapeHtml( preview.author || '' ) + '</div>'
				+ '</div>'
			+ '</div>';
		}

		const img = preview.thumb
			? '<img class="lobbychat-preview-img" src="' + escapeAttr( preview.thumb ) + '" alt="" loading="lazy">'
			: '';
		let host = '';
		try { host = new URL( url ).hostname; } catch ( e ) { host = ''; }
		return '<div class="lobbychat-preview">'
			+ '<a href="' + escapeAttr( url ) + '" target="_blank" rel="noopener noreferrer">'
				+ img
				+ '<div class="lobbychat-preview-body">'
					+ '<div class="lobbychat-preview-title">' + escapeHtml( preview.title || url ) + '</div>'
					+ ( preview.desc ? '<div class="lobbychat-preview-desc">' + escapeHtml( preview.desc ) + '</div>' : '' )
					+ '<div class="lobbychat-preview-type">🔗 ' + escapeHtml( host ) + '</div>'
				+ '</div>'
			+ '</a>'
		+ '</div>';
	}

	/* ── Render reactions ──────────────────────────────── */
	function renderReactions( reactions, msgId ) {
		const userKey = App.userId ? 'u_' + App.userId : null;
		let html = '<div class="lobbychat-reactions" data-msg-id="' + msgId + '">';

		if ( reactions ) {
			Object.keys( reactions ).forEach( function ( emoji ) {
				const data = reactions[ emoji ];
				if ( ! data || ! data.count ) { return; }
				const reacted = userKey && data.users && data.users.indexOf( userKey ) !== -1;
				html += '<button type="button" class="lobbychat-reaction-btn ' + ( reacted ? 'lobbychat-reacted' : '' ) + '" '
					+ 'data-emoji="' + escapeAttr( emoji ) + '" data-msg="' + msgId + '">'
					+ escapeHtml( emoji ) + ' <span class="lobbychat-reaction-count">' + data.count + '</span>'
					+ '</button>';
			});
		}

		html += '<button type="button" class="lobbychat-add-reaction" data-msg="' + msgId + '" title="' + escapeAttr( I18N.react || 'React' ) + '">＋</button>';
		html += '</div>';
		return html;
	}

	/* ── Render single message ─────────────────────────── */
	function renderMessage( m, isNew ) {
		const isOwn     = App.userId && parseInt( m.user_id, 10 ) === parseInt( App.userId, 10 );
		const canDelete = App.isMod || isOwn;

		let avatar = '';
		if ( m.avatar ) {
			avatar = '<img class="lobbychat-msg-avatar" src="' + escapeAttr( m.avatar ) + '" alt="" loading="lazy">';
		} else {
			const initial = ( m.name || 'G' )[0].toUpperCase();
			avatar = '<div class="lobbychat-msg-avatar-guest">' + escapeHtml( initial ) + '</div>';
		}

		const nameHtml = ( m.profile_url && m.user_id )
			? '<a class="lobbychat-msg-name" href="' + escapeAttr( m.profile_url ) + '">' + escapeHtml( m.name ) + '</a>'
			: '<span class="lobbychat-msg-name">' + escapeHtml( m.name ) + '</span>';

		const msgText   = highlightMentions( escapeHtml( m.message ) );
		// Render preview if we have one, otherwise show a bare link if link_url is set
		// (so the link is never silently lost when scraping fails).
		let preview = '';
		if ( m.preview ) {
			preview = renderPreview( m.preview );
		} else if ( m.link_url ) {
			preview = renderPreview({ type: 'og', title: m.link_url, desc: '', thumb: '', url: m.link_url });
		}
		const reactions = renderReactions( m.reactions, m.id );

		let actions = '';
		if ( canDelete ) {
			actions += '<button type="button" class="lobbychat-action-sm lobbychat-delete" data-action="delete" data-id="' + m.id + '">🗑 ' + escapeHtml( I18N.delete_label || 'Delete' ) + '</button>';
		}
		if ( App.isMod ) {
			actions += '<button type="button" class="lobbychat-action-sm" data-action="pin" data-id="' + m.id + '">📌 ' + escapeHtml( I18N.pin_label || 'Pin' ) + '</button>';
		}
		actions += '<button type="button" class="lobbychat-action-sm" data-action="report" data-id="' + m.id + '">⚑ ' + escapeHtml( I18N.report || 'Report' ) + '</button>';

		return '<div class="lobbychat-msg ' + ( isNew ? 'lobbychat-new' : '' ) + '" id="lobbychat-msg-' + m.id + '" data-id="' + m.id + '">'
			+ avatar
			+ '<div class="lobbychat-msg-content">'
				+ '<div class="lobbychat-msg-header">'
					+ nameHtml
					+ '<span class="lobbychat-msg-time">' + escapeHtml( m.time_ago ) + '</span>'
				+ '</div>'
				+ '<div class="lobbychat-msg-text">' + msgText + '</div>'
				+ preview
				+ reactions
				+ '<div class="lobbychat-msg-actions">' + actions + '</div>'
			+ '</div>'
		+ '</div>';
	}

	/* ── Cooldown timer on send button ─────────────────── */
	function startCooldown() {
		const $btn = $('#lobbychat-send-btn');
		const sendLabel = I18N.send || 'Send';
		let remaining = Math.ceil( App.cooldown / 1000 );
		$btn.prop( 'disabled', true ).text( 'Wait ' + remaining + 's' );
		const timer = setInterval( function () {
			remaining--;
			if ( remaining <= 0 ) {
				clearInterval( timer );
				$btn.prop( 'disabled', false ).text( sendLabel );
			} else {
				$btn.text( 'Wait ' + remaining + 's' );
			}
		}, 1000 );
	}

	/* ── Load initial messages ─────────────────────────── */
	function loadMessages() {
		ajax( 'lobbychat_get', { limit: 40 } )
			.done( function ( resp ) {
				if ( ! resp.success ) { return; }
				const d = resp.data;

				App.isMod = d.is_mod;

				$('#lobbychat-online-count').text( d.online );
				renderBreakdown( d.breakdown );

				if ( d.pinned ) {
					$('#lobbychat-pinned').show();
					$('#lobbychat-pinned-text').text( d.pinned.name + ': ' + d.pinned.message );
				}

				const $feed = $('#lobbychat-feed').empty();
				const msgs  = ( d.messages || [] ).slice().reverse();

				if ( ! msgs.length ) {
					$feed.html( '<div class="lobbychat-loading">' + escapeHtml( I18N.no_messages || 'No messages yet.' ) + '</div>' );
				} else {
					msgs.forEach( function ( m ) { $feed.append( renderMessage( m, false ) ); } );
					App.sinceId = Math.max.apply( null, msgs.map( function ( m ) { return m.id; } ) );
					scrollToBottom();
				}

				startPolling();
			})
			.fail( function () {
				$('#lobbychat-feed').html( '<div class="lobbychat-loading">Could not load messages.</div>' );
			});
	}

	/* ── Poll for new messages ─────────────────────────── */
	function startPolling() {
		if ( App.polling ) { clearInterval( App.polling ); }
		App.polling = setInterval( pollNew, LobbyChat.poll_interval || 30000 );
	}

	document.addEventListener( 'visibilitychange', function () {
		if ( document.hidden ) {
			if ( App.polling ) { clearInterval( App.polling ); App.polling = null; }
		} else {
			if ( ! App.polling ) { startPolling(); }
		}
	});

	function pollNew() {
		if ( document.hidden ) { return; }

		if ( App.collapsed ) {
			// Light ping every 2 minutes when collapsed.
			if ( ! App._lastPing || Date.now() - App._lastPing > 120000 ) {
				App._lastPing = Date.now();
				ajax( 'lobbychat_ping', {} ).done( function ( r ) {
					if ( r.success ) {
						$('#lobbychat-online-count').text( r.data.online );
						renderBreakdown( r.data.breakdown );
					}
				});
			}
			return;
		}

		ajax( 'lobbychat_get', { since_id: App.sinceId, limit: 20 } ).done( function ( resp ) {
			if ( ! resp.success ) { return; }
			const d = resp.data;

			$('#lobbychat-online-count').text( d.online );
			renderBreakdown( d.breakdown );

			const msgs = ( d.messages || [] ).slice().reverse();
			if ( msgs.length ) {
				const $feed = $('#lobbychat-feed');
				msgs.forEach( function ( m ) {
					if ( $('#lobbychat-msg-' + m.id).length ) { return; }
					$feed.append( renderMessage( m, true ) );
					playSound();
				});
				App.sinceId = Math.max.apply( null, [ App.sinceId ].concat( msgs.map( function ( m ) { return m.id; } ) ) );
				if ( ! App.isScrolled ) { scrollToBottom(); }
			}
		});
	}

	/* ── Send message ──────────────────────────────────── */
	function sendMessage() {
		const message   = ( $('#lobbychat-message').val() || '' ).trim();
		const $guest    = $('#lobbychat-guest-name');
		const guestName = $guest.length ? ( $guest.val() || '' ).trim() : '';
		const $linkUrl  = $('#lobbychat-link-url');
		const linkUrl   = ( App.linkMode && $linkUrl.length ) ? ( $linkUrl.val() || '' ).trim() : '';

		if ( ! message ) { toast( I18N.type_first || 'Type a message first.' ); return; }
		if ( ! LobbyChat.logged_in && LobbyChat.allow_guests && ! guestName ) { toast( I18N.enter_name || 'Enter your name.' ); return; }

		const now = Date.now();
		if ( App.lastSent > 0 && ( now - App.lastSent ) < App.cooldown ) { return; }

		const sendLabel = I18N.send || 'Send';
		const $btn = $('#lobbychat-send-btn').prop( 'disabled', true ).text( 'Sending…' );
		App.lastSent = Date.now();

		ajax( 'lobbychat_send', {
			message:    message,
			guest_name: guestName,
			link_url:   linkUrl
		}).done( function ( resp ) {
			if ( ! resp.success ) {
				toast( resp.data ? resp.data.message : 'Error sending message.' );
				App.lastSent = 0;
				$btn.prop( 'disabled', false ).text( sendLabel );
				return;
			}
			const m = resp.data.message;
			const $feed = $('#lobbychat-feed');
			$feed.find('.lobbychat-loading').remove();
			$feed.append( renderMessage( m, true ) );

			if ( m.id > App.sinceId ) { App.sinceId = m.id; }

			$('#lobbychat-message').val('');
			$('#lobbychat-char-count').text( LobbyChat.max_length || 500 ).removeClass('lobbychat-char-warn lobbychat-char-over');
			if ( App.linkMode ) {
				if ( $linkUrl.length ) { $linkUrl.val(''); }
				toggleLinkMode( false );
			}

			scrollToBottom();
		}).fail( function ( xhr ) {
			if ( xhr.status === 429 ) {
				$btn.prop( 'disabled', false ).text( sendLabel );
				return;
			}
			let msg = 'Network error. Try again.';
			try {
				const resp = JSON.parse( xhr.responseText );
				if ( resp && resp.data && resp.data.message ) { msg = resp.data.message; }
			} catch ( e ) {}
			toast( msg );
			App.lastSent = 0;
			$btn.prop( 'disabled', false ).text( sendLabel );
		}).always( function () {
			if ( App.lastSent > 0 && Date.now() - App.lastSent < App.cooldown ) {
				startCooldown();
			} else {
				$btn.prop( 'disabled', false ).text( sendLabel );
			}
		});
	}

	/* ── Scroll ────────────────────────────────────────── */
	function scrollToBottom() {
		const feed = document.getElementById('lobbychat-feed');
		if ( feed ) { feed.scrollTop = feed.scrollHeight; }
		App.isScrolled = false;
	}

	/* ── Link mode ─────────────────────────────────────── */
	function toggleLinkMode( force ) {
		App.linkMode = force !== undefined ? force : ! App.linkMode;
		$('#lobbychat-link-row').toggle( App.linkMode );
		$('#lobbychat-link-btn').toggleClass( 'active', App.linkMode );
	}

	/* ── Render online breakdown ───────────────────────── */
	function renderBreakdown( b ) {
		if ( ! b ) { return; }
		const $members = $('#lobbychat-wo-members');
		const $guests  = $('#lobbychat-wo-guests');
		const $bots    = $('#lobbychat-wo-bots');
		const $empty   = $('#lobbychat-wo-empty');

		const mems  = ( b.admins || [] ).concat( b.mods || [], b.members || [] );
		const hasMembers = mems.length > 0;
		const hasGuests  = ( b.guests || 0 ) > 0;
		const hasBots    = ( b.bots || [] ).length > 0;

		if ( hasMembers ) {
			const parts = [];
			( b.admins || [] ).forEach( function ( u ) {
				parts.push( '<span class="lobbychat-wo-user lobbychat-wo-admin">' + escapeHtml( u.name || 'Admin' ) + '</span>' );
			});
			( b.mods || [] ).forEach( function ( u ) {
				parts.push( '<span class="lobbychat-wo-user lobbychat-wo-mod">' + escapeHtml( u.name || 'Mod' ) + '</span>' );
			});
			( b.members || [] ).forEach( function ( u ) {
				parts.push( '<span class="lobbychat-wo-user lobbychat-wo-member">' + escapeHtml( u.name || 'User' ) + '</span>' );
			});
			$('#lobbychat-wo-members-list').html( parts.join(', ') );
			$members.show();
		} else {
			$members.hide();
		}

		if ( hasGuests ) {
			$('#lobbychat-wo-guests-count').text( b.guests );
			$guests.show();
		} else {
			$guests.hide();
		}

		if ( hasBots ) {
			const botParts = ( b.bots || [] ).map( function ( bot ) {
				return '<span class="lobbychat-wo-botname">' + escapeHtml( bot.name || 'Bot' ) + '</span>';
			});
			$('#lobbychat-wo-bots-list').html( botParts.join(', ') );
			$bots.show();
		} else {
			$bots.hide();
		}

		if ( hasMembers || hasGuests || hasBots ) {
			$empty.hide();
		} else {
			$empty.show();
		}
	}

	function escapeHtml( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"']/g, function ( m ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ m ];
		});
	}
	function escapeAttr( s ) { return escapeHtml( s ); }

	/* ── Action delegation (delete / pin / report) ─────── */
	function bindActions( $root ) {
		$root.on( 'click', '[data-action="delete"]', function () {
			const id = $(this).data('id');
			if ( ! confirm( I18N.confirm_delete || 'Delete this message?' ) ) { return; }
			ajax( 'lobbychat_delete', { message_id: id } ).done( function ( resp ) {
				if ( resp.success ) {
					$('#lobbychat-msg-' + id).fadeOut( 200, function () { $(this).remove(); } );
				} else {
					toast( resp.data ? resp.data.message : 'Error.' );
				}
			});
		});

		$root.on( 'click', '[data-action="pin"]', function () {
			const id = $(this).data('id');
			ajax( 'lobbychat_pin', { message_id: id } ).done( function ( resp ) {
				if ( resp.success ) { toast( 'Pinned.' ); loadMessages(); }
			});
		});

		$root.on( 'click', '[data-action="report"]', function () {
			const id = $(this).data('id');
			if ( ! confirm( I18N.confirm_report || 'Report this message?' ) ) { return; }
			ajax( 'lobbychat_report', { message_id: id } ).done( function ( resp ) {
				if ( resp.success ) { toast( I18N.reported || 'Reported.' ); }
				else { toast( 'Already reported.' ); }
			});
		});

		$root.on( 'click', '#lobbychat-unpin-btn', function () {
			ajax( 'lobbychat_unpin', {} ).done( function ( resp ) {
				if ( resp.success ) {
					$('#lobbychat-pinned').hide();
					toast( 'Unpinned.' );
				}
			});
		});

		// YouTube preview play button
		$root.on( 'click', '.lobbychat-yt-wrap', function () {
			const videoId = $(this).data('videoid');
			$(this).replaceWith(
				'<div style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;border-radius:6px;">'
				+ '<iframe src="https://www.youtube.com/embed/' + escapeAttr( videoId ) + '?autoplay=1" '
					+ 'style="position:absolute;top:0;left:0;width:100%;height:100%;" '
					+ 'frameborder="0" allowfullscreen allow="autoplay"></iframe>'
				+ '</div>'
			);
		});
	}

	/* ── Init ──────────────────────────────────────────── */
	function init() {
		loadMessages();
		bindActions( $(document) );

		const sendLabel = I18N.send || 'Send';

		// Send: use event delegation on document so the binding survives any DOM rewrite.
		$(document).on( 'click', '#lobbychat-send-btn', sendMessage );

		$(document).on( 'keydown', '#lobbychat-message', function ( e ) {
			if ( e.key === 'Enter' && ! e.shiftKey ) {
				e.preventDefault();
				sendMessage();
			}
		});

		$(document).on( 'input', '#lobbychat-message', function () {
			const max  = LobbyChat.max_length || 500;
			const len  = $(this).val().length;
			const left = max - len;
			$('#lobbychat-char-count')
				.text( left )
				.removeClass( 'lobbychat-char-warn lobbychat-char-over' )
				.addClass( left <= 20 ? ( left < 0 ? 'lobbychat-char-over' : 'lobbychat-char-warn' ) : '' );
		});

		// Collapse toggle
		$('#lobbychat-toggle-btn').on( 'click', function () {
			App.collapsed = ! App.collapsed;
			$('#lobbychat').toggleClass( 'lobbychat-collapsed', App.collapsed );
			$(this).text( App.collapsed ? '▼' : '▲' );
		});

		// Fullscreen
		let fsPlaceholder = null;

		function exitFullscreen() {
			const $wrap = $('#lobbychat');
			if ( ! $wrap.hasClass( 'lobbychat-fullscreen' ) ) { return; }
			$wrap.removeClass( 'lobbychat-fullscreen' );
			$('html, body').removeClass( 'lobbychat-body-fs' );
			$('#lobbychat-fs-backdrop, #lobbychat-fs-exit').remove();
			if ( fsPlaceholder && fsPlaceholder.length ) {
				fsPlaceholder.before( $wrap );
				fsPlaceholder.remove();
				fsPlaceholder = null;
			}
			$('#lobbychat-fs-btn').text( '⛶' ).attr( 'title', I18N.fullscreen || 'Fullscreen' );
			setTimeout( scrollToBottom, 150 );
		}

		function enterFullscreen() {
			const $wrap = $('#lobbychat');
			if ( $wrap.hasClass( 'lobbychat-fullscreen' ) ) { return; }
			fsPlaceholder = $('<div id="lobbychat-fs-placeholder" style="display:none"></div>');
			$wrap.before( fsPlaceholder );
			$('body').append(
				'<div id="lobbychat-fs-backdrop" class="lobbychat-fs-backdrop"></div>'
				+ '<button type="button" id="lobbychat-fs-exit" class="lobbychat-fs-exit" aria-label="Exit fullscreen">✕</button>'
			);
			$wrap.appendTo( 'body' ).addClass( 'lobbychat-fullscreen' );
			$('html, body').addClass( 'lobbychat-body-fs' );
			$('#lobbychat-fs-btn').text( '✕' ).attr( 'title', 'Exit fullscreen' );
			setTimeout( scrollToBottom, 150 );
		}

		$('#lobbychat-fs-btn').on( 'click', function () {
			if ( $('#lobbychat').hasClass( 'lobbychat-fullscreen' ) ) {
				exitFullscreen();
			} else {
				enterFullscreen();
			}
		});

		$(document).on( 'click', '#lobbychat-fs-exit, #lobbychat-fs-backdrop', exitFullscreen );

		$(document).on( 'keydown', function ( e ) {
			if ( e.key === 'Escape' && $('#lobbychat').hasClass( 'lobbychat-fullscreen' ) ) {
				exitFullscreen();
			}
		});

		// Sound
		$('#lobbychat-sound-btn').on( 'click', function () {
			App.soundOn = ! App.soundOn;
			localStorage.setItem( 'lobbychat_sound', App.soundOn ? 'on' : 'off' );
			$(this).text( App.soundOn ? '🔔' : '🔕' );
		});
		$('#lobbychat-sound-btn').text( App.soundOn ? '🔔' : '🔕' );

		// Link mode
		$('#lobbychat-link-btn').on( 'click', function () { toggleLinkMode(); } );
		$('#lobbychat-link-clear').on( 'click', function () { toggleLinkMode( false ); } );

		// Scroll detection
		$('#lobbychat-feed').on( 'scroll', function () {
			const el = this;
			App.isScrolled = el.scrollTop < el.scrollHeight - el.clientHeight - 30;
		});

		// Reactions: toggle existing
		$(document).on( 'click', '.lobbychat-reaction-btn', function () {
			const emoji = $(this).data('emoji');
			const msgId = $(this).data('msg');
			ajax( 'lobbychat_react', { message_id: msgId, emoji: emoji } ).done( function ( resp ) {
				if ( ! resp.success ) { return; }
				const $row = $('.lobbychat-reactions[data-msg-id="' + msgId + '"]');
				$row.replaceWith( renderReactions( resp.data.reactions, msgId ) );
			});
		});

		// Reactions: open picker
		$(document).on( 'click', '.lobbychat-add-reaction', function ( e ) {
			e.stopPropagation();
			const msgId = $(this).data('msg');
			const $btn  = $(this);

			$('.lobbychat-reaction-picker').remove();
			if ( App.activePicker === msgId ) { App.activePicker = null; return; }

			const emojis = [ '👍', '❤️', '😂', '🔥', '🎉' ];
			const $picker = $('<div class="lobbychat-reaction-picker">').css( 'position', 'absolute' );
			emojis.forEach( function ( em ) {
				$picker.append(
					$('<button type="button">').text( em ).on( 'click', function () {
						$('.lobbychat-reaction-picker').remove();
						App.activePicker = null;
						ajax( 'lobbychat_react', { message_id: msgId, emoji: em } ).done( function ( resp ) {
							if ( ! resp.success ) { return; }
							const $row = $('.lobbychat-reactions[data-msg-id="' + msgId + '"]');
							$row.replaceWith( renderReactions( resp.data.reactions, msgId ) );
						});
					})
				);
			});

			$btn.closest( '.lobbychat-reactions' ).css( 'position', 'relative' ).append( $picker );
			App.activePicker = msgId;
		});

		// Close picker on outside click
		$(document).on( 'click', function () {
			$('.lobbychat-reaction-picker').remove();
			App.activePicker = null;
		});
	}

	$( init );

})( jQuery );
