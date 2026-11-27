"""Cross-ledger summary HTTP test."""
import unittest
from datetime import datetime, timezone, timedelta
import test_print_and_comments as fixture


class CrossLedgerSummaryCase(unittest.TestCase):
    def setUp(self):
        Fixture = fixture.AccountingTest
        self.addCleanup(Fixture.doClassCleanups)
        Fixture.setUpClass()

        # Taipei current date
        self.now = datetime.now(timezone(timedelta(hours=8))).date()
        self.today_str = self.now.isoformat()

        # Existing ledger 1 gives fixture admin
        with fixture.AccountingTest.connect() as db:
            self.admin_id = db.execute('SELECT user_id FROM entries WHERE id=1').fetchone()[0]

        # Insert one active ledger and one archived ledger
        with fixture.AccountingTest.connect() as db:
            # Active ledger
            db.execute('INSERT INTO ledgers(name,owner_id,archived) VALUES(?,?,0)', ('跨月總帳', self.admin_id))
            self.active_ledger_id = db.execute('SELECT last_insert_rowid()').fetchone()[0]
            db.execute('INSERT INTO ledger_access(ledger_id,user_id,role) VALUES(?,?,?)',
                       (self.active_ledger_id, self.admin_id, 'owner'))

            # Archived ledger
            db.execute('INSERT INTO ledgers(name,owner_id,archived) VALUES(?,?,1)', ('舊帳戶', self.admin_id))
            self.archived_ledger_id = db.execute('SELECT last_insert_rowid()').fetchone()[0]
            db.execute('INSERT INTO ledger_access(ledger_id,user_id,role) VALUES(?,?,?)',
                       (self.archived_ledger_id, self.admin_id, 'owner'))

        # Seed active new income: 12000 cents
        with fixture.AccountingTest.connect() as db:
            db.execute('INSERT INTO entries(ledger_id,user_id,amount_cents,type,category,entry_date,note) VALUES(?,?,?,?,?,?,?)',
                       (self.active_ledger_id, self.admin_id, 12000, 'income', '薪資', self.today_str, '活帳收入'))

        # Seed archived income: 42000 cents
        with fixture.AccountingTest.connect() as db:
            db.execute('INSERT INTO entries(ledger_id,user_id,amount_cents,type,category,entry_date,note) VALUES(?,?,?,?,?,?,?)',
                       (self.archived_ledger_id, self.admin_id, 42000, 'income', '薪資', self.today_str, '歸檔收入'))

        # Seed ledger 1 current month: 3000 cents expense and 88888 cents transfer
        with fixture.AccountingTest.connect() as db:
            db.execute('INSERT INTO entries(ledger_id,user_id,amount_cents,type,category,entry_date,note) VALUES(?,?,?,?,?,?,?)',
                       (1, self.admin_id, 3000, 'expense', '交通', self.today_str, '本月支出'))
            db.execute('INSERT INTO entries(ledger_id,user_id,amount_cents,type,category,entry_date,note) VALUES(?,?,?,?,?,?,?)',
                       (1, self.admin_id, 88888, 'transfer', '轉帳', self.today_str, '本月轉帳'))

    def test_cross_ledger_summary(self):
        status, _, page = fixture.AccountingTest.request('/dashboard.php')
        self.assertEqual(status, 200, 'dashboard should return 200')

        # Displayed amounts are dollars (cents/100), not raw cents:
        #   income 12000¢ → +120.00, expense 3000¢ → -30.00, balance 90.00
        pairs = [
            ('全家本月總收入', '+120.00'),
            ('全家本月總支出', '-30.00'),
            ('全家本月總結餘', '+90.00'),
        ]
        for label, amount in pairs:
            expected_substring = f'<small>{label}</small><div class="v">{amount}<small> 元</small>'
            self.assertTrue(expected_substring in page, f'{label} {amount} mismatch')

        # Assert archived name absent
        self.assertNotIn('舊帳戶', page, 'archived ledger name should not appear on dashboard')


if __name__ == '__main__':
    unittest.main(verbosity=2)