import unittest

import numpy as np
from sklearn.ensemble import RandomForestClassifier
from sklearn.multioutput import MultiOutputClassifier

from train.masked_multilabel import MaskedPerLabelClassifier


def make_corrupted_dataset(seed: int = 7):
    """Label 1 depends on feature 1, but half its positive rows are masked
    and (as in the real CSVs) mislabeled 0. A mask-aware trainer must beat a
    mask-blind one on that label's recall."""
    rng = np.random.default_rng(seed)
    n = 400
    X = rng.normal(size=(n, 4))
    y_true = np.zeros((n, 2), dtype=int)
    y_true[:, 0] = (X[:, 0] > 0).astype(int)
    y_true[:, 1] = (X[:, 1] > 0).astype(int)

    y_observed = y_true.copy()
    mask = np.ones((n, 2), dtype=int)
    corrupt = rng.random(n) < 0.5
    corrupt_rows = corrupt & (y_true[:, 1] == 1)
    y_observed[corrupt_rows, 1] = 0  # fabricated negatives
    mask[corrupt_rows, 1] = 0  # ...but the mask knows they are unknown
    return X, y_observed, mask, y_true


class MaskedPerLabelClassifierTest(unittest.TestCase):
    def test_fit_predict_shapes(self) -> None:
        X, y, mask, _ = make_corrupted_dataset()
        clf = MaskedPerLabelClassifier(RandomForestClassifier(n_estimators=30, random_state=0))
        clf.fit(X, y, mask)
        predictions = clf.predict(X)
        self.assertEqual(predictions.shape, y.shape)
        probabilities = clf.predict_proba_matrix(X)
        self.assertEqual(probabilities.shape, y.shape)
        self.assertTrue(((probabilities >= 0) & (probabilities <= 1)).all())

    def test_masked_training_beats_mask_blind_on_corrupted_label(self) -> None:
        X, y_observed, mask, y_true = make_corrupted_dataset()
        split = 300
        masked_clf = MaskedPerLabelClassifier(
            RandomForestClassifier(n_estimators=50, random_state=0)
        )
        masked_clf.fit(X[:split], y_observed[:split], mask[:split])

        blind_clf = MultiOutputClassifier(
            RandomForestClassifier(n_estimators=50, random_state=0)
        )
        blind_clf.fit(X[:split], y_observed[:split])

        def recall_label1(y_pred) -> float:
            actual = y_true[split:, 1] == 1
            return float((np.asarray(y_pred)[:, 1][actual] == 1).mean())

        masked_recall = recall_label1(masked_clf.predict(X[split:]))
        blind_recall = recall_label1(blind_clf.predict(X[split:]))
        self.assertGreater(masked_recall, blind_recall + 0.15)

    def test_single_class_label_predicts_constant(self) -> None:
        X = np.random.default_rng(0).normal(size=(50, 3))
        y = np.zeros((50, 2), dtype=int)
        y[:, 0] = (X[:, 0] > 0).astype(int)  # learnable
        # label 1 all zeros -> constant
        mask = np.ones_like(y)
        clf = MaskedPerLabelClassifier(RandomForestClassifier(n_estimators=10, random_state=0))
        clf.fit(X, y, mask)
        predictions = clf.predict(X)
        self.assertTrue((predictions[:, 1] == 0).all())


if __name__ == "__main__":
    unittest.main()
