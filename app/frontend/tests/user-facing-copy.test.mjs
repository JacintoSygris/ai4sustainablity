import assert from "node:assert/strict"
import { readdirSync, readFileSync, statSync } from "node:fs"
import { dirname, join, relative } from "node:path"
import test from "node:test"
import { fileURLToPath } from "node:url"

const sourceRoot = dirname(dirname(fileURLToPath(import.meta.url)))

function walk(directory) {
  const files = []

  for (const entry of readdirSync(directory)) {
    const path = join(directory, entry)
    const stat = statSync(path)

    if (stat.isDirectory()) {
      files.push(...walk(path))
      continue
    }

    if (path.endsWith(".tsx") || path.endsWith(".ts")) {
      files.push(path)
    }
  }

  return files
}

test("user-facing frontend string literals do not expose backend framework names in any locale", () => {
  const files = [
    ...walk(join(sourceRoot, "app")),
    ...walk(join(sourceRoot, "components")),
    ...walk(join(sourceRoot, "lib")),
  ]

  const offenders = []
  const internalLinePattern =
    /\b(import|from|type|interface|function|const|let|class|export)\b|Laravel[A-Z]|getLaravel|saveLaravel|submitLaravel|updateLaravel|fetchLaravel|laravelApi|laravelAuth|instanceof LaravelApiError|as Laravel/
  const errorMessagePattern = /new\s+LaravelApiError\(\s*(["'`])[^"'`]*Laravel/

  function isInternalReference(line) {
    if (errorMessagePattern.test(line)) {
      return false
    }

    return internalLinePattern.test(line)
  }

  for (const file of files) {
    const source = readFileSync(file, "utf8")
    const matches = source
      .split(/\r?\n/)
      .map((line, index) => ({ line, index: index + 1 }))
      .filter(({ line }) => line.includes("Laravel") && !isInternalReference(line))
      .map(({ line, index }) => `${relative(sourceRoot, file)}:${index}: ${line.trim()}`)

    if (matches.length > 0) {
      offenders.push(...matches)
    }
  }

  assert.deepEqual(offenders, [])
})

test("user-facing frontend copy does not promise filing or guaranteed compliance", () => {
  const files = [
    ...walk(join(sourceRoot, "app")),
    ...walk(join(sourceRoot, "components")),
  ]
  const offenders = []
  const riskyPatterns = [
    /100\s*%?\s+conformes?\b/i,
    /Taxonom[ií]a\s+UE\s+y\s+CSRD/i,
    /xHTML\s*(\+|e|\/|and)\s*iXBRL/i,
  ]

  function isExplicitLimitation(line) {
    return /\b(no|sin|not|not available|no disponible|no est[aá])\b/i.test(line)
  }

  for (const file of files) {
    const source = readFileSync(file, "utf8")
    const matches = source
      .split(/\r?\n/)
      .map((line, index) => ({ line, index: index + 1 }))
      .filter(({ line }) => riskyPatterns.some((pattern) => pattern.test(line)) && !isExplicitLimitation(line))
      .map(({ line, index }) => `${relative(sourceRoot, file)}:${index}: ${line.trim()}`)

    offenders.push(...matches)
  }

  assert.deepEqual(offenders, [])
})
