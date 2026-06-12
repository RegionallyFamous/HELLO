=== HELLO ===
Contributors: hello
Requires at least: 7.0
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later

Turns WordPress post comments into Matrix rooms that can be opened in Beeper.

== Description ==

HELLO asks a hosted bridge to create a Matrix room when a post is published, adds a join button to the comment form, accepts Matrix messages through a secured REST webhook, and syncs approved WordPress comments back through the bridge to the Matrix room.

The companion HELLO Bridge listens to Matrix rooms and calls the plugin webhook when readers send messages. WordPress sites only need the plugin installed.

== Installation ==

1. Upload the `hello` folder to `/wp-content/plugins/`.
2. Activate the plugin.
3. Configure Settings > HELLO.
4. Run the companion bot from the repository `bot` directory.

== REST Endpoint ==

`POST /wp-json/hello/v1/incoming`

Required fields: `room_id`, `matrix_user_id`, `message`, `event_id`, `bot_secret`.

Optional identity fields: `author_name`, `author_url`, `author_email_hash`, `author_avatar_url`.
