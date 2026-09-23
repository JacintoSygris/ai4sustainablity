import assert from "node:assert/strict"
import { readFileSync } from "node:fs"
import { join } from "node:path"
import test from "node:test"

const packageJson = JSON.parse(readFileSync("package.json", "utf8"))
const workspaceConfig = readFileSync("pnpm-workspace.yaml", "utf8")

test("frontend dependencies do not retain the unused Recharts/Lodash chart stack", () => {
  assert.equal(packageJson.dependencies?.recharts, undefined)

  const chartSource = readFileSync(join("components", "ui", "chart.tsx"), "utf8")
  assert.doesNotMatch(chartSource, /from ['"]recharts['"]/)
})

test("frontend workspace pins PostCSS above the audited vulnerable Next transitive version", () => {
  assert.match(workspaceConfig, /^overrides:\n\s+postcss: 8\.5\.28\s*$/m)
  assert.equal(packageJson.pnpm, undefined)
})

test("frontend pins a patched Next release and a fixed pnpm toolchain", () => {
  const [major, minor, patch] = packageJson.dependencies.next.split(".").map(Number)
  assert.ok(major > 16 || (major === 16 && (minor > 3 || (minor === 3 && patch >= 3))), "next must be >= 16.3.3")
  assert.equal(packageJson.devDependencies["eslint-config-next"], packageJson.dependencies.next)
  assert.match(packageJson.packageManager ?? "", /^pnpm@\d+\.\d+\.\d+$/)
})

test("frontend workspace keeps the pnpm release-age supply-chain check intact", () => {
  assert.doesNotMatch(workspaceConfig, /minimumReleaseAgeExclude/)
  assert.match(workspaceConfig, /^allowBuilds:\n\s+sharp: true\n\s+unrs-resolver: true\s*$/m)
})
