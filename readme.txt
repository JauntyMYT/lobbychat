=== LobbyChat — Live Shoutbox & Community Chat ===
Contributors: lobbychat
Tags: shoutbox, chat, live chat, community, comments
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.2
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A live, casual shoutbox for your community. Real-time messages, emoji reactions, link previews, moderator tools, optional AI bot.

== Description ==

**LobbyChat** is a lightweight live-chat shoutbox you can drop into any WordPress page or sidebar with a single shortcode. Built for communities — fan sites, forums, fandoms, classrooms, internal teams — where you want a casual, always-on group chat without bolting on Slack or Discord.

= What you get =

* **Live chat feed** with auto-refresh (configurable polling interval — no WebSocket server required)
* **Guest posting** — visitors can chat with just a display name, no signup wall
* **Emoji reactions** on every message — 👍 ❤️ 😂 🔥 🎉
* **Link previews** for YouTube and any URL with Open Graph tags
* **@mention highlighting** — `@username` gets visually picked out
* **Pinned messages** — moderators can pin one important message above the feed
* **Online presence** — live count of members, guests, and search-engine bots in the room
* **Moderator role** — add a "LobbyChat Moderator" role to any user for pin/delete powers
* **Reporting + auto-hide** — 5 reports on a message and it's hidden automatically
* **Word blocklist** for basic profanity filtering
* **Rate limiting** built in — separate cooldowns for guests vs members
* **Fullscreen mode** + collapse toggle
* **Sound notification** for new messages (toggleable per-visitor)
* **Mobile responsive**

= Optional: AI Chat Companion =

LobbyChat ships with an optional AI bot you can drop into your chat. The bot uses **your own API keys** — you bring your own Google Gemini key (the free tier works) or OpenAI key. The plugin does **not** route requests through any third-party server.

* **Bring-your-own-key** — Gemini (free tier) or OpenAI (paid)
* **Configurable persona** — name, system prompt, custom personality
* **Smart triggers** — replies to @mentions always; questions usually; random messages occasionally (all configurable)
* **Hard rate limits** — daily and hourly caps protect against runaway API costs
* **Active-hours window** — bot only chats during the hours you specify
* **One-click test** in the admin to verify the bot can post

= Privacy & data =

LobbyChat **does not call any third-party server by default**. The only network calls are:

* Fetching link previews when a user shares a URL (request goes from your server to that URL)
* Calling Google Gemini or OpenAI **only if you explicitly enable the AI bot and provide an API key**

No telemetry, no analytics, no "phone home." All data lives in your own `wp_lobbychat*` tables.

= Support development =

LobbyChat is free and developed in spare time. If it helps your community, you can support development at [wise.com/pay/me/asadk372](https://wise.com/pay/me/asadk372). Every bit is appreciated. ♥

= Usage =

After activating, drop this shortcode into any page, post, or text widget:

`[lobbychat]`

Then visit **Settings → LobbyChat** to configure rate limits, blocklist, and other options. For the AI bot, see **Settings → LobbyChat AI Bot**.

= Developer hooks =

LobbyChat exposes several filters and actions for theme/plugin developers:

* `lobbychat_after_send` — action, fires after a message is saved. `do_action('lobbychat_after_send', $user_id, $message_id, $row)` — the third arg is the database row object.
* `lobbychat_profile_url` — filter, override the URL linked from a username (default: WP author archive)
* `lobbychat_allowed_reactions` — filter, override the array of allowed reaction emoji
* `lobbychat_report_threshold` — filter, change the auto-hide report threshold (default: 5)

== Installation ==

1. Upload the `lobbychat` folder to `/wp-content/plugins/`, **or** install the zip via Plugins → Add New → Upload.
2. Activate the plugin through the Plugins menu.
3. Place the shortcode `[lobbychat]` on the page or in the widget area where you want the chat to appear.
4. (Optional) Visit **Settings → LobbyChat** to tweak rate limits and moderation settings.
5. (Optional) Visit **Settings → LobbyChat AI Bot** if you want to add an AI chat companion.

== Frequently Asked Questions ==

= Does this require a WebSocket server, Pusher, or any external service? =

No. LobbyChat uses simple HTTP polling at a configurable interval (default 30 seconds). It runs entirely on your own WordPress install with no external dependencies.

= Can guests post without registering? =

Yes — guest posting is on by default. They just enter a display name. You can require login in Settings if you prefer.

= How do I make someone a moderator? =

Edit the user in **Users → All Users**, change their role (or add the role) to **LobbyChat Moderator**. Administrators are automatically moderators.

= How does the AI bot work? Does it cost me anything? =

The bot is **off by default**. To turn it on, you provide your own Gemini API key (Google offers a free tier — see [aistudio.google.com/apikey](https://aistudio.google.com/apikey)) and/or OpenAI key. The bot calls those APIs directly from your server using your key. We never see your messages or your key.

= Will old messages be deleted automatically? =

Yes if you want. Set **Auto-delete old messages** in Settings to a number of days (default 30, set to 0 to keep forever). Pinned messages are never auto-deleted.

= Is the chat history searchable? =

Not in the current version. Messages are stored in a standard MySQL table (`wp_lobbychat`) so any standard WP backup or export tool will include them.

= Can I style it to match my theme? =

Yes — every color is set via CSS custom properties (`--lobbychat-accent`, `--lobbychat-bg`, etc.) on the wrapper. Override them in your theme's stylesheet.

= Does it work on mobile? =

Yes, the layout is fully responsive and supports fullscreen mode.

== Screenshots ==

1. Live chat feed with reactions and link previews.
2. Online member breakdown showing members, guests, and bots.
3. Settings page with rate-limit controls and moderation options.
4. AI bot configuration with built-in setup guide.

== Changelog ==

= 1.0.1 =
* Fixed: Send button not working in some themes — moved script localization earlier so the LobbyChat global is always defined when the JS loads.
* Fixed: Click handler now uses event delegation, so it survives DOM changes from page builders and caching plugins.
* Fixed: Uninstall now correctly drops the messages table (was using the wrong table name).
* Fixed: Removed unused `tag` column from the messages table schema (auto-migrates from 1.0.0).
* Added: "Show 'Powered by' link" toggle in Settings (on by default — admins can turn it off).
* Added: Donate link on the Plugins page, in plugin header, and on the settings page footer.
* Added: Console diagnostic when the LobbyChat global is missing (helps debug script-loading conflicts).

= 1.0.0 =
* Initial release.
* Live chat feed with HTTP polling.
* Guest and member posting with separate rate limits.
* Emoji reactions, link previews (YouTube + Open Graph), pinned messages.
* Reporting with auto-hide threshold.
* Word blocklist + 60-second self-delete window.
* Custom **LobbyChat Moderator** role.
* Online presence with bot detection.
* Optional AI chat companion (Gemini + OpenAI fallback).
* Fullscreen mode, collapse toggle, sound notifications.

== Upgrade Notice ==

= 1.0.1 =
Fixes the send button not working in certain themes. Recommended upgrade.

= 1.0.0 =
Initial release.
