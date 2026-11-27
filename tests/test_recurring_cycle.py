"""Recurring monthly-cycle and duplicate-guard regressions; run with PHP_BIN."""
import calendar
from datetime import datetime, timedelta, timezone
import unittest
from urllib.parse import unquote_plus

import test_print_and_comments as fixture


class TestRecurringCycle(unittest.TestCase):
    def setUp(self):
        F = fixture.AccountingTest
        self.addCleanup(F.doClassCleanups)
        F.setUpClass()

    def test_cycle_duplicate_guard_and_voided_regeneration(self):
        F = fixture.AccountingTest
        today = datetime.now(timezone(timedelta(hours=8))).date()
        current_month = today.replace(day=1)
        previous_month = current_month - timedelta(days=1)
        past_due = previous_month.replace(day=min(31, previous_month.day))

        def expected_next(current_due):
            year, month = current_due.year, current_due.month
            while True:
                if month == 12:
                    year += 1
                    month = 1
                else:
                    month += 1
                day = min(31, calendar.monthrange(year, month)[1])
                candidate = today.replace(year=year, month=month, day=day)
                if candidate > today:
                    return candidate

        with F.connect() as db:
            uid = db.execute('SELECT user_id FROM entries WHERE id=1').fetchone()[0]
            sql = (
                'INSERT INTO recurring_entries'
                '(ledger_id,user_id,name,amount_cents,type,category,start_date,next_due_date,is_active) '
                'VALUES(1,?,?,3250,\'expense\',\'交通\',\'2026-01-31\',?,1)'
            )
            current_rid = db.execute(sql, (uid, 'QA-Recurring-Current', current_month.isoformat())).lastrowid
            past_rid = db.execute(sql, (uid, 'QA-Recurring-Past', past_due.isoformat())).lastrowid

        def generate(rid):
            return F.request('/ledger.php?ledger_id=1', {
                'csrf_token': F.csrf, 'action': 'generate_recurring', 'recurring_id': rid,
            })

        status, headers, _ = generate(current_rid)
        self.assertEqual(status, 302)
        self.assertIn('success=1', headers['Location'])
        with F.connect() as db:
            self.assertEqual(db.execute(
                'SELECT COUNT(*) FROM entries WHERE recurring_source_id=? AND deleted=0',
                (current_rid,),
            ).fetchone()[0], 1)
            next_due = db.execute(
                'SELECT next_due_date FROM recurring_entries WHERE id=?', (current_rid,),
            ).fetchone()[0]
        expected = expected_next(current_month)
        self.assertEqual(next_due, expected.isoformat())
        self.assertGreater(next_due, today.isoformat())
        self.assertEqual(int(next_due[-2:]), min(31, calendar.monthrange(expected.year, expected.month)[1]))
        self.assertIn('下次預計日期：' + next_due, unquote_plus(headers['Location']))

        status, headers, _ = generate(current_rid)
        self.assertEqual(status, 302)
        self.assertIn('error=1', headers['Location'])
        self.assertIn('本月已產生過此重複記錄。', unquote_plus(headers['Location']))
        with F.connect() as db:
            self.assertEqual(db.execute(
                'SELECT COUNT(*) FROM entries WHERE recurring_source_id=? AND deleted=0',
                (current_rid,),
            ).fetchone()[0], 1)
            db.execute('UPDATE entries SET deleted=1 WHERE recurring_source_id=?', (current_rid,))

        status, headers, _ = generate(current_rid)
        self.assertEqual(status, 302)
        self.assertIn('success=1', headers['Location'])
        with F.connect() as db:
            self.assertEqual(db.execute(
                'SELECT COUNT(*) FROM entries WHERE recurring_source_id=?', (current_rid,),
            ).fetchone()[0], 2)
            self.assertEqual(db.execute(
                'SELECT COUNT(*) FROM entries WHERE recurring_source_id=? AND deleted=0',
                (current_rid,),
            ).fetchone()[0], 1)

        status, headers, _ = generate(past_rid)
        self.assertEqual(status, 302)
        self.assertIn('success=1', headers['Location'])
        with F.connect() as db:
            past_next_due = db.execute(
                'SELECT next_due_date FROM recurring_entries WHERE id=?', (past_rid,),
            ).fetchone()[0]
        past_expected = expected_next(past_due)
        self.assertEqual(past_next_due, past_expected.isoformat())
        self.assertGreater(past_next_due, today.isoformat())
        self.assertEqual(int(past_next_due[-2:]), min(31, calendar.monthrange(past_expected.year, past_expected.month)[1]))


if __name__ == '__main__':
    unittest.main()
