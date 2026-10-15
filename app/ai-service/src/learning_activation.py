"""Deployment defaults shared by serving and the local learning bridge."""
import os


def deployed():
    return os.getenv('LEARNING_DEPLOYED', str(os.getenv('APP_ENV') in ('production', 'staging'))).lower() in ('true', '1')


def flag(name, default=None):
    return os.getenv(name, str(deployed() if default is None else default)).lower() in ('true', '1')


def bandit_mode():
    return os.getenv('LEARNING_BANDIT_MODE', 'active' if deployed() else 'off')
