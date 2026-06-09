import unittest

from materiality_review_pipeline import (
    assemble_reviewed_training_inputs,
    build_machine_review_decisions_from_suggestions,
)


class MaterialityReviewPipelineTest(unittest.TestCase):
    def test_builds_machine_review_decisions_from_bounded_suggestions(self):
        decisions = build_machine_review_decisions_from_suggestions(
            suggestions=[
                _suggestion(
                    review_row_id="review-1",
                    status="unique_child_match",
                    matched_keys=["esrs_s1_secure_employment"],
                ),
                _suggestion(
                    review_row_id="review-2",
                    status="multiple_child_matches_needs_review",
                    matched_keys=["esrs_s1_secure_employment", "esrs_s1_working_time"],
                ),
                _suggestion(
                    review_row_id="review-3",
                    status="parent_only_or_needs_review",
                    matched_keys=[],
                ),
            ],
            reviewer_id="machine-scope-lock-v1",
            reviewed_at="2026-06-09T19:00:00+00:00",
            approve_multiple_exact_matches=True,
            resolve_parent_only=True,
        )

        self.assertEqual(
            [row["decision_status"] for row in decisions],
            ["approved_child_topics", "approved_child_topics", "parent_only"],
        )
        self.assertEqual(decisions[0]["approved_python_esrs_keys"], ["esrs_s1_secure_employment"])
        self.assertEqual(
            decisions[1]["approved_python_esrs_keys"],
            ["esrs_s1_secure_employment", "esrs_s1_working_time"],
        )
        self.assertEqual(decisions[2]["approved_python_esrs_keys"], [])

    def test_assemble_reviewed_training_inputs_removes_resolved_rows_and_dedupes_labels(self):
        result = assemble_reviewed_training_inputs(
            base_child_labels=[
                _label("base-a", 2024, "Acme.pdf", "https://example.com/acme.pdf", "esrs_s1_secure_employment", 28),
                _label("duplicate-a", 2024, "Acme.pdf", "https://example.com/acme.pdf", "esrs_s1_secure_employment", 28),
            ],
            resolved_child_labels=[
                _label("resolved-b", 2024, "Acme.pdf", "https://example.com/acme.pdf", "esrs_s1_working_time", 29),
            ],
            review_queue=[
                _review_row("review-1", 2024, "Acme.pdf", "https://example.com/acme.pdf"),
                _review_row("review-2", 2024, "Other.pdf", "https://example.com/other.pdf"),
            ],
            review_outcomes=[
                {"review_row_id": "review-1", "decision_status": "approved_child_topics"},
            ],
        )

        self.assertEqual(len(result.merged_child_labels), 2)
        self.assertEqual([row["review_row_id"] for row in result.residual_review_queue], ["review-2"])
        self.assertEqual(result.summary["duplicate_label_count"], 1)


def _suggestion(*, review_row_id: str, status: str, matched_keys: list[str]) -> dict:
    return {
        "review_row_id": review_row_id,
        "suggestion_status": status,
        "matched_python_esrs_keys": matched_keys,
        "confidence": 1.0,
        "rationale": "test suggestion",
    }


def _label(
    label_id: str,
    report_year: int,
    source_file: str,
    report_url: str,
    python_esrs_key: str,
    matched_topic_id: int,
) -> dict:
    return {
        "label_id": label_id,
        "report_year": report_year,
        "source_file": source_file,
        "report_url": report_url,
        "python_esrs_key": python_esrs_key,
        "matched_topic_id": matched_topic_id,
    }


def _review_row(review_row_id: str, report_year: int, source_file: str, report_url: str) -> dict:
    return {
        "review_row_id": review_row_id,
        "report_year": report_year,
        "source_file": source_file,
        "report_url": report_url,
        "review_status": "needs_review",
    }


if __name__ == "__main__":
    unittest.main()
