import assert from "node:assert/strict"
import { existsSync, readdirSync, readFileSync, statSync } from "node:fs"
import { dirname, join } from "node:path"
import { fileURLToPath } from "node:url"
import test from "node:test"

const root = dirname(dirname(fileURLToPath(import.meta.url)))

function read(relativePath) {
  return readFileSync(join(root, relativePath), "utf8")
}

function listFiles(relativeDir, predicate = () => true, options = {}) {
  const base = join(root, relativeDir)
  if (!existsSync(base)) {
    return []
  }

  const excludeArchive = options.excludeArchive ?? true
  const files = []
  const visit = (dir) => {
    for (const entry of readdirSync(dir)) {
      const fullPath = join(dir, entry)
      const relativePath = fullPath.slice(root.length + 1).replaceAll("\\", "/")

      if ((excludeArchive && relativePath.startsWith("archive/")) || relativePath.startsWith(".next/") || relativePath.startsWith("node_modules/")) {
        continue
      }

      if (statSync(fullPath).isDirectory()) {
        visit(fullPath)
      } else if (predicate(relativePath)) {
        files.push(relativePath)
      }
    }
  }

  visit(base)
  return files
}

test("Phase 2 Laravel API client is same-origin by default and CSRF-capable", () => {
  const relativePath = "lib/laravel-api.ts"

  assert.equal(existsSync(join(root, relativePath)), true, `${relativePath} must exist`)

  const source = read(relativePath)

  assert.match(source, /\/api/, "client must default to same-origin /api")
  assert.match(source, /credentials:\s*["']include["']/, "client must include browser credentials")
  assert.match(source, /auth\/session/, "client must expose the Laravel frontend session endpoint")
  assert.match(source, /getLaravelSession/, "client must provide a typed session bootstrap helper")
  assert.match(source, /headers\.set\(["']Accept["'][\s\S]*application\/json/, "client must request JSON to avoid Laravel HTML redirects")
  assert.match(source, /X-Requested-With/, "client must mark browser API calls as XMLHttpRequest")
  assert.match(source, /X-CSRF-TOKEN|X-XSRF-TOKEN/, "client must support Laravel CSRF headers")
  assert.match(source, /AbortController|timeout/i, "client must define request timeout behavior")
})

test("published P6, P8, and P9 contracts require and return optimistic revisions", () => {
  const contract = JSON.parse(
    readFileSync(join(root, "../contracts/api/frontend-characterization-openapi-v0.json"), "utf8"),
  )
  const schemas = contract.components.schemas

  assert.ok(schemas.MaterialityProposalReviewRequest.required.includes("expected_revision"))
  assert.ok(schemas.MaterialityProposalReview.required.includes("revision"))
  assert.ok(schemas.MaterialityConfirmationRequest.required.includes("expected_revision"))
  assert.ok(schemas.MaterialityConfirmationDetails.required.includes("revision"))
  assert.ok(schemas.EsrsDatapointResponsesRequest.required.includes("expected_revision"))
  assert.ok(schemas.EsrsDatapointResponsesState.required.includes("revision"))

  for (const path of [
    "/api/materiality-proposal",
    "/api/materiality-confirmation",
    "/api/esrs-datapoints/responses",
  ]) {
    assert.equal(
      contract.paths[path].put.responses["409"].$ref,
      "#/components/responses/OptimisticConcurrencyOrEmailUnverified",
    )
  }
})

test("published P6, P8, and P9 schemas describe the complete Laravel response surfaces", () => {
  const contract = JSON.parse(
    readFileSync(join(root, "../contracts/api/frontend-characterization-openapi-v0.json"), "utf8"),
  )
  const schemas = contract.components.schemas

  for (const field of [
    "topic_sync_status",
    "model_profile",
    "model_key_count",
    "mapped_key_count",
    "feature_metadata",
    "mapping_metadata",
    "evidence_refs",
  ]) {
    assert.ok(schemas.MaterialityProposalAi.required.includes(field), `P6 ai must require ${field}`)
    assert.ok(schemas.MaterialityProposalAi.properties[field], `P6 ai must define ${field}`)
  }
  assert.ok(schemas.MaterialityProposalState.properties.document_evidence)
  assert.equal(schemas.MaterialityProposalState.required.includes("document_evidence"), false)

  for (const field of ["is_stale", "decision_basis", "p6_snapshot", "adm", "exposicion_defaults"]) {
    assert.ok(schemas.MaterialityConfirmationState.required.includes(field), `P8 state must require ${field}`)
  }
  for (const field of ["dimensions", "guided_answers"]) {
    assert.ok(schemas.MaterialityConfirmationDetails.required.includes(field), `P8 details must require ${field}`)
  }
  assert.deepEqual(schemas.MaterialityPreview.properties.mapping_granularity.enum, [
    "disclosure_requirement_mapping_required",
    "disclosure_requirement_level",
  ])
  assert.deepEqual(schemas.MaterialityPreview.properties.coverage_status.enum, ["topical_mapping_required", "dr_level"])
  assert.deepEqual(schemas.MaterialityDecisionSheetSummary.properties.coverage_status.enum, ["topical_mapping_required", "dr_level"])
  assert.ok(contract.paths["/api/materiality-confirmation/preview"].post)

  assert.deepEqual(schemas.EsrsDatapointResponsesState.properties.schema_version.enum, ["v0", "v1"])
  assert.ok(schemas.EsrsDatapointResponsesState.required.includes("orphaned"))
  for (const field of ["decided_count", "decided_required_count", "optional_response_count"]) {
    assert.ok(schemas.EsrsDatapointResponseSummary.required.includes(field), `P9 summary must require ${field}`)
  }
  const triageValues = ["have_it", "need_to_find", "not_applicable_candidate"]
  assert.deepEqual(schemas.EsrsDatapointResponseInput.properties.triage.enum, [...triageValues, null])
  assert.deepEqual(schemas.EsrsDatapointResponse.properties.triage.enum, triageValues)
})

test("published pagination envelopes reject undeclared top-level response fields", () => {
  const contract = JSON.parse(
    readFileSync(join(root, "../contracts/api/frontend-characterization-openapi-v0.json"), "utf8"),
  )

  for (const name of ["PaginatedNaceCodes", "PaginatedEsrsTopics"]) {
    assert.equal(
      contract.components.schemas[name].additionalProperties,
      false,
      `${name} must be a closed response envelope`,
    )
  }
})

test("published write and corpus schemas match Laravel validation and emitted payloads", () => {
  const contract = JSON.parse(
    readFileSync(join(root, "../contracts/api/frontend-characterization-openapi-v0.json"), "utf8"),
  )
  const schemas = contract.components.schemas
  const canonicalTopicKey = "^[1-9][0-9]*$"

  for (const field of ["topic_actions", "action_reasons", "action_notes"]) {
    assert.equal(schemas.MaterialityProposalReviewRequest.properties[field].propertyNames.pattern, canonicalTopicKey)
  }
  assert.equal(schemas.MaterialityProposalReviewRequest.properties.topic_actions.minProperties, 1)
  assert.equal(
    schemas.MaterialityConfirmationRequest.properties.dimensions.$ref,
    "#/components/schemas/MaterialityDimensions",
  )
  assert.equal(
    schemas.MaterialityConfirmationRequest.properties.guided_answers.$ref,
    "#/components/schemas/MaterialityGuidedAnswers",
  )

  assert.equal(schemas.EsrsDatapoint.properties.selection.$ref, "#/components/schemas/EsrsDatapointSelection")
  assert.ok(schemas.EsrsDatapoint.required.includes("selection"))
  for (const field of ["required_datapoint_count", "default_unselected_datapoint_count"]) {
    assert.ok(schemas.EsrsDatapointSummary.required.includes(field))
    assert.ok(schemas.EsrsDatapointSummary.properties[field])
  }
  assert.equal(schemas.EsrsPhaseInAssessment.properties.application.$ref, "#/components/schemas/EsrsPhaseInApplication")
  assert.ok(schemas.EsrsPhaseInAssessment.required.includes("application"))
  assert.deepEqual(schemas.EsrsMatterMapping.properties.coverage_status.enum, ["topical_mapping_required", "dr_level"])
  assert.deepEqual(schemas.EsrsMatterMapping.properties.current_filter.enum, [
    "topical_blocked_until_dr_mapping",
    "mapped_disclosure_requirements",
  ])
  assert.deepEqual(schemas.EsrsMatterMappingTopic.properties.current_filter.enum, [
    "topical_blocked_until_dr_mapping",
    "disclosure_requirement_level",
  ])
  assert.ok(schemas.EsrsCompletionPhase.properties.status.enum.includes("blocked"))
  assert.deepEqual(schemas.EsrsCompletionPhase.properties.coverage_status.enum, ["topical_mapping_required", "dr_level"])

  const currentYear = new Date().getUTCFullYear()
  assert.equal(schemas.CompanyProfile.properties.reporting_year.maximum, currentYear)
  assert.equal(schemas.DataReadinessItem.properties.year.maximum, currentYear)
  assert.match(read("components/wizard/initial-survey-form.tsx"), /max=\{new Date\(\)\.getFullYear\(\)\}/)
})

test("optimistic conflict schemas preserve both Laravel response shapes", () => {
  const contract = JSON.parse(
    readFileSync(join(root, "../contracts/api/frontend-characterization-openapi-v0.json"), "utf8"),
  )
  const schemas = contract.components.schemas
  const branches = schemas.OptimisticConcurrencyConflictBody.oneOf.map((branch) => branch.$ref)

  assert.deepEqual(branches, [
    "#/components/schemas/MaterialityRevisionConflictBody",
    "#/components/schemas/EsrsDatapointResponsesConflictBody",
  ])
  assert.deepEqual(schemas.MaterialityRevisionConflictBody.required, ["message", "code", "data"])
  assert.deepEqual(schemas.MaterialityRevisionConflictData.required, ["current_revision"])
  assert.deepEqual(schemas.EsrsDatapointResponsesConflictBody.required, ["message", "code", "current_revision", "data"])
  assert.equal(
    schemas.EsrsDatapointResponsesConflictBody.properties.data.$ref,
    "#/components/schemas/EsrsDatapointResponsesState",
  )
})

test("every session-protected OpenAPI operation documents email_unverified without losing concurrency conflicts", () => {
  const contract = JSON.parse(
    readFileSync(join(root, "../contracts/api/frontend-characterization-openapi-v0.json"), "utf8"),
  )
  const resolveRef = (ref) => ref.split("/").slice(1).reduce((value, key) => value[key], contract)
  const emailResponse = resolveRef("#/components/responses/EmailUnverified")
  const emailSchema = resolveRef(emailResponse.content["application/json"].schema.$ref)

  assert.deepEqual(emailSchema.required, ["message", "code"])
  assert.equal(emailSchema.additionalProperties, false)
  assert.equal(emailSchema.properties.code.const, "email_unverified")

  for (const [path, pathItem] of Object.entries(contract.paths)) {
    for (const [method, operation] of Object.entries(pathItem)) {
      if (!operation || !["get", "post", "put", "patch", "delete"].includes(method) || !operation.security?.length) continue
      const response409 = operation.responses?.["409"]
      assert.ok(response409, `${method.toUpperCase()} ${path} must document email_unverified 409`)
      const expectedRef = method === "put" && [
        "/api/materiality-proposal",
        "/api/materiality-confirmation",
        "/api/esrs-datapoints/responses",
      ].includes(path)
        ? "#/components/responses/OptimisticConcurrencyOrEmailUnverified"
        : "#/components/responses/EmailUnverified"
      assert.equal(response409.$ref, expectedRef, `${method.toUpperCase()} ${path} must preserve its exact 409 meanings`)
      if (expectedRef === "#/components/responses/OptimisticConcurrencyOrEmailUnverified") {
        const compositeSchema = resolveRef(expectedRef).content["application/json"].schema
        assert.deepEqual(
          compositeSchema.oneOf.map((branch) => branch.$ref),
          ["#/components/schemas/OptimisticConcurrencyConflictBody", "#/components/schemas/EmailUnverifiedBody"],
        )
      }
    }
  }
})

test("Next local dev can reserve /api/* for Laravel through rewrites", () => {
  const source = read("next.config.mjs")

  assert.match(source, /LARAVEL_API_ORIGIN/, "config must read LARAVEL_API_ORIGIN")
  assert.match(source, /async\s+rewrites\s*\(/, "config must define rewrites")
  assert.match(source, /source:\s*["']\/api\/:path\*["']/, "config must reserve /api/:path*")
  assert.match(source, /destination:\s*.*\/api\/:path\*/, "rewrite destination must preserve Laravel /api path")
})

test("imported Next backend routes are retired from active Next API paths", () => {
  const retiredRouteFiles = [
    "app/api/auth/[...all]/route.ts",
    "app/api/user/onboarding/route.ts",
    "app/api/wizard/reset-step/route.ts",
    "app/api/wizard/step-1/route.ts",
    "app/api/wizard/step-2/route.ts",
    "app/api/wizard/step-3/route.ts",
    "app/api/wizard/step-4/route.ts",
  ]

  for (const file of retiredRouteFiles) {
    assert.equal(existsSync(join(root, file)), false, `${file} must not remain routable in active Next app/api`)
  }
})

test("imported Better Auth Turso Drizzle helpers and scripts are superseded outside active paths", () => {
  const retiredActiveFiles = [
    "lib/auth-client.ts",
    "lib/auth.ts",
    "lib/db.ts",
    "lib/queries.ts",
    "lib/schema.ts",
    "lib/local-next-backend.ts",
    "scripts/run-migrations.js",
    "scripts/001-create-auth-tables.sql",
    "scripts/002-fix-auth-tables.sql",
    "scripts/003-create-reports-table.sql",
    "scripts/004-add-onboarding-fields.sql",
    "scripts/005-add-survey-data.sql",
  ]

  for (const file of retiredActiveFiles) {
    assert.equal(existsSync(join(root, file)), false, `${file} must not remain active`)
  }

  const archivedFiles = listFiles("archive/2026-06-06-imported-next-backend", () => true, { excludeArchive: false })

  assert.equal(archivedFiles.length, 19, "retired backend source must be preserved as archived files")

  for (const file of archivedFiles) {
    assert.doesNotMatch(file, /\.(ts|tsx)$/)
  }
})

test("active frontend source no longer imports retired backend packages or helpers", () => {
  const activeFiles = [
    ...listFiles("app", (file) => /\.(ts|tsx|js|mjs)$/.test(file)),
    ...listFiles("components", (file) => /\.(ts|tsx|js|mjs)$/.test(file)),
    ...listFiles("lib", (file) => /\.(ts|tsx|js|mjs)$/.test(file)),
    ...listFiles("scripts", (file) => /\.(ts|tsx|js|mjs)$/.test(file)),
  ]

  const forbiddenImport =
    /from\s+["'](?:better-auth(?:\/[^"']*)?|drizzle-orm(?:\/[^"']*)?|@libsql\/client|@\/lib\/(?:auth|auth-client|db|queries|schema|local-next-backend))["']|import\(["'](?:better-auth(?:\/[^"']*)?|drizzle-orm(?:\/[^"']*)?|@libsql\/client|@\/lib\/(?:auth|auth-client|db|queries|schema|local-next-backend))["']\)/

  for (const file of activeFiles) {
    assert.doesNotMatch(read(file), forbiddenImport, `${file} must not import the retired local backend`)
  }
})

test("environment example exposes only active Laravel integration variables without real values", () => {
  const source = read(".env.example")

  assert.match(source, /^LARAVEL_API_ORIGIN=/m)
  assert.match(source, /^NEXT_PUBLIC_LARAVEL_API_BASE_URL=/m)
  assert.doesNotMatch(source, /^I4S_AUTH_PROVIDER=/m)
  assert.doesNotMatch(source, /^I4S_ENABLE_LOCAL_NEXT_BACKEND=/m)
  assert.doesNotMatch(source, /^BETTER_AUTH_SECRET=/m)
  assert.doesNotMatch(source, /^TURSO_AUTH_TOKEN=/m)
  assert.doesNotMatch(source, /^TURSO_DATABASE_URL=/m)
  assert.doesNotMatch(source, /=.+\S/, "example env must list names only, not real values")
})

test("package manifest has no direct retired local backend dependencies", () => {
  const packageJson = JSON.parse(read("package.json"))

  assert.equal(packageJson.dependencies?.["better-auth"], undefined)
  assert.equal(packageJson.dependencies?.["drizzle-orm"], undefined)
  assert.equal(packageJson.dependencies?.["@libsql/client"], undefined)
})

test("visible help and password recovery links resolve to real Next pages", () => {
  assert.equal(existsSync(join(root, "app/help/page.tsx")), true, "/help must not 404 from header links")
  assert.equal(existsSync(join(root, "app/(dashboard)/settings/page.tsx")), true, "/settings must not 404 from user menu links")
  assert.equal(
    existsSync(join(root, "app/(auth)/forgot-password/page.tsx")),
    true,
    "/forgot-password must not 404 from login links",
  )
})

test("landing feature icons do not request generated placeholder jpg URLs", () => {
  const source = read("components/landing/features-section.tsx")

  assert.doesNotMatch(source, /\/\.jpg\?/, "feature icons must not point to invalid placeholder image URLs")
  assert.match(source, /<feature\.icon/, "feature icons should render from the local icon component")
})

test("root layout does not load Vercel-only analytics on the VPS frontend", () => {
  const source = read("app/layout.tsx")

  assert.doesNotMatch(source, /@vercel\/analytics/, "VPS deployment must not request /_vercel/insights/script.js")
  assert.doesNotMatch(source, /<Analytics/, "Vercel Analytics component must not render on the VPS deployment")
})
