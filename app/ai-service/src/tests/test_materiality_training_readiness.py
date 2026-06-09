import unittest

from materiality_training_readiness import assess_child_training_readiness


class MaterialityTrainingReadinessTest(unittest.TestCase):
    def test_blocks_child_training_when_same_report_has_unresolved_parent_review(self):
        result = assess_child_training_readiness(
            child_labels=[
                _child_label(
                    report_year=2024,
                    source_file="Acme.pdf",
                    report_url="https://example.com/acme.pdf",
                    python_esrs_key="esrs_e5_waste_management",
                )
            ],
            review_queue=[
                _review_row(
                    report_year=2024,
                    source_file="Acme.pdf",
                    report_url="https://example.com/acme.pdf",
                )
            ],
            min_training_reports=1,
            min_child_keys=1,
        )

        self.assertFalse(result["ready"])
        self.assertEqual(result["blocked_child_report_count"], 1)
        self.assertEqual(
            result["blockers"][0]["reason"],
            "unresolved_parent_or_ambiguous_materiality_for_report",
        )

    def test_allows_child_training_when_report_has_no_unresolved_parent_review(self):
        result = assess_child_training_readiness(
            child_labels=[
                _child_label(
                    report_year=2024,
                    source_file="Acme.pdf",
                    report_url="https://example.com/acme.pdf",
                    python_esrs_key="esrs_e5_waste_management",
                )
            ],
            review_queue=[
                _review_row(
                    report_year=2024,
                    source_file="Other.pdf",
                    report_url="https://example.com/other.pdf",
                )
            ],
            min_training_reports=1,
            min_child_keys=1,
        )

        self.assertTrue(result["ready"])
        self.assertEqual(result["trainable_report_count"], 1)
        self.assertEqual(result["trainable_child_key_count"], 1)


def _child_label(
    *,
    report_year: int,
    source_file: str,
    report_url: str,
    python_esrs_key: str,
) -> dict:
    return {
        "report_year": report_year,
        "source_file": source_file,
        "report_url": report_url,
        "python_esrs_key": python_esrs_key,
    }


def _review_row(*, report_year: int, source_file: str, report_url: str) -> dict:
    return {
        "report_year": report_year,
        "source_file": source_file,
        "report_url": report_url,
        "review_status": "needs_review",
    }


if __name__ == "__main__":
    unittest.main()
