"""Logout CSRF, signed notices, and ledger archive audit regressions."""
import unittest
from urllib.parse import parse_qs, urlsplit

import test_print_and_comments as f


class TestLogoutNoticeAudit(unittest.TestCase):
    def setUp(self):
        Fixture = f.AccountingTest
        self.addCleanup(Fixture.doClassCleanups)
        Fixture.setUpClass()
        self.Fixture = Fixture

    @staticmethod
    def request_location(location):
        parsed = urlsplit(location)
        path = '/' + parsed.path.removeprefix('./')
        if parsed.query:
            path += '?' + parsed.query
        return path

    def test_a_get_logout_shows_confirmation_without_logging_out(self):
        status, _, body = self.Fixture.request('/logout.php')
        self.assertEqual(status, 200)
        self.assertIn('確定要登出嗎？', body)
        self.assertIn('name="csrf_token"', body)
        self.assertEqual(self.Fixture.request('/dashboard.php')[0], 200)

    def test_b_post_logout_with_wrong_token_keeps_session(self):
        status, headers, _ = self.Fixture.request('/logout.php', {'csrf_token': 'wrong-token'})
        self.assertEqual(status, 302)
        self.assertIn('logout.php', headers['Location'])
        self.assertEqual(self.Fixture.request('/dashboard.php')[0], 200)

    def test_c_notice_requires_a_valid_redirect_signature(self):
        _, _, body = self.Fixture.request('/ledger.php?ledger_id=1&error=1&msg=FORGED_NOTICE_XYZ')
        self.assertNotIn('FORGED_NOTICE_XYZ', body)

        status, headers, _ = self.Fixture.request('/ledger.php?ledger_id=1', {
            'action': 'generate_recurring',
            'recurring_id': 'invalid',
            'csrf_token': self.Fixture.csrf,
        })
        self.assertEqual(status, 302)
        location = headers['Location']
        query = parse_qs(urlsplit(location).query)
        self.assertIn('msg', query)
        self.assertIn('msg_sig', query)

        status, _, body = self.Fixture.request(self.request_location(location))
        self.assertEqual(status, 200)
        self.assertIn(query['msg'][0], body)

    def test_d_archive_writes_ledger_archive_audit(self):
        with self.Fixture.connect() as db:
            uid = db.execute('SELECT user_id FROM entries WHERE id=1').fetchone()[0]
            ledger_id = db.execute(
                'INSERT INTO ledgers(name,owner_id) VALUES(?,?)',
                ('封存審計測試', uid),
            ).lastrowid
            db.execute(
                "INSERT INTO ledger_access(ledger_id,user_id,role) VALUES(?,?,'owner')",
                (ledger_id, uid),
            )

        status, headers, _ = self.Fixture.request('/ledgers.php', {
            'action': 'archive_ledger',
            'ledger_id': str(ledger_id),
            'csrf_token': self.Fixture.csrf,
        })
        self.assertEqual(status, 302)
        self.assertIn('success=1', headers['Location'])
        with self.Fixture.connect() as db:
            row = db.execute(
                "SELECT detail FROM audit_log WHERE action='ledger_archive' ORDER BY id DESC LIMIT 1"
            ).fetchone()
        self.assertIsNotNone(row)
        self.assertEqual(row[0], f'ledger_id={ledger_id}')

    def test_z_post_logout_with_valid_token(self):
        _, _, body = self.Fixture.request('/logout.php')
        csrf = self.Fixture.token(body)
        status, headers, _ = self.Fixture.request('/logout.php', {'csrf_token': csrf})
        self.assertEqual(status, 302)
        self.assertIn('login.php', headers['Location'])

        status, headers, _ = self.Fixture.request('/dashboard.php')
        self.assertEqual(status, 302)
        self.assertIn('login.php', headers['Location'])


if __name__ == '__main__':
    unittest.main(verbosity=2)
