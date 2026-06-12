# HELLO Hosting

The future-proof production shape is an OCI container with a persistent state volume. That keeps HELLO movable between managed container hosts, a VPS, Kubernetes, and newer platforms without changing the WordPress plugin.

## Recommended Path

Run the bridge as a normal long-running Node service in a container host that supports:

- outbound HTTPS to Matrix and WordPress sites
- one public HTTPS URL for the WordPress plugin to call
- secrets as environment variables
- a persistent volume mounted at `/data`
- a health check that can call `/v1/live`

Good fits are managed container platforms such as Fly.io, Railway, Render, Google Cloud Run with a persistent external store, a VPS with Docker, or Kubernetes. Vercel Functions are not a good fit for the bridge because the Matrix sync loop is intentionally long-running. Cloudflare Containers are promising, but as of June 12, 2026 their container disk is ephemeral when an instance sleeps, so HELLO should only use that path after adding a Cloudflare Durable Object, D1, R2 FUSE, or other persistent state adapter.

## Railway

The repo includes `railway.json` so Railway builds `bot/Dockerfile`, checks `/v1/live`, and restarts the bridge if it exits.

Create the Railway project and service:

```sh
railway init --name HELLO
railway add --service bridge
railway service link bridge
```

Attach persistent state:

```sh
railway volume add --mount-path /data
```

Set runtime variables:

```sh
railway variable set MATRIX_HOMESERVER_URL=https://matrix.org --skip-deploys
railway variable set MATRIX_USER_ID=@hello-bot:matrix.org --skip-deploys
railway variable set MATRIX_ACCESS_TOKEN --stdin --skip-deploys
railway variable set BRIDGE_API_TOKEN --stdin --skip-deploys
railway variable set IDENTITY_STORE_PATH=/data/identities.json --skip-deploys
railway variable set MATRIX_SYNC_STORE_PATH=/data/matrix-sync.json --skip-deploys
railway variable set SITE_REGISTRY_PATH=/data/sites.json --skip-deploys
```

Or use the Matrix login API helper to log in an existing bot account and store the Matrix variables in Railway without printing the access token:

```sh
read -s MATRIX_PASSWORD
printf '%s' "$MATRIX_PASSWORD" | npm --prefix bot run matrix:login -- \
  --user hello-bot \
  --railway \
  --service bridge \
  --password-stdin
unset MATRIX_PASSWORD
```

Redeploy after variables are in place:

```sh
railway redeploy
railway domain --service bridge --port 8787
```

## Container Contract

Build the image from the repo root:

```sh
docker build -f bot/Dockerfile -t hello-bridge .
```

Run it with a persistent Docker volume:

```sh
docker run --rm \
  --env-file bot/.env \
  -e PORT=8787 \
  -e IDENTITY_STORE_PATH=/data/identities.json \
  -e MATRIX_SYNC_STORE_PATH=/data/matrix-sync.json \
  -e SITE_REGISTRY_PATH=/data/sites.json \
  -p 8787:8787 \
  -v hello-bridge-data:/data \
  hello-bridge
```

Or use Compose:

```sh
docker compose up --build
```

For Nick's production plugin build, set `HELLO_DEFAULT_BRIDGE_URL` in `hello/hello.php` to the public HTTPS URL that points at this container. Customer WordPress sites do not configure a bridge URL.

## Required Secrets

```sh
MATRIX_HOMESERVER_URL=https://matrix.org
MATRIX_ACCESS_TOKEN=...
MATRIX_USER_ID=@hello-bot:matrix.org
BRIDGE_API_TOKEN=use-a-long-random-secret
```

Optional runtime settings:

```sh
PORT=8787
BRIDGE_PORT=8787
IDENTITY_STORE_PATH=/data/identities.json
MATRIX_SYNC_STORE_PATH=/data/matrix-sync.json
SITE_REGISTRY_PATH=/data/sites.json
```

`BRIDGE_PORT` wins when both `BRIDGE_PORT` and `PORT` are set. Most managed hosts set `PORT`; HELLO supports that directly.

## Health Checks

- `GET /v1/live` is unauthenticated and returns a minimal liveness response for load balancers and container platforms.
- `POST /v1/health` requires `Authorization: Bearer <BRIDGE_API_TOKEN>` and verifies Matrix account access.

## Cloudflare Notes

Cloudflare Containers can build and deploy Docker images through Wrangler, and a Worker can route requests into a container. The part to be careful with is state: HELLO currently stores the Matrix sync cursor, reader identity metadata, and room registry as files. Cloudflare documents container disk as ephemeral after sleep, so do not use Cloudflare Containers for production HELLO state unless you pair it with a persistent state adapter.

Useful references:

- [Cloudflare Containers](https://developers.cloudflare.com/containers/)
- [Cloudflare Containers getting started](https://developers.cloudflare.com/containers/get-started/)
- [Cloudflare Containers lifecycle and persistent disk notes](https://developers.cloudflare.com/containers/platform-details/architecture/)
- [Vercel Function duration](https://vercel.com/docs/functions/configuring-functions/duration)
