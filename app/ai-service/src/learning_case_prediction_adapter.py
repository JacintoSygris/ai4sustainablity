"""Offline synthetic adapter using the existing pure serving filter only."""
from learning_candidate_registry import MAPPING, RegistryError, digest
from learning_case_training import predict_raw

def predict_offline(candidate,X):
    from learning_visible_filter import filter_learning_predictions as filter_new_format_runtime_predictions
    if candidate.topic_ids != MAPPING['topic_ids'] or candidate.promotion_allowed is not False:
        raise RegistryError('adapter.synthetic_axis_required')
    labels,scores=predict_raw(candidate,X)
    rows=[]
    for case_id in X.index:
        available=[i for i,topic in enumerate(candidate.topic_ids) if candidate.label_status[topic] == 'fitted']
        keys=[MAPPING['filter_keys'][i] for i in available]
        raw=[labels.loc[case_id,candidate.topic_ids[i]] for i in available]
        probabilities=[scores.loc[case_id,candidate.topic_ids[i]] for i in available]
        thresholds={MAPPING['filter_keys'][i]:candidate.thresholds[candidate.topic_ids[i]] for i in available}
        visible,metadata=filter_new_format_runtime_predictions(keys,raw,probabilities,score_threshold=.5,
                                                               label_thresholds=thresholds,sector_guard=None,crc_floor=None)
        rows.append(dict(case_id=case_id,keys=visible,metadata=metadata))
    return dict(mode='explicit_offline_inference_only',promotion_allowed=False,mapping=MAPPING,
        mapping_digest=digest(MAPPING),features=X.to_dict('split'),topic_ids=candidate.topic_ids,
        raw_labels=labels.to_numpy().tolist(),scores=scores.to_numpy().tolist(),
        visible_keys=[r['keys'] for r in rows],visible_filter=rows)

def predict_operational(registry,receipt,X,laravel_receipt):
    # No Laravel authorization consumer exists in tranche 1c, even for non-null input.
    raise RegistryError('adapter.not_consumable:no_verified_laravel_receipt')

def predict_case_shadow(registry,receipt,X,laravel_receipt,*,trusted_local_launcher=False):
    import os
    from learning_visible_filter import filter_learning_predictions as filter_new_format_runtime_predictions
    expected=dict(package=dict(binding=registry.case_binding,receipt=receipt),
        mode='verified-private-laravel-synthetic',promotion_allowed=False)
    if (trusted_local_launcher is not True or os.environ.get('APP_ENV') != 'testing'
            or os.environ.get('I4S_BATCH_SYNTHETIC_CAPABILITY') != 'test-namespace:t07-export'
            or registry.case_binding is None or laravel_receipt != expected):
        raise RegistryError('adapter.synthetic_private_receipt_required')
    candidate=registry.load_exact(receipt)
    mapping=registry.mapping
    if candidate.topic_ids != mapping['topic_ids'] or mapping['approved_common_axis'] is not False:
        raise RegistryError('adapter.explicit_fixture_required')
    labels,scores=predict_raw(candidate,X)
    visible=[]
    for row in X.index:
        available=[i for i,t in enumerate(candidate.topic_ids) if candidate.label_status[t]=='fitted']
        keys=[mapping['filter_keys'][i] for i in available]
        selected,_=filter_new_format_runtime_predictions(keys,
            [labels.loc[row,candidate.topic_ids[i]] for i in available],
            [scores.loc[row,candidate.topic_ids[i]] for i in available],score_threshold=.5,
            label_thresholds={mapping['filter_keys'][i]:candidate.thresholds[candidate.topic_ids[i]] for i in available},
            sector_guard=None,crc_floor=None)
        visible.append(selected)
    return dict(mode='synthetic-shadow-proposal-only',promotion_allowed=False,mapping=mapping,
        mapping_digest=digest(mapping),topic_ids=candidate.topic_ids,raw_labels=labels.to_numpy().tolist(),
        scores=scores.to_numpy().tolist(),visible_keys=visible)
