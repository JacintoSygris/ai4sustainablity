import assert from "node:assert/strict"
import { existsSync, readdirSync, readFileSync, statSync } from "node:fs"
import { dirname, join, relative } from "node:path"
import test from "node:test"
import { fileURLToPath } from "node:url"

const sourceRoot = dirname(dirname(fileURLToPath(import.meta.url)))
const ignoredFiles = new Set()
const glossaryPath = join(sourceRoot, "lib", "glossary.ts")
const glossaryKeys = [
  "materialidad",
  "doble_materialidad",
  "adm",
  "datapoint",
  "esrs",
  "requisito_divulgacion",
  "umbral",
  "grupos_interes",
  "cadena_valor",
  "fase_transicion",
]

function walk(directory) {
  const files = []

  for (const entry of readdirSync(directory)) {
    const path = join(directory, entry)
    const stat = statSync(path)

    if (stat.isDirectory()) {
      files.push(...walk(path))
      continue
    }

    if ((path.endsWith(".tsx") || path.endsWith(".ts")) && !ignoredFiles.has(path)) {
      files.push(path)
    }
  }

  return files
}

function isInternalReference(line) {
  return (
    /\b(import|from|function|const|let|interface|type|export)\b/.test(line) ||
    /\b(load|save|update|get|submit)P(5|6|7|8|9|10)\b/.test(line) ||
    /\bp(5|6|7|8|9|10)_/.test(line) ||
    /\bp(5|6|7|8|9|10)[A-Z][A-Za-z]+\b/.test(line)
  )
}

function isWizardFile(file) {
  const relativePath = relative(sourceRoot, file).replace(/\\/g, "/")

  return relativePath.startsWith("app/(dashboard)/wizard/") || relativePath.startsWith("components/wizard/")
}

function hasUserFacingForbiddenCopy(line, file) {
  if (isInternalReference(line)) {
    return false
  }

  const forbiddenBrandPattern = isWizardFile(file) ? "|Airis" : ""
  const hasStringOrJsxText = new RegExp(
    `["'\`>][^<]*\\b(P(5|6|7|8|9|10)|Stakeholders|Umbral ADM${forbiddenBrandPattern})\\b`,
  ).test(line)

  if (!hasStringOrJsxText) {
    return false
  }

  return (
    /(^|[\s"'`>])P(5|6|7|8|9|10)\b/.test(line) ||
    /\b(Stakeholders|Umbral ADM)\b/.test(line) ||
    (isWizardFile(file) && /\bAiris\b/.test(line))
  )
}

test("wizard-facing copy avoids internal step codes and jargon", () => {
  const files = [...walk(join(sourceRoot, "app")), ...walk(join(sourceRoot, "components"))]
  const offenders = []

  for (const file of files) {
    const source = readFileSync(file, "utf8")
    const matches = source
      .split(/\r?\n/)
      .map((line, index) => ({ line, index: index + 1 }))
      .filter(({ line }) => hasUserFacingForbiddenCopy(line, file))
      .map(({ line, index }) => `${relative(sourceRoot, file)}:${index}: ${line.trim()}`)

    offenders.push(...matches)
  }

  assert.deepEqual(offenders, [])
})

test("glossary contains the expected plain-language terms", () => {
  assert.equal(existsSync(glossaryPath), true)

  const source = readFileSync(glossaryPath, "utf8")

  for (const key of glossaryKeys) {
    assert.match(source, new RegExp(`\\b${key}\\b`))
  }
})
