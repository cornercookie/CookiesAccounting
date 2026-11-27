"""Session role change regressions; run with PHP_BIN."""
import unittest
import test_print_and_comments as f
import shutil, time, urllib.error, urllib.parse, urllib.request
from http.cookiejar import CookieJar
import secrets




class TestSessionRoleChange(unittest.TestCase):
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

    def test_role_demote_invalidates_old_session(self):
        # Create independent synthetic admin
        csrf = f.AccountingTest.csrf
        pw = "QA-Session1!" + secrets.token_urlsafe(12)
        s, h, _ = f.AccountingTest.request('/admin/users.php', {'action': 'create_user', 'username': 'qa_role_demote', 'password': pw, 'role': 'admin', 'csrf_token': csrf})
        self.assertEqual(s, 302)
        self.assertIn('success=1', h['Location'])

        with f.AccountingTest.connect() as db:
            tid = db.execute('SELECT id FROM users WHERE username=?', ('qa_role_demote',)).fetchone()[0]

        o = urllib.request.build_opener(f.NoRedirect(), urllib.request.HTTPCookieProcessor(CookieJar()))
        s, h, b = self.member_request(o, '/login.php')
        csrf = f.AccountingTest.token(b)
        s, h, _ = self.member_request(o, '/login.php', {'username': 'qa_role_demote', 'password': pw, 'csrf_token': csrf})
        self.assertEqual(s, 302)
        self.assertTrue(h.get("Location", "").endswith("/dashboard.php"))
        s, h, b = self.member_request(o, '/dashboard.php')
        self.assertEqual(s, 200)
        self.assertTrue('<h1>儀表板</h1>' in b, 'Missing dashboard heading')
        self.assertTrue('qa_role_demote' in b, 'Missing qa_role_demote in dashboard')
        s, h, b = self.member_request(o, '/admin/users.php')
        self.assertEqual(s, 200)
        self.assertTrue('使用者管理' in b, 'Missing 使用者管理 heading')
        time.sleep(1.2)
        s, h, _ = f.AccountingTest.request('/admin/users.php', {'action': 'change_role', 'target_user_id': str(tid), 'role': 'member', 'csrf_token': f.AccountingTest.csrf})
        self.assertEqual(s, 302)
        self.assertIn('success=1', h['Location'])
        with f.AccountingTest.connect() as db:
            role = db.execute('SELECT role FROM users WHERE id=?', (tid,)).fetchone()
        self.assertEqual(role, ('member',))
        s, h, _ = self.member_request(o, '/dashboard.php')
        self.assertEqual(s, 302)
        self.assertTrue(h.get('Location', '').endswith('/login.php'))
        fo = urllib.request.build_opener(f.NoRedirect(), urllib.request.HTTPCookieProcessor(CookieJar()))
        s, h, b = self.member_request(fo, '/login.php')
        csrf = f.AccountingTest.token(b)
        s, h, _ = self.member_request(fo, '/login.php', {'username': 'qa_role_demote', 'password': pw, 'csrf_token': csrf})
        self.assertEqual(s, 302)
        self.assertTrue(h.get("Location", "").endswith("/dashboard.php"))
        s, h, b = self.member_request(fo, '/dashboard.php')
        self.assertEqual(s, 200)
        self.assertTrue('<h1>儀表板</h1>' in b, 'Missing dashboard heading')
        self.assertTrue('qa_role_demote' in b, 'Missing qa_role_demote in dashboard')
        s, h, _ = self.member_request(fo, '/admin/users.php')
        self.assertEqual(s, 302)
        self.assertTrue(h.get('Location', '').endswith('/login.php'))


if __name__ == "__main__":
    unittest.main()