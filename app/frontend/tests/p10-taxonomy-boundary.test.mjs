import assert from "node:assert/strict"
import { readFileSync } from "node:fs"
import { join } from "node:path"
import test from "node:test"

function read(relativePath) {
  return readFileSync(join(process.cwd(), relativePath), "utf8")
}

test("P10 frontend shows safe taxonomy identity and does not advertise candidate downloads", () => {
  const panel = read("components/wizard/report-draft-panel.tsx")
  const api = read("lib/laravel-api.ts")

  assert.match(api, /getLaravelReportTaxonomyStatus/)
  assert.match(api, /\/report\/taxonomy/)
  assert.match(api, /EFRAG ESRS XBRL Taxonomy Set 1/)
  assert.match(api, /2023-12-22/)
  assert.match(api, /esrs-2023-preparatory-v1/)
  assert.match(panel, /Taxonomía ESRS externa/)
  assert.match(panel, /candidato XHTML\/iXBRL no se ofrece como descarga/)
  assert.doesNotMatch(panel, /taxonomy_package_path|manifest_path|sha256/i)
})
