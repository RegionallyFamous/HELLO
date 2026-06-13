=== HELLO ===
Contributors: hello
Requires at least: 7.0
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later

Makes WordPress comments feel alive in Beeper while keeping them native to WordPress.

== Description ==

HELLO gives each post a live discussion room readers can join from Beeper while preserving the canonical conversation as normal WordPress comments.

Readers can chat where they already are. Site owners keep the comment system they already trust. WordPress remains the source of record.

No Matrix account, bot service, bridge URL, or bridge token is configured on the WordPress site. The plugin registers with the hosted HELLO Bridge automatically.

== Installation ==

1. Upload the `hello` folder to `/wp-content/plugins/`.
2. Activate the plugin.

Publish a post and HELLO handles the rest.

== REST Endpoint ==

`POST /wp-json/hello/v1/incoming`

Required fields: `room_id`, `matrix_user_id`, `message`, `event_id`, `bot_secret`.

Optional identity fields: `author_name`, `author_url`, `author_email_hash`, `author_avatar_url`.
