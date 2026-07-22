# syntax=docker/dockerfile:1

FROM node:24-alpine AS dev
WORKDIR /app
ENV NEXT_TELEMETRY_DISABLED=1
# Source arrives via bind mount; node_modules via a named volume, installed by the
# container on first boot (see the compose command).
CMD ["sh", "-c", "npm install && npm run dev"]
