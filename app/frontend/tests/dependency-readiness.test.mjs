import assert from "node:assert/strict"
import { readFileSync } from "node:fs"
import { join } from "node:path"
import test from "node:test"

const packageJson = JSON.parse(readFileSync("package.json", "utf8"))

test("frontend dependencies do not retain the unused Recharts/Lodash chart stack", () => {
  assert.equal(packageJson.dependencies?.recharts, undefined)

  const chartSource = readFileSync(join("components", "ui", "chart.tsx"), "utf8")
  assert.doesNotMatch(chartSource, /from ['"]recharts['"]/)
})

test("frontend package pins PostCSS above the audited vulnerable Next transitive version", () => {
  assert.equal(packageJson.pnpm?.overrides?.postcss, "8.5.15")
})
