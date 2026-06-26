=== LobbyChat ===
Contributors: jauntymellifluous
Tags: shoutbox, chat, live chat, community, comments
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.2
Stable tag: 1.2.1
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

= Try it live =

See LobbyChat running in production at [bejaunty.com/plugins/lobbychat](https://bejaunty.com/plugins/lobbychat).

= Roadmap =

LobbyChat is the free, standalone version. Premium add-ons with gaming-community features — looking-for-group buttons, platform tags, profile-card integration, and richer moderation tools — are in development. Updates and announcements at [bejaunty.com](https://bejaunty.com).

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
* `lobbychat_emoji_set` — filter, customize the array of emoji shown in the picker

== External services ==

This plugin does not connect to any external service by default. Two optional features may, **only when explicitly enabled by the site administrator**, contact third-party services:

= 1. Link previews =

When the link-sharing feature is enabled (Settings → LobbyChat → "Allow link sharing", which is on by default), and a logged-in user shares a URL in chat, the plugin's server fetches that URL once to extract Open Graph / Twitter / `<title>` metadata for a preview card.

* **What is sent:** The HTTP request from your server to the URL the user pasted. The request user-agent identifies as `LobbyChatBot/1.0` and includes a link back to your site.
* **When:** Only at the moment a user posts a message containing a URL.
* **What is received and stored:** Title, description, and image URL extracted from the page's metadata. Stored in the chat message row in your database.
* **Special handling for YouTube URLs:** YouTube URLs are first sent to YouTube's public oEmbed endpoint (`https://www.youtube.com/oembed`) to retrieve title and author info. YouTube's [Terms of Service](https://www.youtube.com/static?template=terms) and [Privacy Policy](https://policies.google.com/privacy) apply.

This feature can be fully disabled by un-checking "Allow link sharing" in plugin settings.

= 2. Optional AI chat companion =

When the AI bot is explicitly enabled by the administrator (Settings → LobbyChat AI Bot → "Enable bot", which is **off by default**) and the administrator has provided their own API key for one or both of the providers below, chat messages are sent to that provider for the bot to generate replies.

**Google Gemini API** (`https://generativelanguage.googleapis.com/`)
* **What it is:** Google's generative AI service, used here to produce conversational chat replies.
* **What is sent:** Up to 6 most recent chat messages (sender display name + message text), the bot's system prompt, and the administrator's Gemini API key.
* **When:** Only when the administrator has enabled the bot AND a chat message satisfies the bot's reply-trigger rules (mention, question, or random chance — all configurable).
* **Terms and privacy:** [Google APIs Terms of Service](https://developers.google.com/terms), [Gemini API Additional Terms](https://ai.google.dev/gemini-api/terms), [Google Privacy Policy](https://policies.google.com/privacy).

**OpenAI API** (`https://api.openai.com/`)
* **What it is:** OpenAI's chat completions API, used here as a fallback when Gemini fails or as the primary if only an OpenAI key is configured.
* **What is sent:** Same as Gemini above (recent messages + system prompt + administrator's OpenAI API key).
* **When:** Same conditions as Gemini above.
* **Terms and privacy:** [OpenAI Terms of Use](https://openai.com/policies/terms-of-use), [OpenAI API Data Usage Policies](https://openai.com/policies/api-data-usage-policies), [OpenAI Privacy Policy](https://openai.com/policies/privacy-policy).

The AI bot is disabled by default and will not contact any external service unless the administrator explicitly enables it and provides an API key.

= 3. Optional Cloudflare Turnstile spam protection =

When Turnstile is explicitly enabled by the administrator (Settings → LobbyChat → Spam protection, **off by default**) and a site key and secret key are provided, the plugin uses Cloudflare's Turnstile service to verify that the person posting is not a bot.

**Cloudflare Turnstile** (`https://challenges.cloudflare.com/`)
* **What it is:** Cloudflare's privacy-focused CAPTCHA alternative, used here to block automated spam before a message is posted.
* **What is sent:** When a user submits a message, the Turnstile token generated in their browser plus the user's IP address are sent from your server to Cloudflare's `siteverify` endpoint, along with your secret key, to confirm the token is valid. The browser-side widget also loads Cloudflare's `api.js` script from `challenges.cloudflare.com`.
* **When:** Only when Turnstile is enabled and a user attempts to post a message (optionally limited to guests only).
* **Terms and privacy:** [Cloudflare Website Terms of Use](https://www.cloudflare.com/website-terms/), [Cloudflare Privacy Policy](https://www.cloudflare.com/privacypolicy/).

Turnstile is disabled by default and will not contact any external service unless the administrator explicitly enables it and provides their own keys.

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

= Where do clickable usernames link to? Can I point them at a custom profile page? =

By default usernames link to the WordPress author archive — but only if that user has published a post (otherwise the link is omitted to avoid 404s).

To send clicks to a different URL (e.g. a BuddyPress profile, a custom `/profile/{username}` page from your community plugin, or anywhere else), use the `lobbychat_profile_url` filter in your theme's `functions.php`:

`add_filter( 'lobbychat_profile_url', function( $url, $user_id ) {`
`    $user = get_userdata( $user_id );`
`    return $user ? home_url( '/profile/' . $user->user_login ) : $url;`
`}, 10, 2 );`

== Screenshots ==

1. Live chat feed with reactions and link previews.
2. Online member breakdown showing members, guests, and bots.
3. Settings page with rate-limit controls and moderation options.
4. AI bot configuration with built-in setup guide.

== Changelog ==

= 1.2.1 =
* Fixed: Clicking an emoji in the picker did nothing on sites where WordPress replaces emoji with images (Twemoji). The real emoji character is now stored on each button and inserted reliably regardless of how the glyph is displayed.

= 1.2.0 =
* New: Optional Cloudflare Turnstile integration for bot protection. Enable it under Settings → LobbyChat → Spam protection, paste your free site/secret keys, and choose whether to require it for everyone or guests only. Posting is blocked until the check passes.
* Improved: Emoji picker is more reliable — fixed an issue where clicking the 😊 button or an emoji did nothing on some setups.
* Improved: The moderator "clear chat" action no longer uses a browser confirm() popup. Instead the 🧹 button arms on first click (turns red, shows ✓) and clears on a second click within 3 seconds.
* Improved: Hover actions (Delete / Pin / Report) now float in the top-right corner of a message instead of pushing the message taller, so row heights no longer jump on hover.

= 1.1.0 =
* New: Emoji picker — users can insert emoji into their messages via a 😊 button next to the message box. The emoji set is filterable with `lobbychat_emoji_set`.
* New: Moderators can instantly clear all chat messages with a 🧹 button in the chat header — useful for sites that run multiple separate sessions (pinned messages are kept). 
* Fixed: Auto-delete (message retention) now also runs opportunistically when the chat is loaded, throttled to once per hour. Previously, on very low-traffic sites — for example a chat only enabled during a weekly event — WordPress cron might never fire and old messages would linger past their retention window. Thanks to @david-innes for the report.

= 1.0.8 =
* Fixed: Apostrophes and other special characters were rendered as HTML entities (`I&#039;m going` instead of `I'm going`) in chat messages and display names. Server no longer double-encodes payload fields that the JS client already escapes at render time. Thanks to @david-innes for the report.
* Fixed: Clickable usernames could 404 on sites where users hadn't published any posts or where author archives are disabled. Default username link is now only added when the user has a usable author archive — otherwise the name is shown as plain text. Use the `lobbychat_profile_url` filter to point at custom profile URLs (see FAQ).

= 1.0.7 =
* Updated: "Tested up to" bumped to WordPress 7.0.

= 1.0.6 =
* Updated: Plugin URI now points to the GitHub source repository (`github.com/JauntyMYT/lobbychat`) for a more stable canonical URL.

= 1.0.5 =
* Compliance: Moved inline admin JavaScript to a properly enqueued file (`assets/js/admin.js`) loaded only on the bot settings page via `admin_enqueue_scripts`, per WordPress.org coding standards.
* Compliance: Renamed all plugin-defined constants from the 3-character `LBC_*` prefix to `LOBBYCHAT_*` to meet the 4+ character prefix requirement.

= 1.0.4 =
* Compliance: "Powered by LobbyChat" frontend attribution is now opt-in (off by default) per WordPress.org guidelines on plugin attribution.
* Compliance: Added detailed "External services" section to readme documenting all third-party API calls (Gemini, OpenAI, YouTube oEmbed, link preview fetches) with terms-of-service and privacy-policy links.

= 1.0.3 =
* Fixed: Link sharing now works for any URL — previously only worked when the linked page had perfectly-formatted Open Graph tags. Now handles Twitter cards, plain `<title>` tags, and falls back to a bare link card if no metadata is available.
* Improved: Open Graph parser now handles meta tags with `content` before `property`, mixed quote styles, and follows redirects.
* Improved: Bumped link-fetch timeout from 5s to 8s for slower-responding sites.
* Updated: Plugin URI and demo link now point to bejaunty.com.

= 1.0.2 =
* Plugin Check compliance: addressed all errors and warnings from the official WordPress Plugin Check tool.
* Replaced `parse_url()` with `wp_parse_url()`, `mt_rand()` with `wp_rand()`, `date()` with `gmdate()`.
* Added `wp_unslash()` to all `$_POST` and `$_SERVER` reads before sanitization.
* Refactored `get_messages()` to avoid dynamic SQL fragments — now uses two clean prepared queries.
* Added documented phpcs suppressions for legitimate direct-DB access (custom plugin tables) and bot logging.
* Removed redundant `load_plugin_textdomain()` (WP.org auto-loads translations since WP 4.6).
* Removed `Domain Path` header (no `/languages/` folder needed for WP.org-hosted plugins).
* Bumped `Tested up to: 6.9` to match current WordPress release.
* Renamed template-scope variables to use full `lobbychat_` prefix.

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

= 1.2.1 =
Fixes emoji picker insertion on sites that use Twemoji emoji images.

= 1.2.0 =
Adds optional Cloudflare Turnstile bot protection, fixes the emoji picker, and smooths out moderator controls.

= 1.1.0 =
New emoji picker and moderator "clear chat" button, plus a fix so message auto-delete works reliably on low-traffic sites.

= 1.0.8 =
Two bug fixes: apostrophes/special characters now display correctly; clickable usernames no longer 404 on sites without author archives. Recommended upgrade.

= 1.0.7 =
Confirmed compatible with WordPress 7.0.

= 1.0.6 =
Minor: Plugin URI updated to GitHub source repository.

= 1.0.5 =
WordPress.org compliance — proper script enqueue + longer constant prefix.

= 1.0.4 =
WordPress.org compliance pass — opt-in branding + documented external services.

= 1.0.3 =
Fixes link sharing — now reliably renders link previews for any URL with a graceful fallback. Recommended upgrade.

= 1.0.2 =
Plugin Check compliance pass. Recommended upgrade.

= 1.0.1 =
Fixes the send button not working in certain themes. Recommended upgrade.

= 1.0.0 =
Initial release.
