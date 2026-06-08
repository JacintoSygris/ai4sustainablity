import assert from "node:assert/strict"
import { readFileSync } from "node:fs"
import { dirname, join } from "node:path"
import { fileURLToPath, pathToFileURL } from "node:url"
import test from "node:test"

const root = dirname(dirname(fileURLToPath(import.meta.url)))
const {
  actionsFromProposal,
  buildReviewPayload,
  reasonsFromProposal,
} = await import(pathToFileURL(join(root, "lib/materiality-proposal-review.mjs")).href)

function read(relativePath) {
  return readFileSync(join(root, relativePath), "utf8")
}

test("wizard Step 2 is a Laravel P6 page, not a local Better Auth/Turso page", () => {
  const source = read("app/(dashboard)/wizard/step-2/page.tsx")

  assert.doesNotMatch(source, /@\/lib\/auth/, "Step 2 must not read Better Auth directly")
  assert.doesNotMatch(source, /@\/lib\/queries/, "Step 2 must not read active reports from Turso")
  assert.doesNotMatch(source, /getActiveReport|activeReport|reportId/, "Step 2 must not depend on local report IDs")
  assert.match(source, /WizardSidebar/, "Step 2 must keep the imported wizard shell")
  assert.match(source, /MaterialTopicsForm/, "Step 2 must delegate P6 review to the form component")
})

test("material topics form reviews Laravel P6 proposals instead of local ESG topic state", () => {
  const source = read("components/wizard/material-topics-form.tsx")
  const helperSource = read("lib/materiality-proposal-review.mjs")
  const implementationSource = `${source}\n${helperSource}`

  assert.match(
    source,
    /getLaravelMaterialityProposal|updateLaravelMaterialityProposalReview/,
    "P6 form must use Laravel materiality proposal helpers",
  )
  assert.match(source, /submitLaravelCharacterization/, "P6 form must be able to trigger Laravel characterization submit")
  assert.match(implementationSource, /topic_actions/, "P6 form must persist explicit review actions")
  assert.match(source, /review_required_prediction_keys/, "P6 form must surface manual-review AI prediction keys")
  assert.match(source, /buildReviewPayload/, "P6 form must build a tested explicit-review payload")
  assert.doesNotMatch(source, /@\/lib\/esg-topics-data/, "P6 form must not use imported local ESG topic taxonomy")
  assert.doesNotMatch(source, /fetch\(["']\/api\/wizard\/step-2/, "P6 form must not post to local Next Step 2")
  assert.doesNotMatch(source, /fetch\(["']\/api\/wizard\/reset-step/, "P6 form must not reset local wizard state")
  assert.doesNotMatch(source, /reportId/, "P6 form must not accept or send local report IDs")
})

test("Laravel API client exposes typed P6 materiality proposal helpers", () => {
  const source = read("lib/laravel-api.ts")

  assert.match(source, /LaravelMaterialityProposal/, "client must type materiality proposal resources")
  assert.match(source, /getLaravelMaterialityProposal/, "client must expose proposal read helper")
  assert.match(source, /updateLaravelMaterialityProposalReview/, "client must expose proposal review helper")
  assert.match(source, /submitLaravelCharacterization/, "client must expose characterization submit helper")
  assert.match(source, /\/materiality-proposal/, "client must call Laravel materiality proposal API")
  assert.match(source, /\/characterization\/submit/, "client must call Laravel characterization submit API")
})

test("P6 review helpers do not turn a fresh proposal into implicit accept-all", () => {
  const proposal = {
    proposal_topic_ids: [101, 102],
    review: {
      topic_actions: {},
      action_reasons: {},
      action_notes: {},
    },
  }

  assert.deepEqual(actionsFromProposal(proposal), {}, "fresh proposal actions must stay empty until user action")

  const result = buildReviewPayload({
    proposalTopicIds: proposal.proposal_topic_ids,
    topicActions: actionsFromProposal(proposal),
    actionReasons: {},
    actionNotes: {},
  })

  assert.equal(result.ok, false)
  assert.deepEqual(result.missingTopicIds, ["101", "102"])
  assert.equal("payload" in result, false, "untouched fresh proposal must not produce a PUT payload")
})

test("P6 review helpers persist only explicit actions, selected reason chips, and cleaned notes", () => {
  const result = buildReviewPayload({
    proposalTopicIds: [101, 102],
    topicActions: {
      "101": "accepted",
      "102": "unsure",
      "999": "rejected",
    },
    actionReasons: {
      "101": ["sector_fit"],
      "102": ["needs_adm", "because"],
      "999": ["other"],
    },
    actionNotes: {
      "101": " Keep in P6. ",
      "102": "   ",
      "999": "Stale note.",
    },
  })

  assert.deepEqual(result, {
    ok: true,
    payload: {
      topic_actions: {
        "101": "accepted",
        "102": "unsure",
      },
      action_reasons: {
        "101": ["sector_fit"],
        "102": ["needs_adm"],
      },
      action_notes: {
        "101": "Keep in P6.",
      },
    },
  })
})

test("P6 review helpers load stored reason chips only for current proposal topics", () => {
  const proposal = {
    proposal_topic_ids: [101],
    review: {
      topic_actions: {
        "101": "accepted",
      },
      action_reasons: {
        "101": ["sector_fit"],
        "102": ["not_relevant"],
      },
      action_notes: {},
    },
  }

  assert.deepEqual(reasonsFromProposal(proposal), {
    "101": ["sector_fit"],
  })
})
