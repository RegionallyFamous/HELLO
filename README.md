# HELLO

![HELLO turns WordPress comments into live Beeper conversations](assets/hello-riso-hero.png)

**HELLO saves comments as we know them by making them feel alive again.**

The comment box at the bottom of a post used to be the beating heart of the web. Then the conversation drifted away into apps, group chats, social feeds, and private rooms. HELLO brings that energy back without asking WordPress to stop being WordPress.

Install the plugin. Publish a post. HELLO gives that post a live conversation room that readers can join from Beeper, while every message still lands back where it belongs: as a real WordPress comment.

No new commenting system. No customer-run server. No "please create a Matrix bot" setup screen. Just WordPress comments with a pulse.

## Try It

Open the [HELLO Playground demo](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/RegionallyFamous/HELLO/main/blueprints/playground.json) to see the reader-facing experience in a temporary WordPress site. It loads a sample post, native comments, and the HELLO Beeper room button.

## Why This Is Cool

HELLO makes comments portable, alive, and still yours.

- Readers can jump into the discussion from Beeper.
- Messages from Beeper come back as native WordPress comments.
- Approved WordPress comments flow back into the room.
- Moderation still happens in WordPress.
- Comment history stays attached to the post, not trapped in a social platform.
- Site owners only install the plugin.

![HELLO flow from plugin install to post comments and Beeper chat](assets/hello-riso-flow.png)

## The Big Idea

Every post deserves its own little room.

HELLO treats a comment thread like a place people can enter, not just a form people submit. It keeps the durable, public, archive-friendly shape of WordPress comments, then adds the immediacy of chat on top.

That means a reader can reply from Beeper and still help build the canonical conversation on the post. It is the old blog comment dream, with modern pipes.

## Install

1. Upload the `hello/` plugin folder or install `dist/hello.zip`.
2. Activate **HELLO** in WordPress.
3. Publish a post.

That is it for customer sites. HELLO uses the hosted RegionallyFamous bridge automatically.

## What Readers See

On posts with a HELLO room, readers get a simple **Open the Beeper discussion** button. It opens a HELLO helper page that can launch Beeper directly and gives readers a copyable room code only if the app needs a hand.

## Repos

- Plugin: https://github.com/RegionallyFamous/HELLO
- Bridge service: https://github.com/RegionallyFamous/HELLO-Bridge
- Technical wiki: https://github.com/RegionallyFamous/HELLO/wiki

## Development

```sh
npm run check
npm run package:plugin
```

HELLO targets WordPress 7.0+ and PHP 8.0+.
