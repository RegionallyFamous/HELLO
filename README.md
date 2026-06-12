# HELLO

HELLO contains **Beeper Comments**, a WordPress plugin and Matrix bot that turn a post comment section into a Matrix room that can be opened from Beeper or any Matrix client.

## What Is Included

- `beeper-comments/` - WordPress plugin.
- `bot/` - Node.js Matrix bot service using the Matrix Client-Server API.

## Quick Start

1. Copy or symlink `beeper-comments/` into `wp-content/plugins/`.
2. Activate **Beeper Comments** in WordPress.
3. Open **Settings > Beeper Comments** and set:
   - Matrix homeserver URL
   - Matrix bot access token
   - Matrix bot user ID
   - shared bot secret
4. Create a bot `.env` from `bot/.env.example`.
5. Run the bot:

```sh
cd bot
npm install
npm start
```

When a post is published, the plugin creates a Matrix room and stores the room ID on the post. The comment form gets a Beeper/Matrix join button. Matrix messages are posted back to WordPress through the plugin REST endpoint, and normal WordPress comments are sent into the Matrix room.

## Development

```sh
npm run check
npm run package:plugin
```

The plugin REST webhook is:

```text
POST /wp-json/beeper-comments/v1/incoming
```

The webhook requires `bot_secret` to match the value stored in WordPress settings.
