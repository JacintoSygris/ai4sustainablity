"""One private, pinned snapshot per process; failure isolated from prediction."""
import os
import threading
import time
from collections import deque
from .sector_comparison import compare_topics, load_snapshot


class SectorRuntime:
    def __init__(self):
        self.snapshot = None
        self.digest = None
        self.slots = threading.BoundedSemaphore(2)
        self.lock = threading.Lock()
        self.requests = deque()
        self.enabled = os.getenv("ESRS_SECTOR_COMPARISON_ENABLED", "false").lower() in ("true", "1")
        if self.enabled:
            try:
                self.digest = os.getenv("ESRS_SECTOR_COMPARISON_SHA256", "")
                self.snapshot = load_snapshot(os.getenv("ESRS_SECTOR_COMPARISON_PATH", ""), self.digest)
            except Exception:
                # Invalid provisioning must never prevent the prediction service starting.
                self.snapshot = None

    def admit(self):
        with self.lock:
            now = time.monotonic()
            while self.requests and self.requests[0] <= now - 60:
                self.requests.popleft()
            if len(self.requests) >= 60:
                return False
            self.requests.append(now)
            return True

    def compare(self, request):
        return compare_topics(self.snapshot, self.digest, request.sections, request.topics)
