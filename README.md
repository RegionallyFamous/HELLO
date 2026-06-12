# HELLO

HELLO is a WordPress plugin and Matrix bot that turn a post comment section into a Matrix room that can be opened from Beeper or any Matrix client.

## What Is Included

- `hello/` - WordPress plugin.
- `bot/` - Node.js Matrix bot service using the Matrix Client-Server API.
- `DEPLOYMENT.md` - production setup and operations runbook.

## Feature Coverage

- Creates a Matrix room when a WordPress post is published.
- Adds a Beeper/Matrix join button below the comment form.
- Exposes secured REST endpoints for incoming Matrix messages, room registry, and health checks.
- Syncs Matrix text messages into WordPress comments with event deduplication.
- Syncs approved WordPress comments back to Matrix and stores the resulting Matrix event ID.
- Syncs comments that move from pending to approved.
- Redacts synced Matrix events when the matching WordPress comment is spammed or trashed.
- Adds a post metabox for inspecting, creating, or repairing the Matrix room for a post.
- Resolves Matrix users to Gravatar metadata through a private DM onboarding flow.
- Keeps bot state in local JSON files with no plaintext reader email storage.
- Provides a bot doctor command and local automated tests.

## Quick Start

1. Copy or symlink `hello/` into `wp-content/plugins/`.
2. Activate **HELLO** in WordPress.
3. Open **Settings > HELLO** and set:
   - Matrix homeserver URL
   - Matrix bot access token
   - Matrix bot user ID
   - shared bot secret
4. Create a bot `.env` from `bot/.env.example`.
5. Run the bot:

```sh
cd bot
npm install
npm run doctor
npm start
```

When a post is published, the plugin creates a Matrix room and stores the room ID on the post. The comment form gets a Beeper/Matrix join button. The bot refreshes the room registry from WordPress, listens only to mapped post rooms, and posts Matrix messages back through the plugin webhook. Normal approved WordPress comments are sent into the Matrix room.

## Development

```sh
npm run check
npm run package:plugin
```

`npm run check` runs PHP syntax checks, Node syntax checks, and Node tests.

The plugin REST webhook is:

```text
POST /wp-json/hello/v1/incoming
```

The legacy `/wp-json/beeper-comments/v1/*` routes are also registered for compatibility.

The webhook requires `bot_secret` to match the value stored in WordPress settings.

See [DEPLOYMENT.md](DEPLOYMENT.md) for the production runbook.
