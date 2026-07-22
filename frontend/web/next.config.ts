import type { NextConfig } from 'next'

const nextConfig: NextConfig = {
  output: 'standalone',
  // Type-checking is deliberately not Next's job here: the gate is `npm run typecheck`
  // (tsgo --noEmit, the native TS compiler), run locally and in CI. The stable
  // `typescript` devDep exists so Next's build-time TS detection is satisfied —
  // without it, Next falls back to a mid-build `npm install` that breaks on CI runners.
  typescript: { ignoreBuildErrors: true },
  async rewrites() {
    return [
      {
        source: '/api/:path*',
        // Native dev talks to artisan/FrankenPHP on localhost; containers set
        // API_ORIGIN=http://api:8000. The browser therefore only ever sees one
        // origin, and CORS never comes up.
        destination: `${process.env.API_ORIGIN ?? 'http://127.0.0.1:8001'}/api/:path*`,
      },
    ]
  },
}

export default nextConfig
