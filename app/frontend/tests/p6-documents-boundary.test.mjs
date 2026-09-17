import assert from "node:assert/strict"
import { readFileSync } from "node:fs"
import { dirname, join } from "node:path"
import { fileURLToPath, pathToFileURL } from "node:url"
import test from "node:test"

const root = dirname(dirname(fileURLToPath(import.meta.url)))
const {
  DOCUMENT_EVIDENCE_LOW_CONFIDENCE_THRESHOLD,
  deletedDocumentIds,
  documentEvidenceFromProposal,
  evidenceNeedsDocumentReview,
  flattenTopicEvidence,
  hasDocumentEvidence,
  staleDocuments,
  standardLevelDocumentEvidenceTopics,
  topicDocumentProvenance,
  topicHasNegativeDocumentEvidence,
} = await import(pathToFileURL(join(root, "lib/p6-document-evidence.mjs")).href)

function read(relativePath) {
  return readFileSync(join(root, relativePath), "utf8")
}

const sampleDocumentEvidence = {
  documents: [
    { id: 1, original_filename: "informe-2024.pdf", status: "extracted", stale: false, deleted: false },
    { id: 2, original_filename: "politicas.docx", status: "extracted", stale: true, deleted: false },
    { id: 3, original_filename: "antiguo.pdf", status: "extracted", stale: false, deleted: true },
  ],
  topics: [
    {
      esrs_code: "E3-1",
      standard: "E3",
      source: "document",
      kind: "positive",
      evidence: [
        { document_id: 1, page: 12, confidence: 0.9, snippet: "Consumo de agua en zonas de estrés hídrico." },
        { document_id: 1, page: 12, confidence: 0.9, snippet: "Consumo de agua en zonas de estrés hídrico." },
        { document_id: 3, page: 4, confidence: 0.8, snippet: "Captación de agua." },
      ],
    },
    {
      esrs_code: "S3-2",
      standard: "S3",
      source: "document",
      kind: "negative",
      evidence: [{ document_id: 1, page: 30, confidence: 0.3, snippet: "No se identifican comunidades afectadas." }],
    },
    {
      esrs_code: null,
      standard: "E4",
      source: "document",
      kind: "positive",
      evidence: [{ document_id: 2, page: 7, confidence: 0.7, snippet: "Zonas sensibles en biodiversidad." }],
    },
  ],
}

test("Laravel API client exposes typed characterization document helpers on the documents endpoints", () => {
  const source = read("lib/laravel-api.ts")

  assert.match(source, /LaravelCharacterizationDocument\b/, "client must type characterization documents")
  assert.match(source, /LaravelDocumentEvidence\b/, "client must type the document evidence block")
  assert.match(source, /LaravelDocumentEvidenceTopic\b/, "client must type document evidence topics")
  assert.match(source, /document_evidence\?:\s*LaravelDocumentEvidence/, "proposal extension must be optional (feature detection)")
  assert.match(source, /"uploaded"\s*\|\s*"extracting"\s*\|\s*"extracted"\s*\|\s*"failed"\s*\|\s*"no_usable_evidence"/, "document status union must follow the contract")
  assert.match(source, /listLaravelCharacterizationDocuments/, "client must expose the document list helper")
  assert.match(source, /uploadLaravelCharacterizationDocument/, "client must expose the multipart upload helper")
  assert.match(source, /deleteLaravelCharacterizationDocument/, "client must expose the delete helper")
  assert.match(source, /new FormData\(\)/, "upload helper must send multipart form data")
  assert.match(source, /"\/characterization\/documents"/, "list/upload helpers must call the documents endpoint")
  assert.match(source, /`\/characterization\/documents\/\$\{documentId\}`/, "delete helper must target a document id")
  assert.match(source, /method:\s*"DELETE"/, "delete helper must use the DELETE method")
})

