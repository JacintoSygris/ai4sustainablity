const laravelApiOrigin = (process.env.LARAVEL_API_ORIGIN?.trim() || process.env.LARAVEL_INTERNAL_API_ORIGIN?.trim() || "").replace(/\/$/, "")

/** @type {import('next').NextConfig} */
const nextConfig = {
  // Offline builds in restricted runners can use threads and the compiler API.
  // Type checking remains enabled; ordinary release builds keep Next's defaults.
  ...(process.env.I4S_BUILD_IN_PROCESS === "1" ? {
    experimental: {
      useTypeScriptCli: false,
      workerThreads: true,
      webpackBuildWorker: false,
      cpus: 1,
    },
  } : {}),
  images: {
    unoptimized: true,
  },
  async headers() {
    return [
      {
        source: "/:path*",
        headers: [
          {
            key: "X-Content-Type-Options",
            value: "nosniff",
          },
          {
            key: "Referrer-Policy",
            value: "strict-origin-when-cross-origin",
          },
          {
            key: "X-Frame-Options",
            value: "DENY",
          },
          {
            key: "Permissions-Policy",
            value: "camera=(), microphone=(), geolocation=(), payment=()",
          },
        ],
      },
    ]
  },
  async rewrites() {
    if (!laravelApiOrigin) {
      return []
    }

    return {
      beforeFiles: [
        {
          source: "/api/:path*",
          destination: `${laravelApiOrigin}/api/:path*`,
        },
        {
          source: "/characterization/:path*",
          destination: `${laravelApiOrigin}/characterization/:path*`,
        },
        {
          source: "/logout",
          destination: `${laravelApiOrigin}/logout`,
        },
        {
          source: "/profile",
          destination: `${laravelApiOrigin}/profile`,
        },
        {
          source: "/build/:path*",
          destination: `${laravelApiOrigin}/build/:path*`,
        },
        {
          source: "/laravel/login",
          destination: `${laravelApiOrigin}/laravel/login`,
        },
        {
          source: "/laravel/register",
          destination: `${laravelApiOrigin}/laravel/register`,
        },
        {
          source: "/laravel/forgot-password",
          destination: `${laravelApiOrigin}/laravel/forgot-password`,
        },
        {
          source: "/reset-password",
          destination: `${laravelApiOrigin}/reset-password`,
        },
        {
          source: "/verify-email/:id/:hash",
          destination: `${laravelApiOrigin}/verify-email/:id/:hash`,
        },
        {
          source: "/laravel/email/verification-notification",
          destination: `${laravelApiOrigin}/laravel/email/verification-notification`,
        },
      ],
      afterFiles: [],
      fallback: [],
    }
  },
}

export default nextConfig
