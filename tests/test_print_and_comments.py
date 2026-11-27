"""Local HTTP regressions; run with PHP_BIN=/path/to/php python tests/test_print_and_comments.py."""
import csv
import html
import io
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request
from http.cookiejar import CookieJar


ROOT = Path(__file__).resolve().parents[1]


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class AccountingTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        php = os.environ.get('PHP_BIN') or shutil.which('php')
        if not php:
            raise RuntimeError('Set PHP_BIN to a PHP 8.1+ executable with pdo_sqlite')
        tmp = tempfile.TemporaryDirectory(dir=os.environ.get('HERMES_TEST_TMP') or None)
        cls.addClassCleanup(tmp.cleanup)
        root = Path(tmp.name)
        for f in ROOT.glob('*.php'):
            shutil.copy(f, root)
        shutil.copytree(ROOT / 'assets', root / 'assets')
        (root / 'private_data').mkdir()
        (root / 'sessions').mkdir()
        # Preserve production HTTPS settings; only the isolated fixture uses HTTP cookies.
        config = root / 'config.php'
        source = config.read_text(encoding='utf-8')
        assert source.count("ini_set('session.cookie_secure', '1');") == 1
        config.write_text(source.replace("ini_set('session.cookie_secure', '1');", "ini_set('session.cookie_secure', '0');"), encoding='utf-8')
        password = secrets.token_urlsafe(28)
        (root / '.env').write_text(f'ADMIN_USERNAME=fixture_admin\nADMIN_PASSWORD={password}\nGEMINI_API_KEY=\n', encoding='utf-8')
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        cls.base = f'http://127.0.0.1:{port}'
        cls.opener = urllib.request.build_opener(NoRedirect(), urllib.request.HTTPCookieProcessor(CookieJar()))
        env = {k: v for k, v in os.environ.items() if k not in {'ADMIN_USERNAME', 'ADMIN_PASSWORD', 'GEMINI_API_KEY'}}
        proc = subprocess.Popen([php, '-d', f'session.save_path={root / "sessions"}', '-S', f'127.0.0.1:{port}'], cwd=root, env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        cls.addClassCleanup(cls.stop, proc)
        for _ in range(40):
            try:
                status, _, body = cls.request('/login.php')
                break
            except urllib.error.URLError:
                if proc.poll() is not None:
                    raise RuntimeError('PHP test server exited during startup')
                time.sleep(.1)
        else:
            raise RuntimeError('PHP test server did not start')
        assert status == 200
        csrf = cls.token(body)
        status, headers, _ = cls.request('/login.php', {'username': 'fixture_admin', 'password': password, 'csrf_token': csrf})
        assert status == 302 and headers.get('Location', '').endswith('/dashboard.php'), 'fixture login failed'
        cls.db = root / 'private_data' / 'cookies_xxxx.db'
        with cls.connect() as db:
            host = db.execute('SELECT id FROM users WHERE username=?', ('fixture_admin',)).fetchone()[0]
            db.execute('UPDATE ledgers SET name=? WHERE id=1 AND owner_id=?', ('測試 & 家庭', host))
            db.executemany('INSERT INTO entries(id,ledger_id,user_id,amount_cents,type,category,entry_date,note,currency,exchange_rate,original_amount_cents) VALUES(?,1,?,?,?,?,?,?,?,?,?)', [
                (1, host, 32500, 'expense', '餐費', '2025-02-17', '本月標記', 'USD', 325000, 1000),
                (2, host, 100, 'income', '其他', '2025-03-01', '次月標記', 'TWD', 10000, 100),
            ])
        cls.csrf = cls.token(cls.request('/ledger.php?ledger_id=1&ym=2025-02')[2])

    @staticmethod
    def stop(proc):
        proc.terminate()
        proc.wait(timeout=10)

    @classmethod
    def request(cls, path, fields=None):
        data = urllib.parse.urlencode(fields).encode() if fields is not None else None
        try:
            response = cls.opener.open(urllib.request.Request(cls.base + path, data=data), timeout=5)
        except urllib.error.HTTPError as exc:
            response = exc
        with response:
            return response.status, response.headers, response.read().decode('utf-8')

    @staticmethod
    def token(body):
        match = re.search(r'name="csrf_token"\s+value="([^"]+)"', body)
        if match is None:
            raise AssertionError('Missing CSRF form field')
        return match.group(1)

    @classmethod
    def connect(cls):
        from contextlib import closing, contextmanager

        @contextmanager
        def connection():
            with closing(sqlite3.connect(cls.db)) as db, db:
                yield db

        return connection()

    def test_statement_and_voucher_are_html_with_csp_compatible_print(self):
        for query in ('start_date=2025-02-01&end_date=2025-02-28', 'entry=1'):
            with self.subTest(query=query):
                status, headers, body = self.request('/export_pdf.php?ledger_id=1&' + query)
                self.assertEqual(status, 200)
                self.assertEqual(headers.get_content_type(), 'text/html')
                self.assertIsNone(headers.get('Content-Disposition'))
                self.assertIn("script-src 'self';", headers['Content-Security-Policy'])
                self.assertIn('data-print-page', body)
                self.assertNotIn('onclick="window.print()', body)
                self.assertIn('本月標記', body)
                self.assertNotIn('次月標記', body)
                self.assertIn('測試 &amp; 家庭', body)
                self.assertNotIn('測試 &amp;amp; 家庭', body)
                for field in ('原幣別', '原幣金額', '匯率', 'USD', '325.00'):
                    self.assertIn(field, body)
                self.assertNotRegex(body, '匯出 PDF|重試 PDF')

    def test_statement_dates_empty_and_invalid(self):
        base = '/export_pdf.php?ledger_id=1&'
        for query, text in (
            ('start_date=2025-04-01&end_date=2025-04-30', '該期間無記錄'),
            ('start_date=2025-02-01&end_date=2025-01-01', '結束日期不得早於開始日期'),
            ('start_date=2025-02-30', '開始日期格式不正確'),
            ('start_date%5B%5D=2025-02-01', '開始日期格式不正確'),
        ):
            with self.subTest(query=query):
                status, _, body = self.request(base + query)
                self.assertEqual(status, 200)
                self.assertIn(text, body)
        self.assertEqual(self.request('/export_pdf.php?ledger_id=1&entry=bad')[0], 404)

    def test_print_link_uses_the_ledger_view_month(self):
        _, _, body = self.request('/ledger.php?ledger_id=1&ym=2025-02')
        match = re.search(r'<a href="([^"]+)"[^>]*>[^<]*列印月結單</a>', body)
        self.assertIsNotNone(match)
        url = urllib.parse.urlsplit(html.unescape(match.group(1)))
        self.assertEqual(urllib.parse.parse_qs(url.query).get('ym'), ['2025-02'])
        _, _, statement = self.request('/' + url.path.removeprefix('./') + '?' + url.query)
        self.assertIn('本月標記', statement)
        self.assertNotIn('次月標記', statement)

    def test_old_statement_routes_enforce_the_current_policy(self):
        for file in ('pdf_export_empty.php', 'pdf_export_error.php'):
            with self.subTest(file=file):
                status, headers, _ = self.request('/' + file + '?ledger_id=1&ym=2025-02')
                self.assertEqual(status, 302)
                self.assertIn('export_pdf.php?', headers['Location'])
        with self.connect() as db:
            db.execute('UPDATE ledgers SET archived=1 WHERE id=1')
        try:
            status, headers, body = self.request('/export_pdf.php?ledger_id=1&entry=1')
            self.assertEqual(status, 302)
            self.assertIn('ledgers.php', headers['Location'])
            self.assertNotIn('本月標記', body)
        finally:
            with self.connect() as db:
                db.execute('UPDATE ledgers SET archived=0 WHERE id=1')

    def test_preview_audit_only_records_authorized_results(self):
        with self.connect() as db:
            before = db.execute('SELECT COALESCE(MAX(id), 0) FROM audit_log').fetchone()[0]
        base = '/export_pdf.php?ledger_id=1&'
        self.assertEqual(self.request(base + 'entry=1')[0], 200)
        self.assertEqual(self.request(base + 'entry=99999')[0], 404)
        self.assertEqual(self.request(base + 'ym=2025-02')[0], 200)
        self.assertEqual(self.request(base + 'ym=2025-13')[0], 200)
        self.assertEqual(self.request('/export_pdf.php?ledger_id=99999&entry=1')[0], 302)
        with self.connect() as db:
            rows = db.execute('SELECT action, detail FROM audit_log WHERE id>? ORDER BY id', (before,)).fetchall()
        self.assertEqual([(action, json.loads(detail)) for action, detail in rows], [
            ('voucher_print_view', {'ledger_id': 1, 'entry_id': 1}),
            ('statement_print_view', {'ledger_id': 1, 'start_date': '2025-02-01', 'end_date': '2025-02-28'}),
        ])

    def test_csv_reports_the_requested_month(self):
        status, _, body = self.request('/export_csv.php?ledger_id=1&ym=2025-02')
        self.assertEqual(status, 200)
        self.assertTrue(body.startswith('\ufeff'))
        rows = list(csv.reader(io.StringIO(body[1:])))
        self.assertEqual(len(rows), 2)
        self.assertIn('本月標記', rows[1])

    def test_add_comment_persists_and_renders(self):
        fields = {'csrf_token': self.csrf, 'ledger_id': '1', 'entry_id': '1', 'ym': '2025-02', 'comment': '確認這筆支出 & 備註'}
        status, headers, _ = self.request('/entry_comment.php', fields)
        self.assertEqual(status, 302)
        self.assertIn('success=1', headers['Location'])
        with self.connect() as db:
            self.assertEqual(db.execute('SELECT comment FROM entry_comments WHERE entry_id=1').fetchall(), [(fields['comment'],)])
        _, _, body = self.request('/ledger.php?ledger_id=1&ym=2025-02')
        self.assertIn('確認這筆支出 &amp; 備註', body)


if __name__ == '__main__':
    unittest.main(verbosity=2)
