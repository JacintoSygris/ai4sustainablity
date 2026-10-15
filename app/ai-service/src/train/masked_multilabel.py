"""Per-label masked multi-label trainer.

Each label gets an independent clone of the base estimator fitted only on
rows whose mask says the label is known. predict/predict_proba assemble the
full matrix. Trades away ClassifierChain's label-dependency modeling for the
ability to not train on fabricated negatives — the Phase 0 baseline showed
fabricated negatives are the larger error source (v7: micro-F1 0.2365 vs
prevalence-prior 0.3067).
"""
from __future__ import annotations

import numpy as np
from sklearn.base import clone


class MaskedPerLabelClassifier:
    def __init__(self, base_estimator):
        self.base_estimator = base_estimator
        self.estimators_: list = []
        self.constant_: list[float] = []  # per-label constant when one class is absent

    def fit(self, X, Y, mask):
        X = np.asarray(X)
        Y = np.asarray(Y)
        mask = np.asarray(mask)
        if Y.shape != mask.shape:
            raise ValueError(f"Y shape {Y.shape} != mask shape {mask.shape}")
        self.estimators_ = []
        self.constant_ = []
        for j in range(Y.shape[1]):
            keep = mask[:, j] == 1
            yj = Y[keep, j]
            if len(np.unique(yj)) < 2:
                self.estimators_.append(None)
                self.constant_.append(float(yj[0]) if len(yj) else 0.0)
                continue
            estimator = clone(self.base_estimator)
            estimator.fit(X[keep], yj)
            self.estimators_.append(estimator)
            self.constant_.append(0.0)
        return self

    def predict_proba_matrix(self, X) -> np.ndarray:
        X = np.asarray(X)
        out = np.zeros((X.shape[0], len(self.estimators_)))
        for j, estimator in enumerate(self.estimators_):
            if estimator is None:
                out[:, j] = self.constant_[j]
                continue
            classes = list(estimator.classes_)
            if 1 in classes:
                out[:, j] = estimator.predict_proba(X)[:, classes.index(1)]
            else:
                out[:, j] = 0.0
        return out

    def predict(self, X, threshold: float = 0.5) -> np.ndarray:
        return (self.predict_proba_matrix(X) >= threshold).astype(int)

    # sklearn-ish compatibility for the runtime profile loader, which calls
    # predict / predict_proba on the loaded artifact.
    def predict_proba(self, X):
        probabilities = self.predict_proba_matrix(X)
        return [
            np.column_stack([1.0 - probabilities[:, j], probabilities[:, j]])
            for j in range(probabilities.shape[1])
        ]
