import unittest
from types import SimpleNamespace

from materiality_schema import (
    ALLOWED_AR16_MAPPING_STATUSES,
    BLOCKING_TOPIC_RESOLUTION_STATES,
    BLOCKER_CODES,
    EVIDENCE_TYPES,
    FORBIDDEN_TOPIC_RESOLUTION_VALUES,
    PRIMARY_STATUSES,
    VALID_TOPIC_RESOLUTION_PAIRS,
    MaterialitySchemaError,
    get_cross_surface_table,
    has_open_export_blocker,
    topic_resolution_allows_export_checks,
    validate_ar16_mapping_status,
    validate_blocker_fields,
    validate_evidence_items,
    validate_materiality_label_schema,
    validate_primary_status,
    validate_topic_resolution,
)


class MaterialitySchemaTest(unittest.TestCase):
    def test_rejects_all_forbidden_values_for_topic_resolution_fields(self):
        for forbidden_value in FORBIDDEN_TOPIC_RESOLUTION_VALUES:
            with self.subTest(field="topic_resolution_method", value=forbidden_value):
                with self.assertRaises(MaterialitySchemaError):
                    validate_topic_resolution(
                        forbidden_value,
                        "resolved_to_registry_topic",
                    )

            with self.subTest(field="topic_resolution_state", value=forbidden_value):
                with self.assertRaises(MaterialitySchemaError):
                    validate_topic_resolution(
                        "registry_exact_match",
                        forbidden_value,
                    )

    def test_only_closed_method_state_pairs_validate(self):
        for method, state in VALID_TOPIC_RESOLUTION_PAIRS:
            with self.subTest(method=method, state=state):
                validate_topic_resolution(method, state)

        invalid_pairs = [
            ("unresolved", "resolved_to_registry_topic"),
            ("registry_exact_match", "machine_candidate_needs_review"),
            ("machine_synonym_candidate", "resolved_to_registry_topic"),
            ("non_topic_aggregate_candidate", "resolved_to_registry_topic"),
            ("reviewer_override", "machine_candidate_needs_review"),
        ]
        for method, state in invalid_pairs:
            with self.subTest(method=method, state=state):
                with self.assertRaises(MaterialitySchemaError):
                    validate_topic_resolution(method, state)

    def test_rejects_every_unlisted_method_state_pair(self):
        methods = {method for method, _state in VALID_TOPIC_RESOLUTION_PAIRS}
        states = {state for _method, state in VALID_TOPIC_RESOLUTION_PAIRS}

        for method in methods:
            for state in states:
                if (method, state) in VALID_TOPIC_RESOLUTION_PAIRS:
                    continue
                with self.subTest(method=method, state=state):
                    with self.assertRaises(MaterialitySchemaError):
                        validate_topic_resolution(method, state)

    def test_rejects_stale_mapping_review_status_field(self):
        label = {
            "matched_topic_id": 1,
            "topic_resolution_method": "registry_exact_match",
            "topic_resolution_state": "resolved_to_registry_topic",
            "mapping_review_status": "approved",
        }

        with self.assertRaises(MaterialitySchemaError):
            validate_materiality_label_schema(label)

    def test_ar16_mapping_status_uses_repository_vocabulary_only(self):
        for status in ALLOWED_AR16_MAPPING_STATUSES:
            with self.subTest(status=status):
                validate_ar16_mapping_status(status)

        for status in ("direct", "equivalent", "review_required", "ignored", ""):
            with self.subTest(status=status):
                with self.assertRaises(MaterialitySchemaError):
                    validate_ar16_mapping_status(status)

    def test_topic_resolution_only_proceeds_to_export_checks_with_approved_registry_entry(self):
        label = {
            "matched_topic_id": 10,
            "topic_resolution_method": "registry_exact_match",
            "topic_resolution_state": "resolved_to_registry_topic",
        }

        self.assertTrue(
            topic_resolution_allows_export_checks(
                label,
                _registry_entry(10, "approved"),
            )
        )

        for status in ALLOWED_AR16_MAPPING_STATUSES - {"approved"}:
            with self.subTest(status=status):
                self.assertFalse(
                    topic_resolution_allows_export_checks(
                        label,
                        _registry_entry(10, status),
                    )
                )

    def test_blocking_topic_resolution_states_never_proceed_to_export_checks(self):
        for state in BLOCKING_TOPIC_RESOLUTION_STATES:
            label = {
                "matched_topic_id": 10,
                "topic_resolution_method": _method_for_blocking_state(state),
                "topic_resolution_state": state,
            }
            with self.subTest(state=state):
                self.assertFalse(
                    topic_resolution_allows_export_checks(
                        label,
                        _registry_entry(10, "approved"),
                    )
                )

    def test_reviewer_resolved_requires_reviewer_metadata(self):
        label = {
            "matched_topic_id": 10,
            "topic_resolution_method": "reviewer_override",
            "topic_resolution_state": "reviewer_resolved",
        }

        with self.assertRaises(MaterialitySchemaError):
            topic_resolution_allows_export_checks(label, _registry_entry(10, "approved"))

        label["reviewer_id"] = "reviewer-a"
        label["reviewed_at"] = "2026-06-09T12:00:00+02:00"

        self.assertTrue(
            topic_resolution_allows_export_checks(
                label,
                _registry_entry(10, "approved"),
            )
        )

    def test_cross_surface_table_has_single_final_export_authorization_surface(self):
        table = get_cross_surface_table()
        by_field = {row["field"]: row for row in table}

        self.assertEqual(
            set(by_field),
            {
                "topic_resolution_method",
                "topic_resolution_state",
                "Ar16RegistryEntry.mapping_status",
                "Ar16KeyEquivalence.status",
                "review_status",
            },
        )
        authorizing_fields = [
            row["field"] for row in table if row["participates_in_final_export_authorization"]
        ]
        self.assertEqual(authorizing_fields, ["Ar16RegistryEntry.mapping_status"])
        self.assertTrue(by_field["topic_resolution_state"]["can_block_export"])
        self.assertTrue(by_field["review_status"]["can_block_export"])

    def test_primary_status_taxonomy_rejects_extraction_blocker_codes(self):
        for status in PRIMARY_STATUSES:
            with self.subTest(status=status):
                validate_primary_status(status)

        for blocker_code in BLOCKER_CODES:
            with self.subTest(blocker_code=blocker_code):
                with self.assertRaises(MaterialitySchemaError):
                    validate_primary_status(blocker_code)

    def test_blocker_fields_validate_allowed_codes_and_statuses(self):
        label = {
            "report_blockers": ["low_text_yield"],
            "page_blockers": ["matrix_image_unreadable"],
            "topic_blockers": ["prompt_injection_detected"],
            "blocker_status": "open",
        }
        validate_blocker_fields(label)
        self.assertTrue(has_open_export_blocker(label))

        invalid_code_label = dict(label)
        invalid_code_label["topic_blockers"] = ["unknown_blocker"]
        with self.assertRaises(MaterialitySchemaError):
            validate_blocker_fields(invalid_code_label)

        invalid_status_label = dict(label)
        invalid_status_label["blocker_status"] = "closed"
        with self.assertRaises(MaterialitySchemaError):
            validate_blocker_fields(invalid_status_label)

        resolved_label = dict(label)
        resolved_label["blocker_status"] = "resolved"
        self.assertFalse(has_open_export_blocker(resolved_label))

    def test_accepted_exception_blockers_require_governed_reviewer_metadata(self):
        label = {
            "report_blockers": ["low_text_yield"],
            "page_blockers": [],
            "topic_blockers": [],
            "blocker_status": "accepted_exception",
            "blocker_resolution_notes": "Low yield accepted after manual review.",
        }

        with self.assertRaises(MaterialitySchemaError):
            validate_blocker_fields(label)

        label["blocker_exception_reason"] = "source_limitation_accepted"
        with self.assertRaises(MaterialitySchemaError):
            validate_blocker_fields(label)

        label["reviewer_id"] = "reviewer-a"
        label["reviewed_at"] = "2026-06-09T12:00:00+02:00"
        validate_blocker_fields(label)

        prompt_injection_exception = dict(label)
        prompt_injection_exception["report_blockers"] = []
        prompt_injection_exception["topic_blockers"] = ["prompt_injection_detected"]
        with self.assertRaises(MaterialitySchemaError):
            validate_blocker_fields(prompt_injection_exception)

    def test_evidence_items_require_closed_types_and_promotable_fields(self):
        label = {"evidence_items": [_valid_evidence_item()]}
        validate_evidence_items(label)

        for evidence_type in EVIDENCE_TYPES:
            with self.subTest(evidence_type=evidence_type):
                item = _valid_evidence_item(evidence_type=evidence_type)
                validate_evidence_items({"evidence_items": [item]})

        unknown_type = _valid_evidence_item(evidence_type="unknown")
        with self.assertRaises(MaterialitySchemaError):
            validate_evidence_items({"evidence_items": [unknown_type]})

        missing_page = _valid_evidence_item()
        del missing_page["page_number"]
        with self.assertRaises(MaterialitySchemaError):
            validate_evidence_items({"evidence_items": [missing_page]})

        missing_locator = _valid_evidence_item()
        missing_locator["bbox"] = None
        missing_locator["structured_locator"] = ""
        with self.assertRaises(MaterialitySchemaError):
            validate_evidence_items({"evidence_items": [missing_locator]})

        trusted_source_text = _valid_evidence_item()
        trusted_source_text["source_text_trusted"] = True
        with self.assertRaises(MaterialitySchemaError):
            validate_evidence_items({"evidence_items": [trusted_source_text]})

    def test_evidence_items_reject_invalid_promotable_values(self):
        missing_id = _valid_evidence_item()
        del missing_id["evidence_id"]
        with self.assertRaises(MaterialitySchemaError):
            validate_evidence_items({"evidence_items": [missing_id]})

        invalid_strength = _valid_evidence_item()
        invalid_strength["evidence_strength"] = "medium"
        with self.assertRaises(MaterialitySchemaError):
            validate_evidence_items({"evidence_items": [invalid_strength]})

        invalid_scope = _valid_evidence_item()
        invalid_scope["scope"] = "global-ish"
        with self.assertRaises(MaterialitySchemaError):
            validate_evidence_items({"evidence_items": [invalid_scope]})

        invalid_page_number = _valid_evidence_item()
        invalid_page_number["page_number"] = 0
        with self.assertRaises(MaterialitySchemaError):
            validate_evidence_items({"evidence_items": [invalid_page_number]})

        non_integer_page_number = _valid_evidence_item()
        non_integer_page_number["page_number"] = "12"
        with self.assertRaises(MaterialitySchemaError):
            validate_evidence_items({"evidence_items": [non_integer_page_number]})

        invalid_bbox = _valid_evidence_item()
        invalid_bbox["bbox"] = [0, 1, 2]
        with self.assertRaises(MaterialitySchemaError):
            validate_evidence_items({"evidence_items": [invalid_bbox]})

        invalid_bbox_object = _valid_evidence_item()
        invalid_bbox_object["bbox"] = {"x0": 0, "y0": 1, "x1": 2}
        with self.assertRaises(MaterialitySchemaError):
            validate_evidence_items({"evidence_items": [invalid_bbox_object]})

    def test_materiality_label_schema_validates_status_blockers_and_evidence(self):
        label = {
            "matched_topic_id": 1,
            "primary_status": "explicit_material",
            "topic_resolution_method": "registry_exact_match",
            "topic_resolution_state": "resolved_to_registry_topic",
            "blocker_status": "not_applicable",
            "evidence_items": [_valid_evidence_item()],
        }

        validate_materiality_label_schema(label)

        invalid_label = dict(label)
        invalid_label["primary_status"] = "prompt_injection_detected"
        with self.assertRaises(MaterialitySchemaError):
            validate_materiality_label_schema(invalid_label)


def _registry_entry(ar16_topic_id: int, mapping_status: str):
    return SimpleNamespace(ar16_topic_id=ar16_topic_id, mapping_status=mapping_status)


def _method_for_blocking_state(state: str) -> str:
    return {
        "machine_candidate_needs_review": "machine_synonym_candidate",
        "ambiguous_needs_review": "machine_synonym_candidate",
        "unresolved_blocked": "unresolved",
        "excluded_non_topic": "non_topic_aggregate_candidate",
    }[state]


def _valid_evidence_item(evidence_type: str = "materiality_table") -> dict:
    return {
        "evidence_id": "evidence-1",
        "evidence_type": evidence_type,
        "evidence_strength": "direct",
        "page_number": 12,
        "bbox": [0, 1, 2, 3],
        "structured_locator": "",
        "excerpt": "The topic is identified as material in the DMA table.",
        "scope": "group",
        "extractor_method": "deterministic",
        "source_text_trusted": False,
    }
