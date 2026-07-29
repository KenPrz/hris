# syntax=docker/dockerfile:1

FROM node:24-alpine AS deps
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci

FROM node:24-alpine AS build
WORKDIR /app
COPY --from=deps /app/node_modules ./node_modules
COPY . .
ENV NEXT_TELEMETRY_DISABLED=1
# next.config.ts sets `output: 'standalone'`, which is what makes the runner stage below
# a bare `node server.js` with no node_modules copy.
RUN npm run build

FROM node:24-alpine AS runner
WORKDIR /app
# PORT=3000, not 5176: in production this sits behind Caddy (reverse_proxy web:3000),
# so the dev host port is irrelevant here. HOSTNAME=0.0.0.0 or the standalone server
# binds loopback only and Caddy can't reach it.
ENV NODE_ENV=production NEXT_TELEMETRY_DISABLED=1 HOSTNAME=0.0.0.0 PORT=3000
COPY --from=build --chown=node:node /app/.next/standalone ./
COPY --from=build --chown=node:node /app/.next/static ./.next/static
COPY --from=build --chown=node:node /app/public ./public
USER node
EXPOSE 3000
CMD ["node", "server.js"]

FROM node:24-alpine AS dev
WORKDIR /app
ENV NEXT_TELEMETRY_DISABLED=1
# Source arrives via bind mount; node_modules via a named volume, installed by the
# container on first boot (see the compose command).
CMD ["sh", "-c", "npm install && npm run dev"]
