import assert from "node:assert/strict"
import { existsSync, readdirSync, readFileSync, statSync } from "node:fs"
import { createRequire } from "node:module"
import { dirname, join } from "node:path"
import { fileURLToPath } from "node:url"
import test from "node:test"

const root = dirname(dirname(fileURLToPath(import.meta.url)))
const ts = createRequire(import.meta.url)("typescript")

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

function resolveJsonPointer(document, pointer) {
  assert.match(pointer, /^#\//, `schema reference must be local: ${pointer}`)

  return pointer
    .slice(2)
    .split("/")
    .map((part) => part.replaceAll("~1", "/").replaceAll("~0", "~"))
    .reduce((value, part) => value[part], document)
}

function normalizedResolvedSchema(node, document) {
  if (Array.isArray(node)) {
    return node.map((item) => normalizedResolvedSchema(item, document))
  }
  if (node === null || typeof node !== "object") {
    return node
  }
  if (node.$ref) {
    return normalizedResolvedSchema(resolveJsonPointer(document, node.$ref), document)
  }

  const metadataKeys = new Set(["$schema", "$id", "$defs", "title", "description", "x-contract-only"])
  return Object.fromEntries(
    Object.entries(node)
      .filter(([key]) => !metadataKeys.has(key))
      .map(([key, value]) => [key, normalizedResolvedSchema(value, document)]),
  )
}

function normalizedShape(shape) {
  if (shape.kind === "union") {
    const branches = shape.branches.flatMap((branch) => {
      const normalized = normalizedShape(branch)
      return normalized.kind === "union" ? normalized.branches : [normalized]
    })
    const unique = new Map(branches.map((branch) => [JSON.stringify(branch), branch]))
    const normalizedBranches = [...unique.values()].sort((left, right) =>
      JSON.stringify(left).localeCompare(JSON.stringify(right)),
    )
    return normalizedBranches.length === 1
      ? normalizedBranches[0]
      : { kind: "union", branches: normalizedBranches }
  }
  if (shape.kind === "object") {
    return {
      kind: "object",
      properties: Object.fromEntries(
        Object.entries(shape.properties)
          .sort(([left], [right]) => left.localeCompare(right))
          .map(([name, property]) => [
            name,
            { required: property.required, shape: normalizedShape(property.shape) },
          ]),
      ),
    }
  }
  if (shape.kind === "array") {
    return { kind: "array", items: normalizedShape(shape.items) }
  }
  return shape
}

function unionShape(branches) {
  return normalizedShape({ kind: "union", branches })
}

function schemaShape(node, document) {
  if (node.$ref) {
    return schemaShape(resolveJsonPointer(document, node.$ref), document)
  }
  if (node.oneOf || node.anyOf) {
    return unionShape((node.oneOf ?? node.anyOf).map((branch) => schemaShape(branch, document)))
  }
  if (Object.hasOwn(node, "const")) {
    return { kind: "literal", value: node.const }
  }
  if (node.enum) {
    return unionShape(node.enum.map((value) => ({ kind: "literal", value })))
  }
  if (Array.isArray(node.type)) {
    return unionShape(node.type.map((type) => schemaShape({ ...node, type }, document)))
  }
  if (node.type === "object") {
    const required = new Set(node.required ?? [])
    return normalizedShape({
      kind: "object",
      properties: Object.fromEntries(
        Object.entries(node.properties ?? {}).map(([name, property]) => [
          name,
          { required: required.has(name), shape: schemaShape(property, document) },
        ]),
      ),
    })
  }
  if (node.type === "array") {
    return { kind: "array", items: schemaShape(node.items, document) }
  }
  if (node.type === "integer" || node.type === "number") {
    return { kind: "number" }
  }
  if (["string", "boolean", "null"].includes(node.type)) {
    return { kind: node.type }
  }
  throw new Error(`unsupported passive schema shape: ${JSON.stringify(node)}`)
}

function typeScriptAliases(source) {
  const sourceFile = ts.createSourceFile("laravel-api.ts", source, ts.ScriptTarget.Latest, true, ts.ScriptKind.TS)
  return new Map(
    sourceFile.statements
      .filter(ts.isTypeAliasDeclaration)
      .map((declaration) => [
        declaration.name.text,
        {
          type: declaration.type,
          exported: declaration.modifiers?.some((modifier) => modifier.kind === ts.SyntaxKind.ExportKeyword) ?? false,
        },
      ]),
  )
}

function typeScriptShape(node, aliases, cache = new Map(), resolving = new Set()) {
  if (ts.isParenthesizedTypeNode(node)) {
    return typeScriptShape(node.type, aliases, cache, resolving)
  }
  if (ts.isUnionTypeNode(node)) {
    return unionShape(node.types.map((branch) => typeScriptShape(branch, aliases, cache, resolving)))
  }
  if (ts.isArrayTypeNode(node)) {
    return { kind: "array", items: typeScriptShape(node.elementType, aliases, cache, resolving) }
  }
  if (ts.isTypeLiteralNode(node)) {
    const properties = {}
    for (const member of node.members) {
      assert.ok(ts.isPropertySignature(member), "passive contract type members must be property signatures")
      assert.ok(member.type, "passive contract properties must declare a type")
      const name = member.name.text
      properties[name] = {
        required: member.questionToken === undefined,
        shape: typeScriptShape(member.type, aliases, cache, resolving),
      }
    }
    return normalizedShape({ kind: "object", properties })
  }
  if (ts.isTypeReferenceNode(node)) {
    const name = node.typeName.text
    assert.equal(node.typeArguments?.length ?? 0, 0, `${name} must not hide passive structure in a generic`)
    assert.ok(aliases.has(name), `passive type reference ${name} must resolve to a local alias`)
    if (cache.has(name)) {
      return cache.get(name)
    }
    assert.equal(resolving.has(name), false, `recursive passive alias ${name} is unsupported`)
    resolving.add(name)
    const resolved = typeScriptShape(aliases.get(name).type, aliases, cache, resolving)
    resolving.delete(name)
    cache.set(name, resolved)
    return resolved
  }
  if (ts.isLiteralTypeNode(node)) {
    if (node.literal.kind === ts.SyntaxKind.NullKeyword) {
      return { kind: "null" }
    }
    if (ts.isStringLiteral(node.literal) || ts.isNumericLiteral(node.literal)) {
      return {
        kind: "literal",
        value: ts.isNumericLiteral(node.literal) ? Number(node.literal.text) : node.literal.text,
      }
    }
    if (node.literal.kind === ts.SyntaxKind.TrueKeyword || node.literal.kind === ts.SyntaxKind.FalseKeyword) {
      return { kind: "literal", value: node.literal.kind === ts.SyntaxKind.TrueKeyword }
    }
  }
  const primitiveKinds = new Map([
    [ts.SyntaxKind.StringKeyword, "string"],
    [ts.SyntaxKind.NumberKeyword, "number"],
    [ts.SyntaxKind.BooleanKeyword, "boolean"],
  ])
  if (primitiveKinds.has(node.kind)) {
    return { kind: primitiveKinds.get(node.kind) }
  }
  throw new Error(`unsupported passive TypeScript shape: ${ts.SyntaxKind[node.kind]}`)
}

function assertCompletePassiveTypeParity(source, parityPairs) {
  source = source.replaceAll("\r\n", "\n")
  const typeNames = new Map([
    ["LearningRevision", "LaravelLearningRevision"],
    ["LearningSourceRevisionTuple", "LaravelLearningSourceRevisions"],
    ["LearningTopicLabel", "LaravelLearningTopicLabel"],
    ["LearningDatapointDecision", "LaravelLearningDatapointDecision"],
    ["LearningCaseV1", "LaravelLearningCaseV1"],
    ["LearningEligibilityCase", "LaravelLearningEligibilityCase"],
    ["LearningEligibilityTombstone", "LaravelLearningEligibilityTombstone"],
    ["LearningEligibilityManifestV1", "LaravelLearningEligibilityManifestV1"],
  ])

  const compare = (candidateSource) => {
    const aliases = typeScriptAliases(candidateSource)
    for (const [schemaName, standaloneNode, standaloneDocument] of parityPairs) {
      const typeName = typeNames.get(schemaName)
      assert.ok(aliases.has(typeName), `${typeName} must be an exported passive type alias`)
      assert.equal(aliases.get(typeName).exported, true, `${typeName} must remain exported`)
      assert.deepEqual(
        normalizedShape(typeScriptShape(aliases.get(typeName).type, aliases)),
        normalizedShape(schemaShape(standaloneNode, standaloneDocument)),
        `${typeName} must exactly match the resolved passive schema structure`,
      )
    }
  }

  compare(source)

  const mutations = [
    ["export modifier", "export type LaravelLearningRevision =", "type LaravelLearningRevision ="],
    ["removed field", "  revision: number\n  digest: string\n}", "  revision: number\n}"],
    ["optional field", "  generation: number\n  revision: number", "  generation?: number\n  revision: number"],
    ["array nesting", "  reason_codes: string[]", "  reason_codes: string[][]"],
    ["literal member", "observed_mask: 1 }", "observed_mask: boolean }"],
    ["enum member", 'source_kind: "human_product" | "report" | "synthetic"', 'source_kind: "human_product" | "report"'],
    ["null union", "  note: string | null", "  note: string"],
    ["number type", "  authorization_generation: number", "  authorization_generation: string"],
    ["string type", "  company_group_key: string", "  company_group_key: number"],
    ["boolean type", "  relevant: boolean", "  relevant: string"],
  ]
  for (const [name, original, replacement] of mutations) {
    assert.ok(source.includes(original), `${name} mutation fixture must match the passive source`)
    assert.throws(
      () => compare(source.replace(original, replacement)),
      /must (?:exactly match the resolved passive schema structure|remain exported)/,
      `${name} mutation must be rejected by complete parity comparison`,
    )
  }
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

test("T01A passive closed contracts retain parity alongside exact T06 transport", () => {
  const contract = JSON.parse(
    readFileSync(join(root, "../contracts/api/frontend-characterization-openapi-v0.json"), "utf8"),
  )
  const learningCaseSchema = JSON.parse(
    readFileSync(join(root, "../contracts/api/learning-case-v1.schema.json"), "utf8"),
  )
  const eligibilitySchema = JSON.parse(
    readFileSync(join(root, "../contracts/api/learning-eligibility-v1.schema.json"), "utf8"),
  )
  const schemas = contract.components.schemas
  const learningTimestampPattern = String.raw`^\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$`

  assert.equal(learningCaseSchema.additionalProperties, false)
  assert.equal(eligibilitySchema.additionalProperties, false)
  assert.deepEqual(learningCaseSchema.$defs.TopicLabel.oneOf[0].properties.value.enum, [0, 1])
  assert.equal(learningCaseSchema.$defs.TopicLabel.oneOf[1].properties.observed_mask.const, 0)
  assert.equal(learningCaseSchema.$defs.TopicLabel.oneOf[1].properties.value.type, "null")
  assert.ok(learningCaseSchema.required.includes("closure_evidence"))
  assert.ok(learningCaseSchema.required.includes("rights"))
  assert.ok(eligibilitySchema.required.includes("canonical_digest"))
  assert.ok(eligibilitySchema.required.includes("tombstones"))

  for (const timestampSchema of [
    learningCaseSchema.properties.closure_evidence.properties.recorded_at,
    eligibilitySchema.properties.issued_at,
    eligibilitySchema.properties.valid_until,
    eligibilitySchema.$defs.Tombstone.properties.at,
    schemas.LearningCaseV1.properties.closure_evidence.properties.recorded_at,
    schemas.LearningEligibilityManifestV1.properties.issued_at,
    schemas.LearningEligibilityManifestV1.properties.valid_until,
    schemas.LearningEligibilityTombstone.properties.at,
  ]) {
    assert.equal(timestampSchema.format, "date-time")
    assert.equal(timestampSchema.pattern, learningTimestampPattern)
  }

  for (const name of [
    "LearningRevision",
    "LearningSourceRevisionTuple",
    "LearningTopicLabel",
    "LearningDatapointDecision",
    "LearningCaseV1",
    "LearningEligibilityCase",
    "LearningEligibilityTombstone",
    "LearningEligibilityManifestV1",
  ]) {
    assert.ok(schemas[name], `${name} must be reusable from OpenAPI components`)
    assert.equal(schemas[name]["x-contract-only"], true, `${name} must remain contract-only in T01A`)
  }
  for (const name of [
    "LearningRevision",
    "LearningSourceRevisionTuple",
    "LearningDatapointDecision",
    "LearningCaseV1",
    "LearningEligibilityCase",
    "LearningEligibilityTombstone",
    "LearningEligibilityManifestV1",
  ]) {
    assert.equal(schemas[name].additionalProperties, false, `${name} must be closed`)
  }
  for (const branch of schemas.LearningTopicLabel.oneOf) {
    assert.equal(branch.additionalProperties, false, "each learning topic label branch must be closed")
  }

  const parityPairs = [
    ["LearningRevision", learningCaseSchema.$defs.Revision, learningCaseSchema, schemas.LearningRevision, contract],
    [
      "LearningSourceRevisionTuple",
      learningCaseSchema.$defs.SourceRevisionTuple,
      learningCaseSchema,
      schemas.LearningSourceRevisionTuple,
      contract,
    ],
    ["LearningTopicLabel", learningCaseSchema.$defs.TopicLabel, learningCaseSchema, schemas.LearningTopicLabel, contract],
    [
      "LearningDatapointDecision",
      learningCaseSchema.$defs.DatapointDecision,
      learningCaseSchema,
      schemas.LearningDatapointDecision,
      contract,
    ],
    ["LearningCaseV1", learningCaseSchema, learningCaseSchema, schemas.LearningCaseV1, contract],
    [
      "LearningEligibilityCase",
      eligibilitySchema.$defs.CaseEntry,
      eligibilitySchema,
      schemas.LearningEligibilityCase,
      contract,
    ],
    [
      "LearningEligibilityTombstone",
      eligibilitySchema.$defs.Tombstone,
      eligibilitySchema,
      schemas.LearningEligibilityTombstone,
      contract,
    ],
    [
      "LearningEligibilityManifestV1",
      eligibilitySchema,
      eligibilitySchema,
      schemas.LearningEligibilityManifestV1,
      contract,
    ],
  ]
  for (const [name, standaloneNode, standaloneDocument, openapiNode, openapiDocument] of parityPairs) {
    assert.deepEqual(
      normalizedResolvedSchema(openapiNode, openapiDocument),
      normalizedResolvedSchema(standaloneNode, standaloneDocument),
      `${name} must exactly match its resolved standalone schema`,
    )
  }

  const safeInteger = { minimum: 0, maximum: 9007199254740991 }
  for (const numericSchema of [
    learningCaseSchema.$defs.Revision.properties.generation,
    learningCaseSchema.$defs.Revision.properties.revision,
    learningCaseSchema.properties.rights.properties.authorization_generation,
    eligibilitySchema.properties.generation,
    eligibilitySchema.$defs.Revision.properties.generation,
    eligibilitySchema.$defs.Revision.properties.revision,
    schemas.LearningRevision.properties.generation,
    schemas.LearningRevision.properties.revision,
    schemas.LearningCaseV1.properties.rights.properties.authorization_generation,
    schemas.LearningEligibilityManifestV1.properties.generation,
  ]) {
    assert.equal(numericSchema.type, "integer")
    assert.equal(numericSchema.minimum, safeInteger.minimum)
    assert.equal(numericSchema.maximum, safeInteger.maximum)
  }

  assert.deepEqual(
    Object.keys(contract.paths).filter((path) => path.includes("learning-case") || path.includes("learning-authorization")),
    ["/api/learning-case/draft", "/api/learning-case/close", "/api/learning-case/withdraw"],
    "T06 publishes only its three closure paths; authorization remains deferred",
  )

  const clientSource = read("lib/laravel-api.ts")
  assertCompletePassiveTypeParity(clientSource, parityPairs)
  for (const typeName of [
    "LaravelLearningCaseV1",
    "LaravelLearningEligibilityManifestV1",
    "LaravelLearningTopicLabel",
    "LaravelLearningSourceRevisions",
  ]) {
    assert.match(clientSource, new RegExp(`export\\s+type\\s+${typeName}\\b`), `${typeName} must be a passive exported type`)
  }
  assert.match(clientSource, /generation:\s*number/)
  assert.match(clientSource, /revision:\s*number/)
  assert.match(clientSource, /authorization_generation:\s*number/)
  const operationalHelpers = [
    ["getLaravelLearningCaseDraft", "/learning-case/draft", "GET", "show"],
    ["saveLaravelLearningCaseDraft", "/learning-case/draft", "PUT", "update"],
    ["closeLaravelLearningCase", "/learning-case/close", "POST", "close"],
    ["withdrawLaravelLearningCase", "/learning-case/withdraw", "POST", "withdraw"],
  ]
  const clientAst = ts.createSourceFile("laravel-api.ts", clientSource, ts.ScriptTarget.Latest, true)
  const routes = readFileSync(join(root, "../web/routes/api.php"), "utf8")
  const controller = readFileSync(join(root, "../web/app/Http/Controllers/Api/LearningCaseController.php"), "utf8")
  const actualRoutes = [...routes.matchAll(/Route::(get|put|post|patch|delete)\('((?:learning-case|learning-authorization)[^']*)', \[\\App\\Http\\Controllers\\Api\\LearningCaseController::class, '([^']+)'\]/g)].map((match) => [match[1].toUpperCase(), `/${match[2]}`, match[3]])
  assert.deepEqual(actualRoutes, operationalHelpers.map(([, path, method, action]) => [method, path, action]))
  const groupStart = routes.indexOf("Route::middleware(['web', 'private-dev-user', 'auth', 'verified.required'])->group(function () {")
  assert.ok(groupStart >= 0)
  const groupRoutes = routes.slice(groupStart, routes.indexOf("// Session must", groupStart))
  for (const [name, path, method, action] of operationalHelpers) {
    assert.deepEqual(Object.keys(contract.paths[`/api${path}`]).sort(), path === "/learning-case/draft" ? ["get", "put"] : ["post"])
    const operation = contract.paths[`/api${path}`][method.toLowerCase()]
    assert.deepEqual(operation.security, [{ laravelSession: [] }])
    if (method !== "GET") assert.ok(operation.responses["419"], "mutations document session CSRF")
    const fn = clientAst.statements.find((node) => ts.isFunctionDeclaration(node) && node.name?.text === name)
    assert.ok(fn?.body && fn.modifiers?.some((modifier) => modifier.kind === ts.SyntaxKind.ExportKeyword), `${name} must be an implemented exported function`)
    assert.ok(fn.body.getText(clientAst).includes(`("${path}",`), `${name} uses the exact operational path`)
    assert.ok(fn.body.getText(clientAst).includes(`method: "${method}"`), `${name} uses the exact HTTP verb`)
    assert.ok(groupRoutes.includes(`'${path.slice(1)}'`), "route remains inside session/auth/verified middleware group")
    assert.match(controller, new RegExp(`public function ${action}\\(`))
  }
  assert.match(clientSource, /credentials:\s*"include"/)
  assert.match(clientSource, /headers\.set\("X-CSRF-TOKEN", csrfToken\)/)
  assert.match(clientSource, /headers\.set\("X-XSRF-TOKEN", xsrfToken\)/)
  const frameworkMiddleware = readFileSync(join(root, "../web/vendor/laravel/framework/src/Illuminate/Foundation/Configuration/Middleware.php"), "utf8")
  assert.match(frameworkMiddleware, /StartSession::class/)
  assert.match(frameworkMiddleware, /ValidateCsrfToken::class/)
  for (const helperName of [
    "getLearningCaseDraft",
    "updateLearningCaseDraft",
    "closeLearningCase",
    "getLearningAuthorization",
    "updateLearningAuthorization",
    "revokeLearningAuthorization",
    "updateLaravelLearningCaseDraft",
    "getLaravelLearningAuthorization",
    "updateLaravelLearningAuthorization",
    "revokeLaravelLearningAuthorization",
  ]) {
    assert.doesNotMatch(
      clientSource,
      new RegExp(`export\\s+(?:async\\s+)?function\\s+${helperName}\\b`),
      `${helperName} must remain deferred until its controller exists`,
    )
  }
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
      const expectedRef = path.startsWith("/api/learning-case/")
        ? "#/components/responses/LearningCaseConflictOrEmailUnverified"
        : method === "put" && [
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

test("learning closure 409 matches controller body and email oneOf exactly", () => {
  const contract = JSON.parse(readFileSync(join(root, "../contracts/api/frontend-characterization-openapi-v0.json"), "utf8"))
  const operations = [["/api/learning-case/draft", "get"], ["/api/learning-case/draft", "put"], ["/api/learning-case/close", "post"], ["/api/learning-case/withdraw", "post"]]
  for (const [path, method] of operations) {
    assert.equal(contract.paths[path][method].responses["409"].$ref, "#/components/responses/LearningCaseConflictOrEmailUnverified")
  }
  const body = contract.components.schemas.LearningCaseConflictBody
  assert.deepEqual(body, { type: "object", additionalProperties: false, required: ["code", "message"], properties: { code: { type: "string", const: "learning_case_conflict" }, message: { type: "string" } } })
  assert.deepEqual(contract.components.responses.LearningCaseConflictOrEmailUnverified.content["application/json"].schema.oneOf, [{ $ref: "#/components/schemas/LearningCaseConflictBody" }, { $ref: "#/components/schemas/EmailUnverifiedBody" }])
  const controller = readFileSync(join(root, "../web/app/Http/Controllers/Api/LearningCaseController.php"), "utf8")
  assert.match(controller, /response\(\)->json\(\['code'=>\$forbidden\?'learning_case_blocked':'learning_case_conflict','message'=>\$forbidden\?/)
  assert.match(controller, /\$forbidden\?403:409/)
})
