# ========================================================================= Configuration builders
from .classifiers import *
from .optimisation import *
from .splitting import *
from .wrappers import *

base_classifiers = {
    'RF' : RandomForestBuilder,
    'LGBM' : LGBMClassifierBuilder,
    'XGB' : XGBClassifierBuilder
}

wrapper_models = {
    'multi-output' : MultiOutputWrapperBuilder,
    'chain' : ChainWrapperBuilder
}

splitters = {
    'iterative' : IterativeSplitter,
    'normal' : StandardSplitter,
    'group' : GroupShuffleSplitter
}

optimisers = {
    'none' : VoidOptimiser(),
    'optuna' : OptunaOptimiser(),
    'sc_grid' : SCOptimiser(True),
    'sc_random' : SCOptimiser(False)
}
