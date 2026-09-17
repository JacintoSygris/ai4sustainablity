// Pure helpers for the optional document-evidence block on the step-2 proposal
// payload (contract: app/contracts/api/p6-document-extraction-v0.md).
//
// Feature detection rule: the block is only present when the platform enables
// document extraction. Absence means the feature is off — every consumer must
// render nothing document-related in that case.
//
// Document evidence is reviewer context only: nothing in this module derives,
// suggests, or mutates review actions for a topic. The review controls stay
// exclusively user-driven.

export const DOCUMENT_EVIDENCE_LOW_CONFIDENCE_THRESHOLD = 0.5

export function documentEvidenceFromProposal(proposal) {
  const evidence = proposal?.document_evidence

  if (!evidence || typeof evidence !== "object" || Array.isArray(evidence)) {
    return null
  }

  return {
    documents: Array.isArray(evidence.documents) ? evidence.documents : [],
    topics: Array.isArray(evidence.topics) ? evidence.topics : [],
  }
}

export function hasDocumentEvidence(proposal) {
  return documentEvidenceFromProposal(proposal) != null
}

export function documentEvidenceTopicsByEsrsCode(documentEvidence) {
  const byCode = {}

  for (const topic of documentEvidence?.topics ?? []) {
    if (typeof topic?.esrs_code !== "string" || topic.esrs_code === "") {
      continue
    }

    if (!byCode[topic.esrs_code]) {
      byCode[topic.esrs_code] = []
    }

    byCode[topic.esrs_code].push(topic)
  }

  return byCode
}

// Evidence the extractor could not resolve to a child topic key
// (esrs_code null = standard-level context; never rendered as a review card).
export function standardLevelDocumentEvidenceTopics(documentEvidence) {
  return (documentEvidence?.topics ?? []).filter((topic) => topic?.esrs_code == null)
}

export function deletedDocumentIds(documentEvidence) {
  return new Set(
    (documentEvidence?.documents ?? [])
      .filter((document) => document?.deleted === true)
      .map((document) => document.id),
  )
}

export function staleDocuments(documentEvidence) {
  return (documentEvidence?.documents ?? []).filter(
    (document) => document?.stale === true && document?.deleted !== true,
  )
}

export function deletedDocuments(documentEvidence) {
  return (documentEvidence?.documents ?? []).filter((document) => document?.deleted === true)
}

// Provenance for one proposal topic, derived from the payload only:
// every proposal topic comes from the company-profile proposal (base list),
// and additionally from a document when the evidence block resolves its code.
export function topicDocumentProvenance(esrsCode, documentEvidence) {
  const entries = esrsCode ? documentEvidenceTopicsByEsrsCode(documentEvidence)[esrsCode] ?? [] : []

  return {
    profile: true,
    document: entries.length > 0,
    entries,
  }
}

export function topicHasNegativeDocumentEvidence(entries) {
  return (entries ?? []).some((topic) => topic?.kind === "negative")
}

export function evidenceNeedsDocumentReview(entries, threshold = DOCUMENT_EVIDENCE_LOW_CONFIDENCE_THRESHOLD) {
  return (entries ?? []).some((topic) =>
    (topic?.evidence ?? []).some(
      (item) => typeof item?.confidence === "number" && item.confidence < threshold,
    ),
  )
}

// Flat, render-ready evidence items for a topic's snippet area, deduped by
// (document, page, snippet) and annotated with the deleted-document state.
export function flattenTopicEvidence(entries, documentEvidence) {
  const deletedIds = deletedDocumentIds(documentEvidence)
  const seen = new Set()
  const items = []

  for (const topic of entries ?? []) {
    for (const item of topic?.evidence ?? []) {
      const key = `${item?.document_id}|${item?.page ?? ""}|${item?.snippet ?? ""}`

      if (seen.has(key)) {
        continue
      }

      seen.add(key)
      items.push({
        kind: topic?.kind === "negative" ? "negative" : "positive",
        standard: topic?.standard ?? null,
        documentId: item?.document_id ?? null,
        page: typeof item?.page === "number" ? item.page : null,
        confidence: typeof item?.confidence === "number" ? item.confidence : null,
        snippet: typeof item?.snippet === "string" ? item.snippet : "",
        documentDeleted: deletedIds.has(item?.document_id),
      })
    }
  }

  return items
}