test("document panel renders only behind document_evidence feature detection", () => {
  const formSource = read("components/wizard/material-topics-form.tsx")
  const panelSource = read("components/wizard/document-evidence-panel.tsx")

  assert.match(formSource, /documentEvidenceFromProposal/, "step 2 form must feature-detect via the tested helper")
  assert.match(
    formSource,
    /\{documentEvidence \? \(\s*<DocumentEvidencePanel/,
    "panel must render only when the document_evidence block is present",
  )
  assert.match(panelSource, /Documentos de tu empresa \(opcional\)/, "panel must use the agreed optional section title")
  assert.match(panelSource, /accept="\.pdf,\.docx"/, "file picker must hint the pdf/docx allowlist")
  assert.match(panelSource, /50 MB/, "panel must hint the 50 MB size cap")
  assert.match(panelSource, /Sin datos aprovechables/, "panel must map the no-usable-evidence status chip")
  assert.match(panelSource, /Analizando/, "panel must map the extracting status chip")
  assert.match(panelSource, /Analizado/, "panel must map the extracted status chip")
  assert.match(panelSource, /Subido/, "panel must map the uploaded status chip")
  assert.match(panelSource, /listLaravelCharacterizationDocuments/, "panel must list documents through the client helper")
  assert.match(panelSource, /uploadLaravelCharacterizationDocument/, "panel must upload through the client helper")
  assert.match(panelSource, /deleteLaravelCharacterizationDocument/, "panel must delete through the client helper")
  assert.match(panelSource, /Eliminar documento/, "delete must go through an explicit confirmation dialog")

  assert.equal(documentEvidenceFromProposal({}), null, "missing block means the feature is off")
  assert.equal(documentEvidenceFromProposal({ document_evidence: null }), null, "null block means the feature is off")
  assert.equal(hasDocumentEvidence({ proposal_topic_ids: [1] }), false)
  assert.deepEqual(documentEvidenceFromProposal({ document_evidence: { documents: [], topics: [] } }), {
    documents: [],
    topics: [],
  })
})

test("negative document evidence is context only and never touches the review controls", () => {
  const formSource = read("components/wizard/material-topics-form.tsx")
  const helperSource = read("lib/p6-document-evidence.mjs")

  assert.match(
    formSource,
    /El documento indica que podría no ser material/,
    "negative evidence must render as plain-language context",
  )
  assert.match(
    formSource,
    /\(\["accepted", "unsure", "rejected"\] as LaravelTopicAction\[\]\)/,
    "the accept/unsure/reject controls must stay exactly as before",
  )
  assert.doesNotMatch(
    helperSource,
    /accepted|rejected|unsure|topic_actions/,
    "evidence helpers must never produce or suggest review actions",
  )
  assert.doesNotMatch(
    formSource,
    /(onActionChange|setTopicAction)\([^)]*[Ee]vidence/,
    "document evidence must never drive a review action",
  )

  const entries = topicDocumentProvenance("S3-2", sampleDocumentEvidence).entries

  assert.equal(topicHasNegativeDocumentEvidence(entries), true)
  assert.equal(topicHasNegativeDocumentEvidence([]), false)
})

test("consent/retention copy lives in one draft-marked constant rendered above the upload control", () => {
  const consentSource = read("lib/p6-document-consent-copy.ts")
  const panelSource = read("components/wizard/document-evidence-panel.tsx")

  assert.match(consentSource, /DRAFT/, "constant must be explicitly marked draft pending sign-off")
  assert.match(consentSource, /export const P6_DOCUMENT_CONSENT_COPY\b/, "copy must live in one exported constant")
  assert.match(consentSource, /de forma privada/, "copy must state private storage")
  assert.match(consentSource, /servicio de la plataforma/, "copy must state on-platform service analysis")
  assert.match(consentSource, /nunca se env[ií]a/, "copy must state content never leaves to external AI services")
  assert.match(consentSource, /hasta que decidas eliminarlo/, "copy must state keep-until-user-deletes retention")
  assert.match(consentSource, /se borra por completo/, "copy must state full purge on deletion")
  assert.match(panelSource, /P6_DOCUMENT_CONSENT_COPY/, "panel must render the shared consent constant")
  assert.doesNotMatch(panelSource, /servicio de la plataforma/, "panel must not duplicate the consent copy inline")

  const uploadControlIndex = panelSource.indexOf("Subir documento")
  const consentIndex = panelSource.indexOf("{P6_DOCUMENT_CONSENT_COPY}")

  assert.ok(consentIndex >= 0 && uploadControlIndex > consentIndex, "consent copy must render above the upload control")
})

test("provenance badges derive from the payload instead of per-topic hardcoding", () => {
  const formSource = read("components/wizard/material-topics-form.tsx")

  assert.match(formSource, /Perfil de empresa/, "profile provenance badge copy must exist")
  assert.match(formSource, /Documento subido/, "document provenance badge copy must exist")
  assert.match(formSource, /Revisar en el documento/, "low-confidence items must be labelled for document review")
  assert.match(formSource, /topicDocumentProvenance/, "badges must derive from the payload via the tested helper")
  assert.match(formSource, /flattenTopicEvidence/, "snippet drawer content must derive from the payload")
  assert.match(formSource, /p[áa]gina/, "evidence snippets must show page references")
  assert.doesNotMatch(
    formSource,
    /["'`](E[1-5]|S[1-4]|G1)(-\d+)?["'`]\s*[:=]/,
    "no per-topic code may be hardcoded to a provenance badge",
  )

  const withDocument = topicDocumentProvenance("E3-1", sampleDocumentEvidence)

  assert.equal(withDocument.profile, true)
  assert.equal(withDocument.document, true)
  assert.equal(withDocument.entries.length, 1)

  const withoutDocument = topicDocumentProvenance("E1-1", sampleDocumentEvidence)

  assert.equal(withoutDocument.profile, true)
  assert.equal(withoutDocument.document, false)
  assert.deepEqual(withoutDocument.entries, [])

  const standardLevel = standardLevelDocumentEvidenceTopics(sampleDocumentEvidence)

  assert.equal(standardLevel.length, 1)
  assert.equal(standardLevel[0].standard, "E4")
})

test("low-confidence, stale, deleted, and dedupe evidence states derive from the payload", () => {
  const formSource = read("components/wizard/material-topics-form.tsx")
  const panelSource = read("components/wizard/document-evidence-panel.tsx")

  assert.match(
    panelSource,
    /Los datos de la empresa cambiaron después de analizar el documento\. Vuelve a analizar si quieres\s*\n?\s*actualizar la evidencia\./,
    "stale banner copy must match the agreed wording",
  )
  assert.match(panelSource, /Documento eliminado — revisar/, "panel must surface the deleted-document state")
  assert.match(formSource, /Documento eliminado — revisar/, "topic evidence must surface the deleted-document state")

  assert.equal(evidenceNeedsDocumentReview(topicDocumentProvenance("S3-2", sampleDocumentEvidence).entries), true)
  assert.equal(evidenceNeedsDocumentReview(topicDocumentProvenance("E3-1", sampleDocumentEvidence).entries), false)
  assert.equal(DOCUMENT_EVIDENCE_LOW_CONFIDENCE_THRESHOLD > 0 && DOCUMENT_EVIDENCE_LOW_CONFIDENCE_THRESHOLD < 1, true)

  assert.deepEqual(
    staleDocuments(sampleDocumentEvidence).map((document) => document.id),
    [2],
    "stale detection must skip deleted documents",
  )
  assert.deepEqual([...deletedDocumentIds(sampleDocumentEvidence)], [3])

  const flattened = flattenTopicEvidence(topicDocumentProvenance("E3-1", sampleDocumentEvidence).entries, sampleDocumentEvidence)

  assert.equal(flattened.length, 2, "evidence must dedupe by document/page/snippet")
  assert.equal(flattened[0].documentDeleted, false)
  assert.equal(flattened[1].documentDeleted, true, "evidence from a deleted document must carry the deleted state")
  assert.equal(flattened[0].page, 12)
  assert.equal(flattened[0].confidence, 0.9)
})
