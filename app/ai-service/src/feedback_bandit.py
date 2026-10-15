"""Bounded, opt-in P8 contextual feedback policies. No network or live training.

Updates are constant in history length: O(d²) for disjoint LinUCB (rank-one
inverse update), O(d) for SGD and O(1) for bucketed reward statistics. d is fixed.
JSON is an integrity envelope, NOT an authorization/signature mechanism.
"""
from __future__ import annotations

import hashlib
from contextlib import contextmanager
from contextvars import ContextVar
import json
import logging
import math
import os
from pathlib import Path
import re
import tempfile
import uuid

import numpy as np
from sklearn.linear_model import SGDClassifier

PATH_ENV = "ESRS_FEEDBACK_BANDIT_PATH"
VERSION = "feedback-bandit-v2"
ALGORITHMS = ("epsilon_greedy", "linucb", "thompson", "thompson_bucket", "sgd")
ENCODER = "p5-hash-tanh-v1"
DIM = 18  # intercept + RF score + 16 signed feature-hash coordinates
BUCKETS = 16
LOGGER = logging.getLogger(__name__)
_GATE_DECISION_ID = ContextVar('learning_gate_decision_id', default=None)


@contextmanager
def gate_decision(decision_id):
    """Local evaluation only: replay a protocol-bound decision, then restore it.

    Both serving layouts use this shared bandit implementation. Ordinary P6
    requests retain random IDs and never enter this context.
    """
    require(isinstance(decision_id, str) and len(decision_id) == 64
            and all(c in '0123456789abcdef' for c in decision_id))
    token = _GATE_DECISION_ID.set(decision_id)
    try:
        yield
    finally:
        _GATE_DECISION_ID.reset(token)


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(",", ":"), allow_nan=False).encode()


def digest(value):
    return hashlib.sha256(canonical(value)).hexdigest()


def require(condition):
    if not condition:
        raise ValueError("Invalid feedback bandit state or event")


def number(value, low, high):
    require(type(value) in (int, float) and math.isfinite(value) and low <= value <= high)
    return float(value)


def encode_context(row: dict) -> list[float]:
    """Encode the model's P5 feature row; never encode company names or free text.

    Only callers with the exact bound feature schema may supply a row. Categorical
    values are signed-hashed; numeric magnitudes use tanh. Fixed vocabulary-free
    encoding does not fit on evaluation rows. RF score is appended separately.
    """
    out = np.zeros(DIM - 2)
    for key, value in sorted(row.items()):
        if isinstance(value, (str, np.str_)):
            token, magnitude = f"{key}={value}", 1.0
        else:
            magnitude = float(value)
            if math.isnan(magnitude):
                token, magnitude = f"{key}=<missing>", 1.0
            else:
                require(math.isfinite(magnitude))
                token, magnitude = key, math.tanh(magnitude)
        hashed = hashlib.sha256(token.encode()).digest()
        out[int.from_bytes(hashed[:2], "big") % len(out)] += magnitude * (1 if hashed[2] & 1 else -1)
    out /= max(1.0, float(np.linalg.norm(out)))
    return out.tolist()


def vector(context, score):
    require(isinstance(context, (list, tuple, np.ndarray)) and len(context) == DIM - 2)
    values = [number(v.item() if isinstance(v, np.generic) else v, -1, 1) for v in context]
    return np.array([1.0, number(score, 0, 1), *values])


def bucket(x):
    # Stable coarse context + score partition; bounded memory regardless of history.
    return sum((int(v >= 0) << i) for i, v in enumerate((x[1] - .5, *x[2:5])))


def new_sgd():
    return SGDClassifier(loss="log_loss", learning_rate="constant", eta0=.05,
                         alpha=.0001, random_state=0, shuffle=False)


