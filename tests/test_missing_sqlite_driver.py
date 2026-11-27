"""Isolated HTTP test for the missing pdo_sqlite prerequisite."""
from contextlib import ExitStack
from http.cookiejar import CookieJar
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import unittest
from urllib.error import URLError
from urllib.request import build_opener, HTTPCookieProcessor

import test_print_and_comments as fixture

MESSAGE = '資料庫功能無法使用。請在 cPanel 啟用 PHP 的 pdo_sqlite 擴充後重試。'


class MissingSqliteDriverTest(unittest.TestCase):
    def test_missing_sqlite_driver(self):
        class A(fixture.AccountingTest):
            base = ""
            opener = build_opener()

        env = {k: v for k, v in os.environ.items() if k not in {'ADMIN_USERNAME', 'ADMIN_PASSWORD', 'GEMINI_API_KEY'}}
        php = env.get('PHP_BIN') or shutil.which('php')
        if php is None:
            self.fail('PHP_BIN is not set and php was not found in PATH')
        env['PHP_BIN'] = php

        probe = subprocess.run(
            [php, '-n', '-r', 'echo extension_loaded("pdo_sqlite") ? "built-in" : "missing";'],
            capture_output=True, text=True, check=True,
        )
        if probe.stdout.strip() == 'built-in':
            self.skipTest('PHP -n has a built-in pdo_sqlite extension')
        self.assertEqual(probe.stdout.strip(), "missing")

        with ExitStack() as stack:
            root = Path(stack.enter_context(tempfile.TemporaryDirectory(dir=env.get('HERMES_TEST_TMP'))))
            for source in fixture.ROOT.glob('*.php'):
                shutil.copy2(source, root)
            (root / 'sessions').mkdir()
            (root / 'private_data').mkdir()
            cfg = root / 'config.php'
            original = cfg.read_text(encoding='utf-8')
            secure = "ini_set('session.cookie_secure', '1');"
            assert original.count(secure) == 1
            cfg.write_text(original.replace(secure, "ini_set('session.cookie_secure', '0');"), encoding='utf-8')
            with socket.socket() as sock:
                sock.bind(('127.0.0.1', 0))
                port = sock.getsockname()[1]
            proc = subprocess.Popen([php, '-n', '-d', 'display_errors=0', '-d', f'session.save_path={root / "sessions"}',
                                     '-S', f'127.0.0.1:{port}'], cwd=root, env=env,
                                    stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            stack.callback(A.stop, proc)

            local_base = A.base
            local_opener = A.opener
            try:
                A.base = f'http://127.0.0.1:{port}'
                A.opener = build_opener(fixture.NoRedirect(), HTTPCookieProcessor(CookieJar()))
                for _ in range(35):
                    try:
                        status, headers, _ = A.request('/index.php')
                        break
                    except URLError:
                        if proc.poll() is not None:
                            raise RuntimeError('isolated PHP exited during startup')
                        time.sleep(.1)
                else:
                    raise RuntimeError('isolated PHP is unavailable')
                assert (status, headers['Location']) == (302, './login.php')
                for fields in (None, {'username': 'fixture_only', 'password': 'synthetic-value', 'csrf_token': 'placeholder'}):
                    method = 'GET' if fields is None else 'POST'
                    status, headers, body = A.request('/login.php', fields)
                    assert status == 503, f'{method}: expected 503, got {status}'
                    assert headers.get('Cache-Control') == 'no-store', method
                    assert headers.get_content_type() == 'text/html'
                    assert MESSAGE in body and 'role="alert"' in body, method
                    assert '<form' not in body and 'Stack trace' not in body and 'Fatal error' not in body and str(root) not in body, method
                    assert headers.get('Location') is None
            finally:
                A.base = local_base
                A.opener = local_opener

            assert not list((root / 'private_data').glob('*.db*'))


if __name__ == '__main__':
    unittest.main()
