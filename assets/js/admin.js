/**
 * LobbyChat admin — bot settings page.
 * Handles the "Send Test Reply" button.
 */
(function ($) {
	'use strict';

	$(function () {
		var $btn = $('#lobbychat-bot-test-btn');
		if (!$btn.length) return;

		var $out = $('#lobbychat-bot-test-result');
		var cfg  = window.lobbychatBotTest || {};

		$btn.on('click', function () {
			$btn.prop('disabled', true);
			$out.html('<span style="color:#888">' + (cfg.calling || 'Calling AI…') + '</span>');

			$.post(cfg.ajaxUrl || window.ajaxurl, {
				action: 'lobbychat_bot_test',
				_ajax_nonce: cfg.nonce || ''
			}).done(function (r) {
				if (r.success) {
					var reply = (r.data && r.data.reply) ? r.data.reply : '';
					$out.html('<span style="color:#0a7d0a">✓ ' + (cfg.posted || 'Posted:') + ' "' + escapeHtml(reply) + '"</span>');
				} else {
					var err = (r.data && r.data.error) ? r.data.error : (cfg.unknownError || 'Unknown error');
					$out.html('<span style="color:#c00">✗ ' + escapeHtml(err) + '</span>');
				}
			}).fail(function (xhr) {
				$out.html('<span style="color:#c00">✗ HTTP ' + xhr.status + '</span>');
			}).always(function () {
				$btn.prop('disabled', false);
			});
		});

		function escapeHtml(s) {
			return String(s == null ? '' : s).replace(/[&<>"']/g, function (m) {
				return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
			});
		}
	});
})(jQuery);
