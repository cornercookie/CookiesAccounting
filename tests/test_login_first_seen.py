"""登入來源首次出現旗標與提醒的 HTTP 回歸測試。"""
import unittest
import urllib.error
import urllib.parse
import urllib.request
from http.cookiejar import CookieJar

import test_print_and_comments as f


class TestLoginFirstSeen(unittest.TestCase):
    def setUp(self):
        self.addCleanup(f.AccountingTest.doClassCleanups)
        f.AccountingTest.setUpClass()
        self.fixture = f.AccountingTest

        env = {}
        env_path = self.fixture.db.parent.parent / '.env'
        for line in env_path.read_text(encoding='utf-8').splitlines():
            key, separator, value = line.partition('=')
            if separator:
                env[key] = value
        self.username = env['ADMIN_USERNAME']
        self.password = env['ADMIN_PASSWORD']

        # 清除 fixture 初次登入的來源紀錄，讓三次登入從空白歷史開始。
        with self.fixture.connect() as db:
            self.user_id = db.execute(
                'SELECT id FROM users WHERE username=?', (self.username,)
            ).fetchone()[0]
            db.execute('DELETE FROM login_sessions WHERE user_id=?', (self.user_id,))

    def request(self, opener, path, user_agent, fields=None):
        data = urllib.parse.urlencode(fields).encode() if fields is not None else None
        request = urllib.request.Request(
            self.fixture.base + path,
            data=data,
            headers={'User-Agent': user_agent},
        )
        try:
            response = opener.open(request, timeout=5)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.status, response.headers, response.read().decode('utf-8')

    def login(self, user_agent):
        opener = urllib.request.build_opener(
            f.NoRedirect(), urllib.request.HTTPCookieProcessor(CookieJar())
        )
        status, _, body = self.request(opener, '/login.php', user_agent)
        self.assertEqual(status, 200)
        csrf = self.fixture.token(body)

        status, headers, _ = self.request(opener, '/login.php', user_agent, {
            'username': self.username,
            'password': self.password,
            'csrf_token': csrf,
        })
        self.assertEqual(status, 302)
        self.assertTrue(headers.get('Location', '').endswith('/dashboard.php'))

        status, _, body = self.request(opener, '/dashboard.php', user_agent)
        self.assertEqual(status, 200)
        return body

    def newest_first_seen(self):
        with self.fixture.connect() as db:
            row = db.execute(
                'SELECT is_first_seen FROM login_sessions '
                'WHERE user_id=? ORDER BY id DESC LIMIT 1',
                (self.user_id,),
            ).fetchone()
        self.assertIsNotNone(row)
        return row[0]

    def test_first_seen_flags_and_notices(self):
        notice = '偵測到新登入來源：'

        body = self.login('UA-A')
        self.assertEqual(self.newest_first_seen(), 1)
        self.assertIn(notice, body)

        body = self.login('UA-A')
        self.assertEqual(self.newest_first_seen(), 0)
        self.assertNotIn(notice, body)

        body = self.login('UA-B')
        self.assertEqual(self.newest_first_seen(), 1)
        self.assertIn(notice, body)

        with self.fixture.connect() as db:
            count = db.execute(
                'SELECT COUNT(*) FROM login_sessions WHERE user_id=?',
                (self.user_id,),
            ).fetchone()[0]
        self.assertEqual(count, 3)


if __name__ == '__main__':
    unittest.main(verbosity=2)
