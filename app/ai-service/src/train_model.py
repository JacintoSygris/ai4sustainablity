"""Compatibility guard for the archived legacy trainer.

The historical implementation lives in
`app/ai-service/archive/2026-06-09-train-model-legacy.py`.
Use `train_model_yaml.py` for the active new-format 732 training pipeline.
"""

ARCHIVED_TRAINER_MESSAGE = (
    "The legacy retrain trainer was archived on 2026-06-09. "
    "Use src/train_model_yaml.py for the approved new-format 732 training pipeline."
)


def load_data(*_args, **_kwargs):
    raise RuntimeError(ARCHIVED_TRAINER_MESSAGE)


def train_model(*_args, **_kwargs):
    raise RuntimeError(ARCHIVED_TRAINER_MESSAGE)


def main(*_args, **_kwargs):
    raise RuntimeError(ARCHIVED_TRAINER_MESSAGE)


if __name__ == "__main__":
    raise SystemExit(ARCHIVED_TRAINER_MESSAGE)
