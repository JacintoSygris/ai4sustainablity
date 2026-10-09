"""Exercise the real entrypoint with a fake PHP binary; no PHP or DB required."""

import os
from pathlib import Path
import subprocess
import tempfile
import unittest


ENTRYPOINT = Path(__file__).resolve().parents[2] / "public-entrypoint.sh"


class PublicEntrypointTest(unittest.TestCase):
    def run_entrypoint(self, reset=None, check_exit="0", seed_exit="0", restarts=1):
        with tempfile.TemporaryDirectory(prefix="i4s-entrypoint-") as directory:
            root = Path(directory)
            binary = root / "php"
            binary.write_text(
                '#!/bin/sh\n'
                'if [ "$1" = "-r" ]; then exit 0; fi\n'
                'printf "%s\\n" "$*" >> "$CALLS_FILE"\n'
                'if [ "$2" = "i4s:deploy:check" ]; then exit "$CHECK_EXIT"; fi\n'
                'if [ "$2" = "db:seed" ]; then exit "$SEED_EXIT"; fi\n'
            )
            binary.chmod(0o700)
            database = root / "persistent" / "database.sqlite"
            database.parent.mkdir()
            database.write_bytes(b"existing database sentinel")
            calls = root / "calls"
            env = os.environ.copy()
            env.pop("I4S_RESET_DATABASE_ON_START", None)
            env.update(
                PATH=str(root) + os.pathsep + env["PATH"],
                DB_DATABASE=str(database), CALLS_FILE=str(calls),
                CHECK_EXIT=check_exit, SEED_EXIT=seed_exit,
            )
            if reset is not None:
                env["I4S_RESET_DATABASE_ON_START"] = reset
            for _ in range(restarts):
                result = subprocess.run(
                    ["sh", str(ENTRYPOINT)], cwd=root, env=env,
                    capture_output=True, text=True, check=False,
                )
            self.assertEqual(database.read_bytes(), b"existing database sentinel")
            self.assertTrue((root / "storage/framework/sessions").is_dir())
            return result, calls.read_text().splitlines()

    def test_default_and_false_never_reset(self):
        for value in (None, "false", "1", "TRUE", ""):
            with self.subTest(value=value):
                result, calls = self.run_entrypoint(value)
                self.assertEqual(result.returncode, 0)
                self.assertIn("artisan migrate --force --no-ansi", calls)
                self.assertFalse(any("migrate:fresh" in call for call in calls))
                self.assertFalse(any("--seed" in call for call in calls))

    def test_exact_opt_in_warns_and_resets(self):
        result, calls = self.run_entrypoint("true")
        self.assertEqual(result.returncode, 0)
        self.assertIn("WARNING I4S_RESET_DATABASE_ON_START=true", result.stderr)
        self.assertIn("artisan migrate:fresh --force --no-ansi", calls)
        self.assertNotIn("artisan migrate --force --no-ansi", calls)

    def test_restarts_seed_only_reference_data_and_keep_the_server_command(self):
        result, calls = self.run_entrypoint(restarts=2)
        self.assertEqual(result.returncode, 0)
        expected = [
            "artisan config:clear --no-ansi", "artisan route:clear --no-ansi",
            "artisan view:clear --no-ansi", "artisan migrate --force --no-ansi",
            "artisan db:seed --class=NaceCodeSeeder --force --no-ansi",
            "artisan db:seed --class=EsrsTopicSeeder --force --no-ansi",
            "artisan i4s:deploy:check --no-ansi",
            "artisan serve --host=0.0.0.0 --port=8000",
        ]
        self.assertEqual(calls, expected * 2)
        self.assertIn("PASS i4s:deploy:check", result.stdout)

    def test_failed_check_is_visible_but_does_not_block_start(self):
        result, calls = self.run_entrypoint(check_exit="1")
        self.assertEqual(result.returncode, 0)
        self.assertIn("FAIL i4s:deploy:check (exit=1)", result.stderr)
        self.assertEqual(calls[-1], "artisan serve --host=0.0.0.0 --port=8000")

    def test_failed_seeding_does_not_start_the_server(self):
        result, calls = self.run_entrypoint(seed_exit="1")
        self.assertEqual(result.returncode, 1)
        self.assertFalse(any("artisan serve" in call for call in calls))


if __name__ == "__main__":
    unittest.main()
