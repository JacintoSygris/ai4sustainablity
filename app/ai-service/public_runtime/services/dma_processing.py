"""Local advisory retrieval, fitted only on the allowlisted catalogue. No pickle or user training."""
from __future__ import annotations
import hashlib
import json
import multiprocessing as mp
import os
import re
import time
from pathlib import Path

import numpy as np
from sklearn.feature_extraction.text import TfidfVectorizer

VERSION = 'dma-processing-v1'
ROOT = Path(__file__).resolve().parents[3]
SOURCES = ('esrs_topics.json', 'esrs_datapoints_ig3.json', 'esrs_datapoint_labels_es_v1.json')
LEVELS = ('topic', 'dr', 'datapoint')
# Conservative whole-description guard: mixed affirmative/negative claims also abstain.
NEGATION = re.compile(
    r"\b(no|not|never|without|neither|ning[uú]n\w*|sin|nunca|tampoco|cannot|"
    r"\w+n['’]t|cero|zero|ausencia|absence|absent|inexistente\w*|"
    r"libres?\s+de|free\s+of|lack\w*|carec\w*)\b|"
    r"\b0(?:[.,]0+)?\s+(?:(?:kg|t|tonnes?|toneladas?|kilograms?)\s+(?:de|of)\s+)?"
    r"(?:residuos|waste|radioactive|hazardous|emisiones|emissions)\b|"
    # A standalone numeric zero anywhere in a claim is conservative evidence of
    # absence, including after the object. Do not match 0.5, 10 or ISO/ID fragments.
    r"(?<![\w.,])0(?:[.,]0+)?(?![\w]|[.,]\d)", re.I)
AMBIGUITY = re.compile(r'\b(quiz[aá]s|tal vez|inciert\w*|uncertain|maybe|either|o bien|perhaps|'
    r'pendiente(?:s)?\s+(?:de\s+)?(?:determinar|confirmar|verificar|evaluar)|'
    r'(?:pending|awaiting)\s+(?:determination|confirmation|assessment|verification)|'
    r'(?:yet|still)\s+to\s+be\s+(?:determined|confirmed|assessed)|to\s+be\s+determined)\b|[¿?]' , re.I)
STOP = frozenset('de del la el los las en y o un una por para con que se su sus al a es son como our the of in and or to for is are we it with on at from total amount cantidad consumo consumption productos products'.split())


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def canonical(value) -> bytes:
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(',', ':'), allow_nan=False).encode()


def catalogues(root=ROOT, data=None):
    data = data if data is not None else root / 'web/data'
    topics, ig3, labels = [json.loads((data / name).read_text()) for name in SOURCES]
    docs = {level: [] for level in LEVELS}
    for i, row in enumerate(topics, 1):
        names = {lang: ' — '.join(row[key][lang] for key in ('theme', 'subtheme', 'subtopic') if row.get(key)) for lang in ('es', 'en')}
        docs['topic'].append({'id': str(i), 'labels': names, 'text': {lang: names[lang] + '. ' + ' '.join(row['examples'][lang]) for lang in names},
                              'reference': f'esrs_topics.json#/{i-1}', 'esrs': row['esrs']})
    for key, title in sorted(labels['disclosure_requirements'].items()):
        docs['dr'].append({'id': key, 'labels': title, 'text': title, 'reference': 'esrs_datapoint_labels_es_v1.json#/disclosure_requirements/' + key})
    for row in ig3['datapoints']:
        names = {'en': row['name'], 'es': labels['labels'][row['id']]}
        docs['datapoint'].append({'id': row['id'], 'dr': row['dr'], 'esrs': row['esrs'], 'labels': names, 'text': names,
                                  'reference': 'esrs_datapoints_ig3.json#/datapoints/' + row['id']})
    for items in docs.values():
        items.sort(key=lambda item: item['id'])
    return docs


def vectorizer(kind, vocabulary=None):
    return TfidfVectorizer(analyzer='word' if kind == 'word' else 'char_wb',
                           ngram_range=(1, 2) if kind == 'word' else (3, 5),
                           lowercase=True, sublinear_tf=False, norm='l2', vocabulary=vocabulary)


def load_model(directory=None, root=None):
    from .dma_processing_version import MANIFEST_SHA256
    if directory is None:
        configured = os.getenv('I4S_DMA_NLP_MODEL_DIR', '')
        if not configured or not Path(configured).is_absolute():
            raise ValueError('model_unconfigured')
        directory = Path(configured)
    directory = Path(directory)
    data = root / 'web/data' if root is not None else directory / 'catalogues'
    raw = (directory / 'manifest.json').read_bytes()
    if digest(raw) != MANIFEST_SHA256:
        raise ValueError('model_manifest_mismatch')
    manifest = json.loads(raw)
    if manifest['version'] != VERSION:
        raise ValueError('model_version_mismatch')
    if set(manifest['sources']) != set(SOURCES):
        raise ValueError('catalogue_sources_mismatch')
    for name, expected in manifest['sources'].items():
        if digest((data / name).read_bytes()) != expected:
            raise ValueError('catalogue_mismatch')
    raw_model = (directory / 'model.json').read_bytes()
    if digest(raw_model) != manifest['model_sha256']:
        raise ValueError('model_mismatch')
    params = json.loads(raw_model)
    docs = catalogues(data=data)
    if digest(canonical(docs)) != manifest['catalogue_sha256']:
        raise ValueError('catalogue_identity_mismatch')
    matrices = {}
    for level in LEVELS:
        for lang in ('es', 'en'):
            texts = [item['text'][lang] for item in docs[level]]
            for kind in ('word', 'char'):
                spec = params['vectors'][level][lang][kind]
                vec = vectorizer(kind, spec['vocabulary'])
                vec.idf_ = np.asarray(spec['idf'], dtype=float)
                matrices[level, lang, kind] = (vec, vec.transform(texts))
    return {'manifest': manifest, 'params': params, 'docs': docs, 'matrices': matrices}


