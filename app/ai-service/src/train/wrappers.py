from abc import ABC, abstractmethod

from sklearn.multioutput import MultiOutputClassifier, ClassifierChain


class ClassifierWrapperBuilder(ABC):
    @abstractmethod
    def build(self, base_clf):
        pass

    @abstractmethod
    def name(self):
        pass

class MultiOutputWrapperBuilder(ABC):
    def build(self, base_clf):
        return MultiOutputClassifier(base_clf)

    def name(self):
        return "MultiOutputClassifier"

class ChainWrapperBuilder(ABC):
    def build(self, base_clf):
        return ClassifierChain(base_clf)

    def name(self):
        return "ClassifierChain"