class FeedbackBandit:
    def __init__(self, profile, topics, feature_columns, model_sha256, *,
                 algorithm="linucb", shadow=True, margin=.1, max_changes=3,
                 min_events=3, epsilon=.1, alpha=.5, seed=0):
        require(isinstance(profile, str) and bool(profile.strip()))
        require(isinstance(topics, list) and bool(topics) and len(topics) <= 256)
        require(all(isinstance(k, str) and k.startswith("esrs_") for k in topics))
        require(len(set(topics)) == len(topics))
        require(isinstance(feature_columns, list) and bool(feature_columns) and len(feature_columns) <= 1024)
        require(all(isinstance(k, str) and k for k in feature_columns) and len(set(feature_columns)) == len(feature_columns))
        require(isinstance(model_sha256, str) and len(model_sha256) == 64 and all(c in "0123456789abcdef" for c in model_sha256))
        require(algorithm in ALGORITHMS and type(shadow) is bool)
        require(type(max_changes) is int and 0 <= max_changes <= 5)
        require(type(min_events) is int and 1 <= min_events <= 10000)
        require(type(seed) is int and 0 <= seed < 2**32)
        self.config = dict(profile=profile, topics=topics.copy(), feature_columns=feature_columns.copy(),
                           model_sha256=model_sha256, algorithm=algorithm, shadow=shadow,
                           margin=number(margin, 0, .25), max_changes=max_changes,
                           min_events=min_events, epsilon=number(epsilon, 0, 1),
                           alpha=number(alpha, 0, 10), seed=seed)
        self.sequence = 0
        self.states = {}
        self._topics = set(topics)

    def _state(self, topic):
        require(topic in self._topics)
        if topic not in self.states:
            self.states[topic] = {"n": 0, "rewards": np.zeros((BUCKETS, 2, 2)),
                                  "inverse": np.tile(np.eye(DIM), (2, 1, 1)),
                                  "b": np.zeros((2, DIM)), "sgd": new_sgd()}
        return self.states[topic]

    def choose(self, topic, context, score, fallback, *, decision_id):
        x = vector(context, score)
        require(type(fallback) is int and fallback in (0, 1))
        require(isinstance(decision_id, str) and 0 < len(decision_id) <= 128)
        # Independent decision-local draws: request reloads and worker scheduling
        # cannot reset exploration. The same state + ID replays the same choice.
        rng = np.random.default_rng(int(digest([self.config["seed"], decision_id, topic]), 16))
        state = self._state(topic)
        if state["n"] < self.config["min_events"]:
            return fallback
        algorithm = self.config["algorithm"]
        if algorithm == "sgd":
            return int(state["sgd"].predict(x[None, :])[0])
        if algorithm == "linucb":
            values = [float(x @ state["inverse"][a] @ state["b"][a]) +
                      self.config["alpha"] * math.sqrt(max(0., float(x @ state["inverse"][a] @ x)))
                      for a in (0, 1)]
        else:
            index = 0 if algorithm == "thompson" else bucket(x)
            counts = state["rewards"][index]
            if algorithm == "epsilon_greedy":
                if rng.random() < self.config["epsilon"]:
                    return int(rng.integers(2))
                values = [(counts[a, 0] + (score if a else 1-score)) / (counts[a].sum() + 1)
                          for a in (0, 1)]
            else:
                values = [rng.beta(1 + counts[a, 0], 1 + counts[a, 1]) for a in (0, 1)]
        return fallback if values[0] == values[1] else int(values[1] > values[0])

    def update(self, *, sequence, topic, context, base_score, proposed, accepted):
        """Apply one ordered confirmed event. Unproposed negatives are unobserved.

        Reward = 1 when the actual proposal action matches confirmed truth. An
        addition supplies action=0, truth=1, reward=0. No invented negative labels.
        Persist sequence with state; caller supplies a durable ordered ledger.
        """
        require(type(sequence) is int and sequence == self.sequence + 1)
        require(type(proposed) is bool and type(accepted) is bool)
        require(proposed or accepted)
        x = vector(context, base_score)
        state = self._state(topic)
        action, reward = int(proposed), int(proposed == accepted)
        algorithm = self.config["algorithm"]
        index = 0 if algorithm == "thompson" else bucket(x)
        if algorithm == "linucb":
            inv = state["inverse"][action]
            ax = inv @ x
            state["inverse"][action] = inv - np.outer(ax, ax) / (1 + x @ ax)
            state["b"][action] += reward * x
        elif algorithm == "sgd":
            state["sgd"].partial_fit(x[None, :], [int(accepted)], classes=np.array([0, 1]))
        else:
            state["rewards"][index, action, 1 - reward] += 1
        state["n"] += 1
        self.sequence = sequence

    def update_event(self, event):
        """Consume the private Laravel contract after its authorization/dedup step."""
        require(isinstance(event, dict) and set(event) == {
            "version", "profile", "model_sha256", "feature_schema_sha256", "encoder",
            "sequence", "topic", "context", "base_score", "proposed", "accepted",
        })
        require(event["version"] == "p8-bandit-event-v1" and event["encoder"] == ENCODER)
        require(event["profile"] == self.config["profile"])
        require(event["model_sha256"] == self.config["model_sha256"])
        require(event["feature_schema_sha256"] == digest(self.config["feature_columns"]))
        self.update(**{k: event[k] for k in (
            "sequence", "topic", "context", "base_score", "proposed", "accepted",
        )})

    def apply(self, emitted, columns, scores, context, *, threshold, candidate_predicate,
              label_thresholds=None, protected_standards=(), decision_id=None):
        """Apply bounded changes AFTER CRC; keep its last representative per standard."""
        from esrs_standard import standard_of as _standard_of
        require(len(columns) == len(scores) and len(columns) == len(set(columns)))
        require(self._topics <= set(columns) and set(emitted) <= set(columns))
        vector(context, 0.)
        frozen = _GATE_DECISION_ID.get()
        if frozen is not None:
            require(decision_id is None or decision_id == frozen)
            decision_id = frozen
        decision_id = uuid.uuid4().hex if decision_id is None else decision_id
        require(isinstance(decision_id, str) and 0 < len(decision_id) <= 128)
        result = list(emitted)
        changes = []
        score_by_key = dict(zip(columns, scores))
        # Stable order; cap applies to additions and removals combined.
        for topic in sorted(self._topics):
            if len(changes) >= self.config["max_changes"]:
                break
            score = float(score_by_key[topic])
            limit = (label_thresholds or {}).get(topic, threshold)
            number(limit, 0, 1)
            number(score, 0, 1)
            if not candidate_predicate(topic) or abs(score - limit) > self.config["margin"]:
                continue
            present = topic in result
            action = self.choose(topic, context, score, int(present), decision_id=decision_id)
            if action == int(present):
                continue
            standard = _standard_of(topic)
            if present and standard in protected_standards and sum(_standard_of(k) == standard for k in result) <= 1:
                continue
            if present:
                result.remove(topic)
            else:
                result.append(topic)
            changes.append({"topic": topic, "action": action})
        metadata = {"algorithm": self.config["algorithm"], "shadow": self.config["shadow"],
                    "sequence": self.sequence, "changes": changes, "decision_id": decision_id}
        # No company context, identifiers, notes or rewards in logs.
        if self.config["shadow"]:
            LOGGER.info("feedback_bandit_shadow algorithm=%s sequence=%d changes=%d",
                        self.config["algorithm"], self.sequence, len(changes))
        return (list(emitted) if self.config["shadow"] else result), metadata

    def envelope(self):
        states = {}
        for key, state in self.states.items():
            record = {k: (v.tolist() if isinstance(v, np.ndarray) else v)
                      for k, v in state.items() if k != "sgd"}
            sgd = state["sgd"]
            record["sgd"] = ({"coef": sgd.coef_.tolist(), "intercept": sgd.intercept_.tolist(),
                              "t": float(sgd.t_)} if hasattr(sgd, "coef_") else None)
            states[key] = record
        payload = {"version": VERSION, "encoder": ENCODER, "config": self.config,
                   "sequence": self.sequence, "states": states}
        return {"payload": payload, "sha256": digest(payload)}

    @classmethod
    def from_envelope(cls, envelope):
        require(isinstance(envelope, dict) and set(envelope) == {"payload", "sha256"})
        p = envelope["payload"]
        require(envelope["sha256"] == digest(p))
        require(set(p) == {"version", "encoder", "config", "sequence", "states"})
        require(p["version"] == VERSION and p["encoder"] == ENCODER)
        learner = cls(**p["config"])
        require(type(p["sequence"]) is int and 0 <= p["sequence"] < 2**53)
        require(isinstance(p["states"], dict) and set(p["states"]) <= learner._topics)
        for key, raw in p["states"].items():
            require(set(raw) == {"n", "rewards", "inverse", "b", "sgd"})
            require(type(raw["n"]) is int and 0 <= raw["n"] <= p["sequence"])
            state = learner._state(key)
            state["n"] = raw["n"]
            for field, shape in (("rewards", (BUCKETS, 2, 2)), ("inverse", (2, DIM, DIM)), ("b", (2, DIM))):
                array = np.asarray(raw[field], dtype=float)
                require(array.shape == shape and np.isfinite(array).all())
                state[field] = array
            require((state["rewards"] >= 0).all() and (state["rewards"] == np.floor(state["rewards"])).all())
            expected_rewards = raw["n"] if learner.config["algorithm"] in ("epsilon_greedy", "thompson", "thompson_bucket") else 0
            require(float(state["rewards"].sum()) == expected_rewards)
            require((np.abs(state["b"]) <= raw["n"]).all())
            for matrix in state["inverse"]:
                require(np.allclose(matrix, matrix.T) and np.linalg.eigvalsh(matrix).min() > 0)
            s = raw["sgd"]
            require((s is not None) == (learner.config["algorithm"] == "sgd" and raw["n"] > 0))
            if s is not None:
                require(set(s) == {"coef", "intercept", "t"})
                coef, intercept = np.asarray(s["coef"], dtype=float), np.asarray(s["intercept"], dtype=float)
                require(coef.shape == (1, DIM) and intercept.shape == (1,) and np.isfinite(coef).all() and np.isfinite(intercept).all())
                require(number(s["t"], 1, 2**53) == raw["n"] + 1)
                clf = state["sgd"]
                clf.coef_, clf.intercept_, clf.t_ = coef, intercept, s["t"]
                clf.classes_, clf.n_features_in_, clf.n_iter_ = np.array([0, 1]), DIM, 1
        require(sum(s["n"] for s in learner.states.values()) == p["sequence"])
        learner.sequence = p["sequence"]
        return learner

    def save(self, path):
        """Atomic replacement; single authorized writer owns ordering/locking."""
        path = Path(path)
        raw = canonical(self.envelope())
        with tempfile.NamedTemporaryFile(dir=path.parent, prefix=".bandit-", delete=False) as f:
            temporary = Path(f.name)
            try:
                f.write(raw)
                f.flush()
                os.fsync(f.fileno())
                os.replace(temporary, path)
            finally:
                if temporary.exists():
                    temporary.unlink()


