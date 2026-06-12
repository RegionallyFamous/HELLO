# Beeper Comments Deployment

This runbook assumes a normal WordPress install and a Matrix bot account on the homeserver you want to use.

## 1. Install The WordPress Plugin

Build the plugin zip:

```sh
npm run package:plugin
```

Install `dist/beeper-comments.zip` in WordPress, or copy `beeper-comments/` into `wp-content/plugins/`.

Activate **Beeper Comments**, then open **Settings > Beeper Comments**.

## 2. Configure Matrix

Create or choose a Matrix account for the bot. The bot account needs to be able to:

- create public rooms
- send messages
- join invited rooms
- redact events if you enable moderation redaction

In WordPress settings, fill in:

- `Homeserver URL`
- `Bot access token`
- `Bot user ID`
- `Webhook shared secret`
- `Room alias prefix`
- sync direction
- moderation redaction behavior

The settings page includes a copyable `.env` block for the bot.

## 3. Run The Bot

```sh
cd bot
npm install
cp .env.example .env
```

Fill in `.env`, then verify connectivity:

```sh
npm run doctor
```

Start the bot:

```sh
npm start
```

For production, run it under a process manager such as systemd, launchd, PM2, or Docker. The bot stores state in `.data/` by default, so preserve that directory across restarts.

## 4. systemd Example

```ini
[Unit]
Description=Beeper Comments Matrix Bot
After=network.target

[Service]
Type=simple
WorkingDirectory=/srv/beeper-comments/bot
EnvironmentFile=/srv/beeper-comments/bot/.env
ExecStart=/usr/bin/node src/index.js
Restart=always
RestartSec=5
User=www-data

[Install]
WantedBy=multi-user.target
```

## 5. Smoke Test

1. Publish a new WordPress post.
2. Confirm the post edit screen shows a Matrix room in the **Beeper Comments** metabox.
3. Open the public post and click **Join the discussion in Beeper**.
4. Send a Matrix message in the room.
5. Confirm the message appears as a WordPress comment.
6. Submit and approve a WordPress comment.
7. Confirm the WordPress comment appears in the Matrix room.
8. Mark one synced comment as spam or trash.
9. Confirm the Matrix event is redacted if redaction is enabled.

## 6. Operational Notes

- The WordPress plugin never stores plaintext reader email addresses.
- The bot hashes onboarding email replies for Gravatar and stores the MD5 hash plus public profile metadata.
- First bot startup stores the Matrix sync cursor and skips historical room messages to avoid importing old history by accident.
- The bot refreshes the WordPress room registry every `ROOM_REFRESH_MS`.
- If the plugin cannot create or post to a Matrix room, the latest error is shown in the post metabox.
- If a Matrix user is kicked or banned by a moderator in a mapped post room, future messages from that user are sent to WordPress as held comments.

## 7. Verification Commands

```sh
npm run check
npm run package:plugin
npm audit --omit=dev
git diff --check
```
