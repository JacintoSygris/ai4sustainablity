"""Lazy access to the public serving package through its existing services alias.

The alias resolves to public_runtime/services in a checkout and to the bundled
services package in the image. Import and prediction failures never fall back.
"""
import importlib
import importlib.util
import os
from pathlib import Path


def prefix():
    value = os.getenv('LEARNING_SERVING_RUNTIME', 'public')
    if value != 'public':
        raise ValueError('learning.serving_runtime')
    return ''


def company_type():
    return importlib.import_module(prefix() + 'services.datatypes').CompanyData


def predictor():
    return importlib.import_module(prefix() + 'services.service_predict')


def predict_esrs(*args, **kwargs):
    return predictor().predict_esrs(*args, **kwargs)


def resolve_model_profile(*args, **kwargs):
    return predictor().resolve_model_profile(*args, **kwargs)


def model_serving_identity(*args, **kwargs):
    return predictor().model_serving_identity(*args, **kwargs)


def sources():
    prefix()
    # Resolve the alias without importing the heavyweight predictor/artifacts.
    # Keep the manifest keys identical across the native and image layouts.
    return {'services/' + name + '.py': Path(importlib.util.find_spec('services.' + name).origin).resolve()
            for name in ('service_predict', 'datatypes')}
