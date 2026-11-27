"""Recurring generation regressions; run with PHP_BIN."""
import unittest, test_print_and_comments as fixture


class TestRecurringGeneration(unittest.TestCase):
    def setUp(self):
        F = fixture.AccountingTest
        self.addCleanup(F.doClassCleanups)
        F.setUpClass()

    def test_create_and_generate_recurring(self):
        F = fixture.AccountingTest
        s, h, b = F.request('/ledger.php?ledger_id=1')
        csrf = F.token(b)
        s, h, b = F.request('/ledger.php?ledger_id=1',
            {'csrf_token': csrf, 'action': 'create_recurring',
             'name': 'QA-Recurring', 'amount': '32.50', 'type': 'expense',
             'category': '交通', 'start_date': '2025-02-01', 'next_due_date': '2025-03-01'})
        self.assertEqual(s, 302)
        self.assertIn('success=1', h['Location'])
        with F.connect() as db:
            r = db.execute(
                'SELECT id, amount_cents, next_due_date FROM recurring_entries '
                'WHERE ledger_id = 1 AND name = ?',
                ('QA-Recurring',),
            ).fetchone()
            self.assertIsNotNone(r)
            self.assertEqual(r[1], 3250)
            self.assertEqual(r[2], '2025-03-01')
            rid = r[0]
        s, h, b = F.request('/ledger.php?ledger_id=1',
            {'csrf_token': csrf, 'action': 'generate_recurring', 'recurring_id': rid})
        self.assertEqual(s, 302)
        self.assertIn('success=1', h['Location'])
        with F.connect() as db:
            rows = db.execute(
                'SELECT amount_cents, type, category, currency, exchange_rate, '
                   'original_amount_cents FROM entries '
                'WHERE recurring_source_id = ?',
                (rid,),
            ).fetchall()
            self.assertEqual(len(rows), 1)
            self.assertEqual(rows[0], (3250, 'expense', '交通', 'TWD', 10000, 3250))
        with F.connect() as db:
            a = db.execute(
                'SELECT action, detail FROM audit_log '
                'WHERE action = ? ORDER BY id DESC LIMIT 1',
                ('recurring_generate',),
            ).fetchone()
            self.assertIsNotNone(a)
            action, detail = a
            self.assertIn(f'recurring_id={rid}', detail)
            self.assertIn('ledger_id=1', detail)


if __name__ == '__main__':
    unittest.main()