def load_feedback_bandit(profile, *, feature_columns=None, model_sha256=None, topics=None):
    """Unset OFF; invalid state OFF. No pickle, auto-training or mutable global cache."""
    from learning_activation import bandit_mode, flag, deployed
    mode = bandit_mode()
    managed = mode in ("active", "shadow")
    path = os.getenv(PATH_ENV, "").strip()
    if not managed and (not path or "LEARNING_BANDIT_MODE" in os.environ):
        return None
    try:
        if managed:
            require(flag("LEARNING_ENGINE_ENABLED"))
            require(os.getenv("LEARNING_ENGINE_AUTHORITY", "consent" if deployed() else "deny") == "consent")
            from feedback_bandit_registry import BanditRegistry
            registry = BanditRegistry(os.environ["LEARNING_ENGINE_ROOT"])
            registry.lease()
            learner = registry.active()
            if learner is None:
                # Empty prior captures the frozen basis but changes no proposal.
                learner = FeedbackBandit(profile, list(topics), list(feature_columns), model_sha256, shadow=False)
            if mode == "shadow":
                learner.config["shadow"] = True
        else:
            with Path(path).open("rb") as stream:
                raw = stream.read(8_000_001)
            require(len(raw) <= 8_000_000)
            learner = FeedbackBandit.from_envelope(json.loads(raw))
        require(learner.config["profile"] == profile)
        if feature_columns is not None:
            require(learner.config["feature_columns"] == list(feature_columns))
        if model_sha256 is not None:
            require(learner.config["model_sha256"] == model_sha256)
        if topics is not None:
            require(learner.config["topics"] == list(topics))
        return learner
    except (OSError, ValueError, TypeError, KeyError, OverflowError, np.linalg.LinAlgError):
        LOGGER.warning("feedback_bandit disabled: invalid or incompatible state")
        return None
