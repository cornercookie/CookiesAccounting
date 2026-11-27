"""Session idle timeout test."""
import os
import tempfile
import unittest
import time
from pathlib import Path

import test_print_and_comments as f


class SessionIdleTimeoutTest(unittest.TestCase):
    def setUp(self):
        module = f
        Fixture = module.AccountingTest
        self.addCleanup(Fixture.doClassCleanups)
        Fixture.setUpClass()

    def test_idle_timeout(self):
        module = f
        Fixture = module.AccountingTest

        fixture_root = Fixture.db.parent.parent.resolve()
        temp_parent = Path(os.environ.get("HERMES_TEST_TMP") or tempfile.gettempdir()).resolve()
        self.assertEqual(fixture_root.parent, temp_parent)
        source = fixture_root / "functions.php"
        self.assertNotEqual(fixture_root, module.ROOT.resolve())
        self.assertFalse(source.is_symlink())
        self.assertEqual(source.resolve().parent, fixture_root)
        self.assertTrue((fixture_root / "sessions").is_dir())
        old = "$idleTimeout = 1800;"
        text = source.read_text(encoding="utf-8")
        self.assertEqual(text.count(old), 1)
        source.write_text(text.replace(old, "$idleTimeout = 3;", 1), encoding="utf-8")

        status, _, body = Fixture.request('/dashboard.php')
        self.assertEqual(status, 200)
        self.assertIn('<h1>儀表板</h1>', body)

        time.sleep(4.1)

        status, headers, _ = Fixture.request('/dashboard.php')
        self.assertEqual(status, 302)
        self.assertEqual(headers.get('Location', ''), './login.php')


if __name__ == '__main__':
    unittest.main()