#!/usr/bin/env python3
"""Reproducible offline WP2.2 analysis; never loads or trains the serving model."""
import argparse
import csv
from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path
import sys

import numpy as np
import scipy
from scipy.sparse import hstack
import sklearn
from sklearn.cluster import AgglomerativeClustering, KMeans
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.metrics import silhouette_score
from sklearn.preprocessing import OneHotEncoder, normalize

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'src'))
from concept_relations import association_metrics, cluster_purity

APP = Path(__file__).resolve().parents[3]


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def relations(matrix, ids, minimum_count, minimum_support, minimum_phi, minimum_lift, minimum_confidence):
    edges = []
    for i, left in enumerate(ids):
        for j in range(i+1, len(ids)):
            metric = association_metrics(matrix[:, i].tolist(), matrix[:, j].tolist())
            if (metric['joint_count'] < minimum_count or metric['support'] < minimum_support
                    or metric['phi'] is None or metric['phi'] < minimum_phi
                    or metric['lift'] is None or metric['lift'] < minimum_lift):
                continue
            for source, target, confidence in ((left, ids[j], metric['confidence']),
                                               (ids[j], left, metric['reverse_confidence'])):
                if confidence is not None and confidence >= minimum_confidence:
                    edges.append({'source': source, 'target': target,
                                  **{k: v for k, v in metric.items() if k != 'reverse_confidence'},
                                  'confidence': confidence})
    return sorted(edges, key=lambda e: (str(e['source']), -e['lift'], -e['confidence'], str(e['target'])))


