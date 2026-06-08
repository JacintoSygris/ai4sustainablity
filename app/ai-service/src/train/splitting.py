from abc import ABC, abstractmethod

from sklearn.model_selection import train_test_split
from skmultilearn.model_selection import IterativeStratification

class Splitter(ABC):
    @abstractmethod
    def split(self, X, y):
        pass

    @abstractmethod
    def name(self):
        pass


class IterativeSplitter(Splitter):
    def split(self, X, y):
        stratifier = IterativeStratification(n_splits=2, order=1)
        train_idx, test_idx = next(stratifier.split(X, y))
        X_train, X_test = X.iloc[train_idx], X.iloc[test_idx]
        y_train, y_test = y.iloc[train_idx], y.iloc[test_idx]
        return X_train, X_test, y_train, y_test

    def name(self):
        return "IterativeSplitter"


class StandardSplitter(Splitter):
    def split(self, X, y):
        return train_test_split(X, y, test_size=0.2, random_state=42)

    def name(self):
        return "StandardSplitter"
