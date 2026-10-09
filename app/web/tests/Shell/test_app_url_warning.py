"""Real PHP request lifecycles, without database access or fixture migrations.

Run with python3 -B -m unittest discover -s tests/Shell from app/web.
Requires the Linux public runtime, PHP and installed Composer dependencies.
"""

import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import unittest
from urllib.request import urlopen


WEB = Path(__file__).resolve().parents[2]
PHP = shutil.which("php")
MESSAGE = "APP_URL must have a public host; its host is empty, localhost or a placeholder."


@unittest.skipUnless(PHP and (WEB / "vendor/autoload.php").is_file()
                     and Path("/proc/self/stat").is_file(),
                     "Requires PHP, Composer dependencies and Linux procfs")
class AppUrlWarningServerTest(unittest.TestCase):
    def test_two_requests_per_process_and_new_server_start(self):
        with tempfile.TemporaryDirectory(prefix="i4s-warning-") as directory:
            root = Path(directory)
            (root / "storage/logs").mkdir(parents=True)
            router = root / "router.php"
            router.write_text("""<?php
require getenv('I4S_TEST_WEB_ROOT').'/vendor/autoload.php';
$app = require getenv('I4S_TEST_WEB_ROOT').'/bootstrap/app.php';
$app->useStoragePath(getenv('I4S_TEST_STORAGE'));
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
header('Content-Type: application/json');
echo json_encode(['pid' => getmypid()]);
""")
            env = os.environ.copy()
            env.pop("PHP_CLI_SERVER_WORKERS", None)
            env.update(
                I4S_TEST_WEB_ROOT=str(WEB), I4S_TEST_STORAGE=str(root / "storage"),
                APP_ENV="production", APP_URL="https://example.com",
                APP_DEBUG="false", LOG_CHANNEL="single", LOG_LEVEL="error",
                APP_CONFIG_CACHE=str(root / "no-config-cache.php"),
            )
            log = root / "storage/logs/laravel.log"
            # A real server restart must get a fresh warning; its old marker
            # must not suppress the warning for another process lifetime.
            for expected_count in (1, 2):
                with socket.socket() as listener:
                    listener.bind(("127.0.0.1", 0))
                    port = listener.getsockname()[1]
                with (root / "server.log").open("a+") as output:
                    server = subprocess.Popen(
                        [PHP, "-d", "sys_temp_dir=" + str(root), "-S",
                         f"127.0.0.1:{port}", str(router)],
                        cwd=WEB, env=env, stdout=output, stderr=output,
                    )
                    try:
                        deadline = time.monotonic() + 10
                        while True:
                            self.assertIsNone(server.poll(), "PHP server exited before accepting requests")
                            try:
                                with socket.create_connection(("127.0.0.1", port), timeout=0.2):
                                    break
                            except OSError:
                                if time.monotonic() >= deadline:
                                    self.fail("PHP server did not start")
                                time.sleep(0.05)
                        pids = []
                        for _ in range(2):
                            with urlopen(f"http://127.0.0.1:{port}/", timeout=10) as response:
                                self.assertEqual(response.status, 200)
                                pids.append(json.load(response)["pid"])
                        self.assertEqual(pids, [server.pid, server.pid])
                        self.assertEqual(log.read_text().count(MESSAGE), expected_count)
                        self.assertNotIn("https://example.com", log.read_text())
                    finally:
                        server.terminate()
                        try:
                            server.wait(timeout=5)
                        except subprocess.TimeoutExpired:
                            server.kill()
                            server.wait(timeout=5)


if __name__ == "__main__":
    unittest.main()
