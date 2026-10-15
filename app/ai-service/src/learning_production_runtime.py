"""Fail-closed supervised-learning serving/shadow adapter; no training or promotion endpoint."""
import logging
import os

from learning_production_registry import BASE_PROFILE, ProductionCandidateRegistry, digest, effective_active

log = logging.getLogger(__name__)


from learning_activation import flag as activation_flag


def flag(name):
    return activation_flag(name, False if name == 'LEARNING_ENGINE_SHADOW_ENABLED' else None)


def apply_learning(company, baseline):
    if not flag('LEARNING_ENGINE_ENABLED') or baseline.model_profile != BASE_PROFILE:
        return baseline
    if not flag('LEARNING_ENGINE_SERVING_ENABLED') and not flag('LEARNING_ENGINE_SHADOW_ENABLED'):
        return baseline
    served = baseline
    try:
        registry = ProductionCandidateRegistry(os.environ['LEARNING_ENGINE_ROOT'])
        identity = baseline.mapping_metadata['serving_identity']['serving_identity_sha256']
        if flag('LEARNING_ENGINE_SERVING_ENABLED'):
            active = registry.pointer('active')
            resolved = effective_active(registry, active, identity)
            if active and resolved is None:
                log.warning('learning.registry_unavailable_using_base')
            if resolved is not None:
                manifest, model = resolved
                served = baseline.model_copy(deep=True)
                served.esrs = model.predict(company, baseline.esrs)
                served.model_profile = 'learning:' + active
                # Base threshold/CRC counts describe the base, not the overlay.
                served.mapping_metadata = {
                    'mapping_status': 'external_laravel_mapping',
                    'runtime_activation': 'explicit_promoted_learning_candidate',
                    'learning_base_metadata': baseline.mapping_metadata,
                    'emitted_positive_key_count': sum(served.esrs.values()),
                }
                if 'feedback_bandit' in baseline.mapping_metadata:
                    # Preserve the RF basis; final proposed labels still come from
                    # the composed output shown by Laravel, never shadow actions.
                    served.mapping_metadata['feedback_bandit'] = baseline.mapping_metadata['feedback_bandit']
                served.mapping_metadata['learning_candidate'] = active
                served.mapping_metadata['learning_base_identity'] = identity
                served.mapping_metadata['serving_identity'] = {
                    'profile': served.model_profile,
                    'artifact_sha256': {'candidate': manifest['model_sha256']},
                    'policy_sha256': {'gate': digest(manifest['gate'])},
                    'runtime_config': {'threshold': .5, 'fallback_profile': BASE_PROFILE},
                    'serving_identity_sha256': digest([identity, manifest['model_sha256'], manifest['gate']]),
                }
        if flag('LEARNING_ENGINE_SHADOW_ENABLED') and company.learning_shadow_authorized:
            try:
                candidate = registry.pointer('shadow')
                if candidate:
                    manifest, model = registry.load(candidate)
                    if manifest['baseline_identity'] != identity:
                        raise ValueError('learning.shadow_binding')
                    registry.shadow(candidate, served.esrs, model.predict(company, baseline.esrs),
                                    company.learning_shadow_subject)
            except Exception:
                log.warning('learning.shadow_unavailable')
        return served
    except Exception:
        # No values, paths, subject identifiers or exception text in logs.
        log.warning('learning.registry_unavailable_using_base')
        return baseline
