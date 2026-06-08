from abc import ABC, abstractmethod

import optuna
from sklearn.model_selection import cross_val_score, RandomizedSearchCV, GridSearchCV
from sklearn.pipeline import Pipeline


class Optimiser(ABC):
    def __init__(self, grid = False):
        self.__grid = grid

    @abstractmethod
    def optimise(self, base_classifier_builder, wrapper, clf, preprocessor, X_train, y_train):
        pass

    @abstractmethod
    def name(self):
        pass


class VoidOptimiser(Optimiser):
    # no optimisation, just return the whole classifier
    def optimise(self, base_classifier_builder, wrapper,  clf, preprocessor, X_train, y_train):
        return clf

    def name(self):
        return "No optimiser"

class OptunaOptimiser(Optimiser):
    def name(self):
        return "Optuna"

    def objective(self, trial, base_classifier_builder, wrapper, X_train, y_train, preprocessor):
        bcb = base_classifier_builder()
        params = bcb.hyperparams(trial)

        base_clf = bcb.build_from_params(params, random_state=42)
        clf = Pipeline([
            ('preprocessor', preprocessor),
            ('classifier', wrapper().build(base_clf))
        ])

        # Use cross-validation for evaluation (e.g., macro F1)
        score = cross_val_score(clf, X_train, y_train, cv=3, scoring='f1_macro', n_jobs=-1).mean()
        return score

    def optimise(self,  base_classifier_builder, wrapper, clf, preprocessor, X_train, y_train):
        n_trials = 20

        study_name = wrapper().name()+" ("+base_classifier_builder().name()+")"
        study = optuna.create_study(direction='maximize', study_name=study_name)
        study.optimize(lambda trial:
                            self.objective(trial, base_classifier_builder, wrapper, X_train, y_train, preprocessor),
                       n_trials=n_trials)

        print("==================================================================")
        print("Best hyperparameters:")
        for k, v in study.best_params.items():
            print(f"{k}: {v}")
        print(f"Best cross-validated score: {study.best_value:.4f}")

        # Return best model
        best_clf = Pipeline([
            ('preprocessor', preprocessor),
            ('classifier', wrapper().build(
                base_classifier_builder().build_from_params(study.best_params, random_state=42)
            ))
        ])
        return best_clf


class SCOptimiser(Optimiser):
    def name(self):
        if self.__grid:
            return "Scikit-Learn CV (grid)"
        else:
            return "Scikit-Learn CV"

    def optimise(self, base_classifier_builder, wrapper, clf, preprocessor, X_train, y_train):
        param_grid = base_classifier_builder.hyperparams()

        if not self.grid:
            search = RandomizedSearchCV(
                clf,
                param_distributions=param_grid,
                n_iter=10,
                cv=3,
                verbose=2,
                n_jobs=-1
            )
        else:
            search = GridSearchCV(
                clf,
                param_distributions=param_grid,
                n_iter=10,
                cv=3,
                verbose=2,
                n_jobs=-1
            )

        search.fit(X_train, y_train)

        print("\n📊 Best hyperparameters found:")
        for param, val in search.best_params_.items():
            print(f"{param}: {val}")

        print(f"\n🔍 Best CV score: {search.best_score_:.4f}")

        return search.best_estimator_
