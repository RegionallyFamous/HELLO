# syntax=docker/dockerfile:1

FROM node:24-bookworm-slim

ENV NODE_ENV=production \
    PORT=8787 \
    IDENTITY_STORE_PATH=/data/identities.json \
    MATRIX_SYNC_STORE_PATH=/data/matrix-sync.json \
    SITE_REGISTRY_PATH=/data/sites.json \
    NPM_CONFIG_UPDATE_NOTIFIER=false

WORKDIR /app

COPY package.json package-lock.json ./
COPY bot/package.json ./bot/package.json
RUN npm ci --omit=dev --workspace bot

WORKDIR /app/bot
COPY bot/src ./src

RUN mkdir -p /data && chown -R node:node /app /data

USER node

EXPOSE 8787

HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
  CMD node -e 'const port = process.env.BRIDGE_PORT || process.env.PORT || 8787; fetch("http://127.0.0.1:" + port + "/v1/live").then((r) => process.exit(r.ok ? 0 : 1)).catch(() => process.exit(1))'

CMD ["node", "src/bridge.js"]
