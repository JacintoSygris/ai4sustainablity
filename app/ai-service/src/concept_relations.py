"""Offline concept statistics. Silver-label associations are not materiality decisions."""
from collections import Counter
import math


def association_metrics(left, right):
    """Binary phi/Jaccard and directional association-rule metrics; undefined -> None."""
    if len(left) != len(right) or not len(left):
        raise ValueError('Equal, non-empty binary vectors required')
    if any(v not in (0, 1) for v in (*left, *right)):
        raise ValueError('Labels must be binary')
    n = len(left)
    a = sum(bool(x) and bool(y) for x, y in zip(left, right))
    b = sum(left) - a
    c = sum(right) - a
    d = n - a - b - c
    denominator = math.sqrt((a+b)*(a+c)*(b+d)*(c+d))
    return {
        'joint_count': int(a), 'support': a/n,
        'phi': (a*d-b*c)/denominator if denominator else None,
        'jaccard': a/(a+b+c) if a+b+c else None,
        'lift': a*n/((a+b)*(a+c)) if (a+b)*(a+c) else None,
        'confidence': a/(a+b) if a+b else None,
        'reverse_confidence': a/(a+c) if a+c else None,
    }


def cluster_purity(assignments, truth):
    """Weighted majority purity against DR (descriptive, not external validation)."""
    if len(assignments) != len(truth) or not len(truth):
        raise ValueError('Equal, non-empty assignments required')
    groups = {}
    for cluster, label in zip(assignments, truth):
        groups.setdefault(int(cluster), Counter())[label] += 1
    return sum(max(counts.values()) for counts in groups.values())/len(truth)
