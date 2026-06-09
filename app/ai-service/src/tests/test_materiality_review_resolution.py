import unittest

from materiality_review_resolution import promote_review_decisions_to_child_labels


class MaterialityReviewResolutionTest(unittest.TestCase):
    def test_promotes_approved_candidate_decision_to_human_approved_child_label(self):
        result = promote_review_decisions_to_child_labels(
            review_queue=[
                _queue_row(
                    candidate_keys=[
                        "esrs_s1_own_working_conditions_own_safe_employment",
                        "esrs_s1_own_working_conditions_own_working_time",
                    ]
                )
            ],
            decisions=[
                _decision(
                    approved_keys=["esrs_s1_own_working_conditions_own_safe_employment"]
                )
            ],
            mapping=_mapping(),
        )

        self.assertEqual(result.blocked, [])
        self.assertEqual(len(result.labels), 1)
        label = result.labels[0]
        self.assertEqual(label["python_esrs_key"], "esrs_s1_own_working_conditions_own_safe_employment")
        self.assertEqual(label["matched_topic_id"], 28)
        self.assertEqual(label["review_status"], "human_approved")
        self.assertEqual(label["topic_resolution_method"], "reviewer_override")
        self.assertEqual(label["topic_resolution_state"], "reviewer_resolved")
        self.assertEqual(label["source_review_row_id"], "review-1")
        self.assertEqual(len(result.outcomes), 1)
        self.assertEqual(result.outcomes[0]["emitted_label_ids"], [label["label_id"]])

    def test_blocks_approved_key_that_was_not_a_candidate_for_the_review_row(self):
        result = promote_review_decisions_to_child_labels(
            review_queue=[
                _queue_row(
                    candidate_keys=["esrs_s1_own_working_conditions_own_safe_employment"]
                )
            ],
            decisions=[
                _decision(
                    approved_keys=["esrs_s1_own_working_conditions_own_working_time"]
                )
            ],
            mapping=_mapping(),
        )

        self.assertEqual(result.labels, [])
        self.assertEqual(result.outcomes, [])
        self.assertEqual(result.blocked[0]["reason"], "approved_key_not_in_review_candidates")

    def test_parent_only_decision_generates_outcome_without_child_label(self):
        result = promote_review_decisions_to_child_labels(
            review_queue=[
                _queue_row(
                    candidate_keys=["esrs_s1_own_working_conditions_own_safe_employment"]
                )
            ],
            decisions=[
                _decision(
                    status="parent_only",
                    approved_keys=[],
                    review_notes="The report marks only the parent theme.",
                )
            ],
            mapping=_mapping(),
        )

        self.assertEqual(result.labels, [])
        self.assertEqual(result.blocked, [])
        self.assertEqual(result.outcomes[0]["decision_status"], "parent_only")


def _queue_row(*, candidate_keys: list[str]) -> dict:
    return {
        "review_row_id": "review-1",
        "review_status": "needs_review",
        "review_reason": "parent_multi_child_review_required",
        "company_name": "Acme SA",
        "source_file": "Acme.pdf",
        "report_url": "https://example.com/acme.pdf",
        "report_year": 2024,
        "pdf_sha256": "hash-a",
        "matched_term": "Working conditions",
        "candidate_python_esrs_keys": candidate_keys,
        "candidate_ar16_topic_ids": [28, 29],
        "evidence_items": [
            {
                "evidence_id": "evidence-1",
                "evidence_type": "dma_table",
                "evidence_strength": "direct",
                "page_number": 5,
                "bbox": {"x0": 1, "y0": 2, "x1": 3, "y1": 4},
                "structured_locator": "zone_id=page-5;match_term=Working conditions",
                "excerpt": "Working conditions is listed as material.",
                "scope": "group",
                "extractor_method": "deterministic",
                "source_text_trusted": False,
            }
        ],
    }


def _decision(
    *,
    status: str = "approved_child_topics",
    approved_keys: list[str],
    review_notes: str = "Reviewed against DMA table.",
) -> dict:
    return {
        "review_row_id": "review-1",
        "decision_status": status,
        "approved_python_esrs_keys": approved_keys,
        "reviewer_id": "reviewer-a",
        "reviewed_at": "2026-06-09T12:00:00+00:00",
        "review_notes": review_notes,
    }


def _mapping() -> dict:
    return {
        "keys": [
            {
                "python_esrs_key": "esrs_s1_own_working_conditions_own_safe_employment",
                "status": "approved",
                "ar16_topic_ids": [28],
            },
            {
                "python_esrs_key": "esrs_s1_own_working_conditions_own_working_time",
                "status": "approved",
                "ar16_topic_ids": [29],
            },
        ]
    }


if __name__ == "__main__":
    unittest.main()