def supporting_spans(description, document, lang):
    # Evidence is a verbatim sentence sharing a content token with the specific catalogue row.
    terms = {word.casefold() for word in re.findall(r'\w+', document['text'][lang]) if len(word) > 2} - STOP
    spans = []
    for match in re.finditer(r'[^.!?;\n]+[.!?;]?', description):
        quote = match.group()
        if terms & {word.casefold() for word in re.findall(r'\w+', quote)}:
            start, end = match.span()
            spans.append({'start': start, 'end': end, 'quote': quote, 'catalogue_ref': document['reference']})
            break
    return spans


def process(request: dict, model=None, word_only=False):
    from .dma_processing_schema import DmaProcessingRequest, DmaProcessingResponse
    request = DmaProcessingRequest.model_validate(request).model_dump()
    model = model or load_model()
    manifest = model['manifest']
    rows = []
    for iro in request['iros']:
        description = iro['description']; lang = request['locale']
        result = {'iro_id': iro['iro_id'], 'description_sha256': digest(description.encode()),
                  'model_version': VERSION, 'model_sha256': manifest['model_sha256'],
                  'catalogue_version': manifest['catalogue_version'], 'catalogue_sha256': manifest['catalogue_sha256'], 'levels': {}}
        for level in LEVELS:
            abstention = 'insufficient_evidence' if NEGATION.search(description) else ('ambiguous' if AMBIGUITY.search(description) else None)
            scores = np.zeros(len(model['docs'][level]))
            for kind in (('word',) if word_only else ('word', 'char')):
                vec, matrix = model['matrices'][level, lang, kind]
                scores += (matrix @ vec.transform([description]).T).toarray().ravel() / (1 if word_only else 2)
            ordered = sorted(range(len(scores)), key=lambda idx: (-scores[idx], model['docs'][level][idx]['id']))
            threshold = model['params']['thresholds'][level]
            if scores[ordered[0]] < threshold['minimum']:
                abstention = abstention or 'insufficient_evidence'
            elif len(ordered) > 3 and scores[ordered[0]] - scores[ordered[3]] < threshold['margin']:
                abstention = abstention or 'ambiguous'
            candidates = []
            if not abstention:
                for idx in ordered[:3]:
                    score = float(scores[idx]); doc = model['docs'][level][idx]
                    if score < max(threshold['minimum'], scores[ordered[0]] * threshold['relative']):
                        continue
                    spans = supporting_spans(description, doc, lang)
                    if not spans:
                        continue
                    candidates.append({'id': doc['id'], 'label': doc['labels'][lang],
                                       'score': round(min(1., max(0., score)), 8), 'spans': spans})
            result['levels'][level] = {'candidates': candidates, 'abstention': None if candidates else (abstention or 'insufficient_evidence')}
        rows.append(result)
    response = {'version': VERSION, 'model_sha256': manifest['model_sha256'],
                'catalogue_version': manifest['catalogue_version'], 'catalogue_sha256': manifest['catalogue_sha256'], 'iros': rows}
    DmaProcessingResponse.model_validate(response)
    for source, row in zip(request['iros'], rows):
        for level in row['levels'].values():
            for candidate in level['candidates']:
                for span in candidate['spans']:
                    if source['description'][span['start']:span['end']] != span['quote']:
                        raise ValueError('invalid_span')
    return response


def _worker(connection, request):
    try:
        result = process(request)
        if len(canonical(result)) > 262144:
            raise ValueError('result_too_large')
        connection.send(('ok', result))
    except Exception:
        connection.send(('error', None))
    finally:
        connection.close()


def process_isolated(request, deadline=10.0, worker=_worker):
    """Spawn avoids inherited locks; always terminate/kill AND reap the child on every exit."""
    context = mp.get_context('spawn')
    parent, child = context.Pipe(duplex=False)
    process_child = context.Process(target=worker, args=(child, request), daemon=True)
    start = time.monotonic()
    try:
        process_child.start(); child.close()
        if not parent.poll(max(0, deadline - (time.monotonic() - start))):
            raise TimeoutError('dma_processing_timeout')
        status, result = parent.recv()
        if status != 'ok':
            raise RuntimeError('dma_processing_unavailable')
        return result
    finally:
        parent.close(); child.close()
        if process_child.pid is not None:
            if process_child.is_alive():
                process_child.terminate()
            process_child.join(.2)
            if process_child.is_alive():
                process_child.kill(); process_child.join()
            process_child.close()