def build(args):
    with args.labels.open(newline='', encoding='utf-8-sig') as file:
        reader = csv.DictReader(file, delimiter=';')
        headers = reader.fieldnames
        keys = [k for k in headers if k.startswith('esrs_')]
        rows = list(reader)
    if not rows or len(keys) != 102 or any(row[k] not in ('0', '1') for row in rows for k in keys):
        raise ValueError('Expected deployed-profile binary 102-key silver-label CSV')
    matrix = np.array([[int(row[k]) for k in keys] for row in rows], dtype=np.int64)
    mapping = json.loads(args.mapping.read_text())
    columns = {}
    excluded = []
    for item in mapping['keys']:
        key = item['python_esrs_key']
        if key not in keys:
            raise ValueError('Mapping key absent in source labels: '+key)
        if item['status'] != 'approved':
            excluded.append(key)
            continue
        for topic in item['ar16_topic_ids']:
            columns.setdefault(topic, []).append(keys.index(key))
    topic_ids = sorted(columns)
    topic_matrix = np.column_stack([matrix[:, columns[t]].max(axis=1) for t in topic_ids])
    thresholds = {'minimum_count': 30, 'minimum_support': .05, 'minimum_phi': .2,
                  'minimum_lift': 1.1, 'minimum_confidence': .6}
    leaf_indices = [i for i, key in enumerate(keys) if not key.endswith('_summary')]
    raw_edges = relations(matrix[:, leaf_indices], [keys[i] for i in leaf_indices], **thresholds)
    topic_edges = relations(topic_matrix, topic_ids, **thresholds)
    datapoints = json.loads(args.datapoints.read_text())['datapoints']
    ids = [dp['id'] for dp in datapoints]
    if len(ids) != len(set(ids)):
        raise ValueError('Duplicate datapoint IDs')
    descriptions = sum(bool(dp.get('description')) for dp in datapoints)
    text = [dp['name']+' '+(dp.get('description') or '') for dp in datapoints]
    tfidf = TfidfVectorizer(stop_words='english', ngram_range=(1, 2), min_df=2, sublinear_tf=True)
    words = tfidf.fit_transform(text)
    dr = OneHotEncoder().fit_transform([[dp['dr']] for dp in datapoints])
    standard = OneHotEncoder().fit_transform([[dp['esrs']] for dp in datapoints])
    features = normalize(hstack([words, .5*dr, .5*standard]).tocsr())
    dense = features.toarray()
    candidates = []
    best = None
    for method in ('kmeans', 'agglomerative'):
        for k in args.k:
            if not 2 <= k < len(ids):
                raise ValueError('k must be between 2 and datapoint count - 1')
            model = (KMeans(n_clusters=k, random_state=42, n_init=10)
                     if method == 'kmeans' else
                     AgglomerativeClustering(n_clusters=k, metric='cosine', linkage='average'))
            labels = model.fit_predict(features if method == 'kmeans' else dense)
            unique_count = len(set(labels))
            score = float(silhouette_score(features, labels, metric='cosine')) if 1 < unique_count < len(ids) else None
            result = {'method': method, 'k': k, 'effective_k': unique_count, 'silhouette_cosine': score,
                      'purity_vs_dr': cluster_purity(labels, [dp['dr'] for dp in datapoints])}
            candidates.append(result)
            print(json.dumps(result), flush=True)
            if score is not None and (best is None or score > best[0]['silhouette_cosine']):
                best = (result, labels)
    if best is None:
        raise ValueError('No valid clustering')
    selected, assignments = best
    similarity = (features @ features.T).toarray()
    clusters = {}
    indexed = {}
    for i, cluster in enumerate(assignments):
        cluster_id = str(int(cluster))
        clusters.setdefault(cluster_id, []).append(ids[i])
        siblings = [j for j in range(len(ids)) if j != i and assignments[j] == cluster]
        siblings.sort(key=lambda j: (-float(similarity[i, j]), ids[j]))
        indexed[ids[i]] = {'cluster': cluster_id, 'siblings': [ids[j] for j in siblings]}
    metrics = {'report_count': len(rows), 'label_key_count': len(keys), 'leaf_key_count': len(leaf_indices),
               'mapped_topic_count': len(topic_ids), 'leaf_directed_relation_count': len(raw_edges),
               'topic_directed_relation_count': len(topic_edges), 'datapoint_count': len(ids),
               'datapoints_with_description': descriptions, 'tfidf_feature_count': words.shape[1],
               'feature_count': features.shape[1], 'clustering_candidates': candidates, 'selected': selected}
    payload = {'version': 'esrs-concept-relations-v1',
               'generated_at': datetime.now(timezone.utc).isoformat(),
               'source_sha256': {'gpt41_silver_labels': sha(args.labels), 'ig3_datapoints': sha(args.datapoints),
                                 'ar16_mapping': sha(args.mapping), 'generator': sha(Path(__file__)),
                                 'metric_functions': sha(APP/'ai-service/src/concept_relations.py')},
               'method': {'labels': 'gpt41 silver labels; observational, not human-confirmed truth',
                          'topics': 'OR of approved mapped leaf keys; summaries/review-only excluded',
                          'correlation': 'binary phi, Jaccard, directional lift/confidence',
                          'clustering': 'TF-IDF names + available descriptions, DR and standard one-hot; L2 normalized',
                          'siblings': 'same selected cluster, cosine descending then canonical ID'},
               'parameters': {'thresholds': thresholds, 'k_range': args.k, 'seed': 42, 'n_init': 10,
                              'tfidf': {'ngram_range': [1, 2], 'min_df': 2, 'sublinear_tf': True, 'stop_words': 'english'},
                              'dr_weight': .5, 'standard_weight': .5, 'agglomerative_linkage': 'average',
                              'selection': 'maximum full-data cosine silhouette; tie: first candidate'},
               'software': {'python': sys.version.split()[0], 'numpy': np.__version__, 'scipy': scipy.__version__,
                            'scikit_learn': sklearn.__version__},
               'metrics': metrics, 'excluded_mapping_keys': excluded,
               'leaf_relations': raw_edges, 'topic_relations': topic_edges,
               'clusters': clusters, 'datapoints': indexed}
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(payload, ensure_ascii=False, indent=2, allow_nan=False)+'\n')
    args.output.with_suffix('.json.sha256').write_text(sha(args.output)+'\n')
    evidence = {key: payload[key] for key in ('version', 'generated_at', 'source_sha256', 'method', 'parameters', 'software', 'metrics')}
    evidence['artifact_sha256'] = sha(args.output)
    args.evidence.parent.mkdir(parents=True, exist_ok=True)
    args.evidence.write_text(json.dumps(evidence, indent=2, allow_nan=False)+'\n')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--labels', required=True, type=Path)
    parser.add_argument('--datapoints', type=Path, default=APP/'web/data/esrs_datapoints_ig3.json')
    parser.add_argument('--mapping', type=Path, default=APP/'web/data/ar16_to_python_esrs_mapping_new_format_732_v1.json')
    parser.add_argument('--output', type=Path, default=APP/'web/data/esrs_concept_relations_v1.json')
    parser.add_argument('--evidence', type=Path, default=APP/'ai-service/docs/concept-relations-run-2026-10-08.json')
    parser.add_argument('--k', type=int, nargs='+', default=[8, 12, 16, 24, 32, 48, 64, 80])
    build(parser.parse_args())
