from abc import ABC, abstractmethod

from lightgbm import LGBMClassifier
from sklearn.ensemble import RandomForestClassifier
from xgboost import XGBClassifier


class BaseClassifierBuilder(ABC):
    @abstractmethod
    def build(self):
        pass

    @abstractmethod
    def build_from_params(self, params: dict, **kwargs):
        pass

    @abstractmethod
    def hyperparams(self, trial=None) -> dict:
        pass

    @abstractmethod
    def name(self):
        pass


class RandomForestBuilder(BaseClassifierBuilder):
    def build(self):
        return RandomForestClassifier(n_estimators=100, random_state=42, n_jobs=-1)

    def build_from_params(self, params: dict, **kwargs):
        return RandomForestClassifier(**params, **kwargs)

    def hyperparams(self, trial=None) -> dict:
        if trial is not None:
            return {
                'n_estimators': trial.suggest_int('n_estimators', 100, 800, step=100),
                'max_depth': trial.suggest_int('max_depth', 5, 30),
                'min_samples_split': trial.suggest_int('min_samples_split', 2, 10),
                'min_samples_leaf': trial.suggest_int('min_samples_leaf', 1, 5),
                'max_features': trial.suggest_categorical('max_features', ['sqrt', 'log2', None]),
                'class_weight': trial.suggest_categorical('class_weight', [None, 'balanced']),
            }
        else:
            return {
                'classifier__estimator__n_estimators': [100, 300, 500, 800],
                'classifier__estimator__max_depth': [None, 10, 20, 30],
                'classifier__estimator__min_samples_split': [2, 5, 10],
                'classifier__estimator__min_samples_leaf': [1, 2, 4, 5],
                'classifier__estimator__max_features': ['sqrt', 'log2', None],
                'classifier__estimator__class_weight': [None, 'balanced'],
            }

    def name(self):
        return "RandomForest"

class LGBMClassifierBuilder(BaseClassifierBuilder):
    def build(self):
        return LGBMClassifier(n_estimators=100, random_state=42, verbosity=-1)

    def build_from_params(self, params: dict, **kwargs):
        return LGBMClassifier(**params, **kwargs, verbosity=-1)

    def hyperparams(self, trial=None) -> dict:
        if trial is not None:
            return {
                'n_estimators': trial.suggest_int('n_estimators', 100, 800),
                'learning_rate': trial.suggest_float('learning_rate', 0.01, 0.2, log=True),
                'max_depth': trial.suggest_int('max_depth', 3, 20),
                'num_leaves': trial.suggest_int('num_leaves', 20, 150),
                'min_child_samples': trial.suggest_int('min_child_samples', 5, 100),
                'subsample': trial.suggest_float('subsample', 0.5, 1.0),
                'colsample_bytree': trial.suggest_float('colsample_bytree', 0.5, 1.0),
                'reg_alpha': trial.suggest_float('reg_alpha', 0, 2.0),
                'reg_lambda': trial.suggest_float('reg_lambda', 0, 2.0),
            }
        else:
            return {
                'classifier__estimator__n_estimators': [100, 200, 400, 600, 800],
                'classifier__estimator__learning_rate': [0.01, 0.03, 0.05, 0.1, 0.2],  # log scale in Optuna → manually sampled
                'classifier__estimator__max_depth': [3, 5, 7, 10, 15, 20],
                'classifier__estimator__num_leaves': [20, 31, 50, 70, 100, 130, 150],
                'classifier__estimator__min_child_samples': [5, 10, 20, 40, 60, 80, 100],
                'classifier__estimator__subsample': [0.5, 0.6, 0.7, 0.8, 0.9, 1.0],
                'classifier__estimator__colsample_bytree': [0.5, 0.6, 0.7, 0.8, 0.9, 1.0],
                'classifier__estimator__reg_alpha': [0.0, 0.1, 0.5, 1.0, 1.5, 2.0],
                'classifier__estimator__reg_lambda': [0.0, 0.1, 0.5, 1.0, 1.5, 2.0],
            }

    def name(self):
        return "LGBMClassifier"

class XGBClassifierBuilder(BaseClassifierBuilder):
    def build(self):
        return XGBClassifier(
         n_estimators=100,
         learning_rate=0.1,
         max_depth=6,
         use_label_encoder=False,  # suppress old warnings
         eval_metric='logloss',    # required to silence deprecation warnings
         verbosity=0,              # suppress training logs
         random_state=42
    )

    def build_from_params(self, params: dict, **kwargs):
        return XGBClassifier(**params, **kwargs)

    def hyperparams(self, trial=None) -> dict:
        if trial is not None:
            return {
                'n_estimators': trial.suggest_int('n_estimators', 100, 800),
                'learning_rate': trial.suggest_float('learning_rate', 0.01, 0.2, log=True),
                'max_depth': trial.suggest_int('max_depth', 3, 20),
                'min_child_weight': trial.suggest_float('min_child_weight', 1, 10),
                # similar to LightGBM's min_child_samples
                'subsample': trial.suggest_float('subsample', 0.5, 1.0),
                'colsample_bytree': trial.suggest_float('colsample_bytree', 0.5, 1.0),
                'gamma': trial.suggest_float('gamma', 0, 5),  # minimum loss reduction (optional)
                'reg_alpha': trial.suggest_float('reg_alpha', 0, 2.0),  # L1 regularization
                'reg_lambda': trial.suggest_float('reg_lambda', 0, 2.0),  # L2 regularization
            }
        else:
            return {
                'classifier__estimator__n_estimators': [100, 200, 400, 600, 800],
                'classifier__estimator__learning_rate': [0.01, 0.05, 0.1, 0.2],
                'classifier__estimator__max_depth': [3, 5, 7, 10, 15, 20],
                'classifier__estimator__min_child_weight': [1, 3, 5, 10],
                'classifier__estimator__subsample': [0.5, 0.7, 0.9, 1.0],
                'classifier__estimator__colsample_bytree': [0.5, 0.7, 0.9, 1.0],
                'classifier__estimator__gamma': [0, 0.1, 0.5, 1, 2],
                'classifier__estimator__reg_alpha': [0, 0.1, 0.5, 1, 2],
                'classifier__estimator__reg_lambda': [0, 0.1, 0.5, 1, 2],
            }

    def name(self):
        return "XGBClassifier"
