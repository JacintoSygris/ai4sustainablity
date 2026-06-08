console.error(
  "Frontend ESLint is not configured in this source handoff. Treat `pnpm lint` as a failing readiness gate until a frontend integration slice configures linting."
)

process.exit(1)
