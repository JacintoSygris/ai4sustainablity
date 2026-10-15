"""Canonical standard projection for feedback policies."""
import re


# The standard axis includes summary roll-ups.
_STANDARD = re.compile(r"^esrs_(e[1-5]|s[1-4]|g1)_", re.IGNORECASE)


def standard_of(column: str) -> str | None:
    match = _STANDARD.match(column)
    return f"esrs_{match.group(1).lower()}" if match else None
