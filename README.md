# HELLO

HELLO is a WordPress plugin plus a hosted bridge service that turns a post comment section into a Matrix room that can be opened from Beeper or any Matrix client.

## What Is Included

- `hello/` - WordPress plugin.
- `bot/` - Node.js HELLO Bridge service using the Matrix Client-Server API.
- `DEPLOYMENT.md` - production setup and operations runbook.
- `docs/HOSTING.md` - provider-neutral container hosting guide.

## Feature Coverage

- Lets a WordPress site use a hosted HELLO Bridge, so customer sites only need the plugin.
- Creates a Matrix room through the bridge when a WordPress post is published.
- Adds a Beeper/Matrix join button below the comment form.
- Exposes secured REST endpoints for incoming Matrix messages, room registry, and health checks.
- Syncs Matrix text messages into WordPress comments with event deduplication.
- Syncs approved WordPress comments back to Matrix and stores the resulting Matrix event ID.
- Syncs comments that move from pending to approved.
- Redacts synced Matrix events when the matching WordPress comment is spammed or trashed.
- Adds a post metabox for inspecting, creating, or repairing the Matrix room for a post.
- Resolves Matrix users to Gravatar metadata through a private DM onboarding flow.
- Keeps bot state in local JSON files with no plaintext reader email storage.
- Provides direct single-site bot mode for development and local automated tests.
- Ships a provider-neutral Docker/Compose path for hosted bridge deployments.

## Quick Start

1. Copy or symlink `hello/` into `wp-content/plugins/`.
2. Activate **HELLO** in WordPress.
3. Open **Settings > HELLO** and set:
   - Connection mode: `Hosted bridge`
   - Bridge URL
   - Bridge token
4. On your bridge server, create a bot `.env` from `bot/.env.example`.
5. Run the bridge:

```sh
cd bot
npm install
npm start
```

When a post is published, the plugin asks your hosted bridge to create a Matrix room and stores the returned room ID on the post. The comment form gets a Beeper/Matrix join button. The bridge listens to mapped Matrix rooms and posts messages back through each site’s plugin webhook. Normal approved WordPress comments are sent from the plugin to the bridge, then into Matrix.

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

The webhook requires `bot_secret` to match the value stored in WordPress settings.

HELLO targets WordPress 7.0+ and PHP 8.0+.

See [DEPLOYMENT.md](DEPLOYMENT.md) for the production runbook.

For a future-proof hosted bridge, package the bridge as an OCI container and keep its state on a persistent volume. See [docs/HOSTING.md](docs/HOSTING.md).
