"""Schema-version gate and recurring reminder regressions; run with PHP_BIN."""
import re
import unittest
from datetime import datetime, timedelta, timezone

import test_print_and_comments as f


class TestSchemaVersion(unittest.TestCase):
    def setUp(self):
        Fixture = f.AccountingTest
        self.addCleanup(Fixture.doClassCleanups)
        Fixture.setUpClass()

    def test_schema_version_and_indexes_after_fixture_startup(self):
        with f.AccountingTest.connect() as db:
            self.assertEqual(db.execute('PRAGMA user_version').fetchone()[0], 34)
            indexes = {
                row[0]
                for row in db.execute(
                    "SELECT name FROM sqlite_master WHERE type='index' AND name IN (?,?)",
                    ('idx_entries_ledger_deleted_date', 'idx_entries_recurring_source_date'),
                )
            }
        self.assertEqual(indexes, {
            'idx_entries_ledger_deleted_date',
            'idx_entries_recurring_source_date',
        })

    def test_old_schema_version_reruns_migrations(self):
        with f.AccountingTest.connect() as db:
            db.execute('PRAGMA user_version = 0')
            db.execute('DROP INDEX idx_entries_recurring_source_date')

        status, _, _ = f.AccountingTest.request('/dashboard.php')
        self.assertEqual(status, 200)
        with f.AccountingTest.connect() as db:
            self.assertEqual(db.execute('PRAGMA user_version').fetchone()[0], 34)
            index = db.execute(
                "SELECT name FROM sqlite_master WHERE type='index' AND name=?",
                ('idx_entries_recurring_source_date',),
            ).fetchone()
        self.assertIsNotNone(index)

    def test_recurring_reminder_ignores_deleted_entries(self):
        name = '定期提醒測試３４'
        today = datetime.now(timezone(timedelta(hours=8))).date().isoformat()
        with f.AccountingTest.connect() as db:
            user_id = db.execute('SELECT owner_id FROM ledgers WHERE id=1').fetchone()[0]
            recurring_id = db.execute(
                'INSERT INTO recurring_entries'
                '(ledger_id,user_id,name,amount_cents,type,category,start_date,next_due_date,is_active) '
                'VALUES(1,?,?,?,?,?,?,?,1)',
                (user_id, name, 12500, 'expense', '其他', today, today),
            ).lastrowid
            db.execute(
                'INSERT INTO entries'
                '(ledger_id,user_id,amount_cents,type,category,entry_date,recurring_source_id,deleted) '
                'VALUES(1,?,?,?,?,?,?,1)',
                (user_id, 12500, 'expense', '其他', today, recurring_id),
            )

        status, _, body = f.AccountingTest.request('/dashboard.php')
        self.assertEqual(status, 200)
        reminder = re.search(r'<div class="alert warn" role="status">(.*?)</div>', body, re.S)
        self.assertIsNotNone(reminder)
        self.assertIn(name, reminder.group(1))

        with f.AccountingTest.connect() as db:
            db.execute('UPDATE entries SET deleted=0 WHERE recurring_source_id=?', (recurring_id,))
        status, _, body = f.AccountingTest.request('/dashboard.php')
        self.assertEqual(status, 200)
        reminder = re.search(r'<div class="alert warn" role="status">(.*?)</div>', body, re.S)
        if reminder is not None:
            self.assertNotIn(name, reminder.group(1))


if __name__ == '__main__':
    unittest.main(verbosity=2)
