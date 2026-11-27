"""Session reset regressions; run with PHP_BIN."""
import unittest
import test_print_and_comments as f
import shutil, time, urllib.error, urllib.parse, urllib.request
from http.cookiejar import CookieJar
import secrets




class TestSessionReset(unittest.TestCase):
    def setUp(self):
        self.addCleanup(f.AccountingTest.doClassCleanups)
        f.AccountingTest.setUpClass()
        d = f.AccountingTest.db.parent.parent / "admin"
        d.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(f.ROOT / "admin" / "users.php", d / "users.php")

    def member_request(self, opener, path, fields=None):
        d = urllib.parse.urlencode(fields).encode() if fields is not None else None
        try:
            r = opener.open(urllib.request.Request(f.AccountingTest.base + path, data=d), timeout=5)
        except urllib.error.HTTPError as error:
            r = error
        with r:
            return r.status, r.headers, r.read().decode('utf-8')

    def test_full_session_reset_lifecycle(self):
        # Create synthetic role member
        csrf = f.AccountingTest.csrf
        pw = "QA-Session1!" + secrets.token_urlsafe(12)
        s, h, _ = f.AccountingTest.request('/admin/users.php', {'action': 'create_user', 'username': 'qa_session_member', 'password': pw, 'role': 'member', 'csrf_token': csrf})
        self.assertEqual(s, 302)
        self.assertIn('success=1', h['Location'])

        with f.AccountingTest.connect() as db:
            tid = db.execute('SELECT id FROM users WHERE username=?', ('qa_session_member',)).fetchone()[0]

        o = urllib.request.build_opener(f.NoRedirect(), urllib.request.HTTPCookieProcessor(CookieJar()))
        s, h, b = self.member_request(o, '/login.php')
        csrf = f.AccountingTest.token(b)
        s, h, _ = self.member_request(o, '/login.php', {'username': 'qa_session_member', 'password': pw, 'csrf_token': csrf})
        self.assertEqual(s, 302)
        self.assertTrue(h.get("Location", "").endswith("/dashboard.php"))
        s, h, b = self.member_request(o, '/dashboard.php')
        self.assertEqual(s, 200)
        self.assertTrue('<h1>儀表板</h1>' in b, 'Missing dashboard heading')
        self.assertTrue('qa_session_member' in b, 'Missing qa_session_member in dashboard')
        time.sleep(1.2)
        npw = "QA-Session1!" + secrets.token_urlsafe(12)
        s, h, _ = f.AccountingTest.request('/admin/users.php', {'action': 'reset_password', 'target_user_id': str(tid), 'password': npw, 'csrf_token': f.AccountingTest.csrf})
        self.assertEqual(s, 302)
        self.assertIn('success=1', h['Location'])
        s, h, _ = self.member_request(o, '/dashboard.php')
        self.assertEqual(s, 302)
        self.assertTrue(h.get('Location', '').endswith('/login.php'))
        fo = urllib.request.build_opener(f.NoRedirect(), urllib.request.HTTPCookieProcessor(CookieJar()))
        s, h, b = self.member_request(fo, '/login.php')
        csrf = f.AccountingTest.token(b)
        s, h, _ = self.member_request(fo, '/login.php', {'username': 'qa_session_member', 'password': npw, 'csrf_token': csrf})
        self.assertEqual(s, 302)
        self.assertTrue(h.get("Location", "").endswith("/dashboard.php"))
        s, h, b = self.member_request(fo, '/dashboard.php')
        self.assertEqual(s, 200)
        self.assertTrue('<h1>儀表板</h1>' in b, 'Missing dashboard heading')
        self.assertTrue('qa_session_member' in b, 'Missing qa_session_member in dashboard')


if __name__ == "__main__":
    unittest.main()