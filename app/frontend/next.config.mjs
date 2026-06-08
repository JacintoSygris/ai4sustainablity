const laravelApiOrigin = process.env.LARAVEL_API_ORIGIN?.replace(/\/$/, "")

/** @type {import('next').NextConfig} */
const nextConfig = {
  images: {
    unoptimized: true,
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
          source: "/laravel/login",
          destination: `${laravelApiOrigin}/laravel/login`,
        },
        {
          source: "/laravel/register",
          destination: `${laravelApiOrigin}/laravel/register`,
        },
      ],
      afterFiles: [],
      fallback: [],
    }
  },
}

export default nextConfig
