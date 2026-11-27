"""Budget role regressions; run with PHP_BIN."""
import unittest
import test_print_and_comments as f
import shutil, time, urllib.error, urllib.parse, urllib.request
from http.cookiejar import CookieJar
import secrets




class TestBudgetRoles(unittest.TestCase):
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

    def test_budget_roles_are_read_only_for_non_owners(self):
        with f.AccountingTest.connect() as db:
            db.execute("INSERT INTO categories(name,is_active) VALUES(?,1)", ("QA Read Only Budget",))
            budget_id = db.execute('INSERT INTO category_budgets(ledger_id, category, monthly_budget_cents, is_active) VALUES(?,?,?,?)', (1, 'QA Read Only Budget', 32100, 1)).lastrowid
        for role in ('member', 'viewer'):
            with self.subTest(role=role):
                username = 'qa_budget_' + role
                pw = "QA-Budget1!" + secrets.token_urlsafe(12)
                s, h, _ = f.AccountingTest.request('/admin/users.php', {'action': 'create_user', 'username': username, 'password': pw, 'role': 'member', 'csrf_token': f.AccountingTest.csrf})
                self.assertEqual(s, 302)
                self.assertIn('success=1', h['Location'])
                with f.AccountingTest.connect() as db:
                    uid = db.execute('SELECT id FROM users WHERE username=?', (username,)).fetchone()[0]
                    db.execute('INSERT INTO ledger_access(user_id, ledger_id, role) VALUES(?,?,?)', (uid, 1, role))
                o = urllib.request.build_opener(f.NoRedirect(), urllib.request.HTTPCookieProcessor(CookieJar()))
                s, h, b = self.member_request(o, '/login.php')
                csrf = f.AccountingTest.token(b)
                s, h, _ = self.member_request(o, '/login.php', {'username': username, 'password': pw, 'csrf_token': csrf})
                self.assertEqual(s, 302)
                self.assertTrue(h.get('Location', '').endswith('/dashboard.php'))
                s, h, b = self.member_request(o, '/category_budget.php?ledger_id=1')
                self.assertEqual(s, 200)
                self.assertIn('QA Read Only Budget', b)
                self.assertIn('321.00', b)
                self.assertNotIn("name=\"action\" value=", b)
                self.assertNotIn("edit_id=", b)
                s, h, b = self.member_request(o, '/change_password.php')
                csrf = f.AccountingTest.token(b)
                for action in ('create', 'update', 'disable'):
                    s, h, _ = self.member_request(o, '/category_budget.php?ledger_id=1', {'action': action, 'ledger_id': '1', 'category': 'QA Read Only Budget', 'monthly_budget': '999.00', 'id': budget_id, 'csrf_token': csrf})
                    self.assertEqual(s, 302)
                    self.assertIn('category_budget.php?ledger_id=1&error=1', h['Location'])
                    msg = urllib.parse.parse_qs(urllib.parse.urlsplit(h['Location']).query, keep_blank_values=True)['msg']
                    self.assertEqual(msg, ['僅帳本擁有者可管理類別預算。'])
                with f.AccountingTest.connect() as db:
                    self.assertEqual(db.execute('SELECT monthly_budget_cents, is_active FROM category_budgets WHERE ledger_id=1').fetchall(), [(32100, 1)])


if __name__ == "__main__":
    unittest.main()