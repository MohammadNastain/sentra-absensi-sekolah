# syntax=docker/dockerfile:1
# Image web produksi: Next.js 16 mode standalone (tanpa node_modules penuh).
# Konteks build = root repo:  docker build -f infra/docker/prod/web.Dockerfile -t sentra-web .
# NEXT_PUBLIC_* ikut terbakar ke bundel saat build, jadi nilainya HARUS diberikan sebagai build-arg.

FROM node:24-alpine AS deps
RUN corepack enable
WORKDIR /repo
COPY package.json pnpm-lock.yaml pnpm-workspace.yaml ./
COPY apps/web/package.json apps/web/
COPY packages/api-client/package.json packages/api-client/
RUN pnpm install --frozen-lockfile --filter "@sentra/web..."

FROM deps AS build
COPY apps/web apps/web
COPY packages/api-client packages/api-client
ARG NEXT_PUBLIC_DEMO_ENABLED=false
# Rewrite /api dan /sanctum terbakar saat build; di produksi Caddy sudah mengarahkannya langsung ke API,
# jadi nilai ini hanya cadangan (nama service API di jaringan Docker).
ARG API_ORIGIN=http://api
ENV NEXT_PUBLIC_DEMO_ENABLED=$NEXT_PUBLIC_DEMO_ENABLED \
    API_ORIGIN=$API_ORIGIN \
    NEXT_OUTPUT=standalone \
    NEXT_TELEMETRY_DISABLED=1
RUN pnpm --filter "@sentra/web" build

FROM node:24-alpine AS runtime
WORKDIR /app
ENV NODE_ENV=production NEXT_TELEMETRY_DISABLED=1 PORT=3000 HOSTNAME=0.0.0.0
COPY --from=build --chown=node:node /repo/apps/web/.next/standalone ./
COPY --from=build --chown=node:node /repo/apps/web/.next/static ./apps/web/.next/static
COPY --from=build --chown=node:node /repo/apps/web/public ./apps/web/public
USER node
EXPOSE 3000
CMD ["node", "apps/web/server.js"]
