"""Pure optional learning filter, matching serving semantics with policies disabled.

The public serving module remains independent. Callers supply fitted heads only;
unknown learning heads remain unobserved in the prediction adapter.
"""


def filter_learning_predictions(esrs_columns, raw_predictions, scores,
                                score_threshold=.5, label_thresholds=None,
                                sector_guard=None, crc_floor=None):
    if sector_guard is not None or crc_floor is not None:
        raise ValueError('learning_filter.serving_policy_not_supported')
    raw_positive = [(key, score) for key, value, score in
                    zip(esrs_columns, [int(v) for v in raw_predictions], scores) if value == 1]
    threshold_positive = [(key, score) for key, score in raw_positive
                          if score >= (label_thresholds or {}).get(key, score_threshold)]
    candidates = [(key, score) for key, score in threshold_positive
                  if not key.endswith('_summary') and key != 'esrs_e3_other_issues_related_to_esrs_e3']
    candidates.sort(key=lambda item: (-item[1], item[0]))
    return [key for key, _ in candidates], {
        'new_format_score_threshold': score_threshold,
        'per_label_threshold_count': len(label_thresholds or {}),
        'raw_positive_key_count': len(raw_positive),
        'threshold_positive_key_count': len(threshold_positive),
        'excluded_non_candidate_key_count': len(threshold_positive) - len(candidates),
        'emitted_positive_key_count': len(candidates),
        'sector_guard_active': False,
        'sector_guard_suppressed_count': 0,
        'sector_guard_suppressed_keys': [],
        'crc_recall_floor_active': False,
        'crc_lambda': None,
        'crc_version': None,
        'crc_added_key_count': 0,
        'crc_added_keys': [],
    }
