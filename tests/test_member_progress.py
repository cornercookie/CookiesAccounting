"""Member progress HTTP test."""
import re
import unittest
from datetime import datetime, timezone, timedelta
import test_print_and_comments as fixture


class MemberProgressCase(unittest.TestCase):
    def setUp(self):
        Fixture = fixture.AccountingTest
        self.addCleanup(Fixture.doClassCleanups)
        Fixture.setUpClass()

        # Taipei current date (UTC+8)
        self.now = datetime.now(timezone(timedelta(hours=8))).date()
        self.today_str = self.now.isoformat()

        # Calculate prior month (first day of previous month)
        if self.now.month == 1:
            prior_month = datetime(self.now.year - 1, 12, 1).date()
        else:
            prior_month = datetime(self.now.year, self.now.month - 1, 1).date()
        self.prior_month_str = prior_month.isoformat()

        # Two synthetic users in disposable fixture ledger 1
        with fixture.AccountingTest.connect() as db:
            # User 1: has current-month expense (non-deleted) and one deleted expense
            db.execute(
                'INSERT INTO users(username,password_hash,role) VALUES(?,?,?)',
                ('u1', '!fixture-disabled', 'member'),
            )
            self.user1_id = db.execute('SELECT last_insert_rowid()').fetchone()[0]
            db.execute(
                'INSERT INTO ledger_access(ledger_id,user_id,role) VALUES(?,?,?)',
                (1, self.user1_id, 'member'),
            )

            # User 2: has only prior-month expense
            db.execute(
                'INSERT INTO users(username,password_hash,role) VALUES(?,?,?)',
                ('u2', '!fixture-disabled', 'member'),
            )
            self.user2_id = db.execute('SELECT last_insert_rowid()').fetchone()[0]
            db.execute(
                'INSERT INTO ledger_access(ledger_id,user_id,role) VALUES(?,?,?)',
                (1, self.user2_id, 'viewer'),
            )

        # Seed expenses
        with fixture.AccountingTest.connect() as db:
            # User 1: current Taipei date expense (not deleted) and deleted expense
            db.execute(
                'INSERT INTO entries(ledger_id,user_id,amount_cents,type,entry_date,deleted) VALUES(?,?,?,?,?,?)',
                (1, self.user1_id, 1000, 'expense', self.today_str, 0),
            )
            db.execute(
                'INSERT INTO entries(ledger_id,user_id,amount_cents,type,entry_date,deleted) VALUES(?,?,?,?,?,?)',
                (1, self.user1_id, 500, 'expense', self.today_str, 1),
            )

            # User 2: only prior-month expense
            db.execute(
                'INSERT INTO entries(ledger_id,user_id,amount_cents,type,entry_date,deleted) VALUES(?,?,?,?,?,?)',
                (1, self.user2_id, 2000, 'expense', self.prior_month_str, 0),
            )

    def test_member_progress(self):
        status, _, page = fixture.AccountingTest.request('/dashboard.php')
        self.assertEqual(status, 200, 'dashboard should return 200')

        # Scope to table after "本月各成員記帳進度"
        header = '本月各成員記帳進度'
        idx = page.find(header)
        self.assertNotEqual(idx, -1, 'Dashboard should contain "本月各成員記帳進度" heading')
        table_area = page[idx:].split("</table>", 1)[0]

        # Robust whitespace regex: match <tr> followed by whitespace, then <td> cells
        # Expected row shape: <tr> whitespace <td>帳本名</td> whitespace <td>username</td> whitespace <td class="num">count</td>
        # User 1 (u1) should have count=1 (current month non-deleted expense)
        user1_pattern = (
            r'<tr>\s*'
            r'<td[^>]*>.*?</td>\s*'          # first cell:帳本名 or content
            r'<td[^>]*>u1</td>\s*'           # second cell: username
            r'<td class="num">1</td>\s*'      # third cell: count=1
            r'</tr>'
        )
        self.assertTrue(re.search(user1_pattern, table_area) is not None,
                         'User 1 should have count cell "1" in progress table')

        # User 2 (u2) should have count=0 (only prior-month expense, not current month)
        user2_pattern = (
            r'<tr>\s*'
            r'<td[^>]*>.*?</td>\s*'
            r'<td[^>]*>u2</td>\s*'
            r'<td class="num">0</td>\s*'
            r'</tr>'
        )
        self.assertTrue(re.search(user2_pattern, table_area) is not None,
                         'User 2 should have count cell "0" in progress table')


if __name__ == '__main__':
    unittest.main(verbosity=2)