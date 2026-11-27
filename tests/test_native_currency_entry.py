"""Native currency entry test that validates TWD entries persist correctly."""
import unittest
import test_print_and_comments as f


class NativeCurrencyEntryTest(unittest.TestCase):
    def setUp(self):
        Fixture = f.AccountingTest
        self.addCleanup(Fixture.doClassCleanups)
        Fixture.setUpClass()

    def test_native_currency_entry_persists_twice(self):
        Fixture = f.AccountingTest
        path = '/add_entry.php?ledger_id=1'
        status, _, body = Fixture.request(path)
        self.assertTrue(status == 200)
        token = Fixture.token(body)

        for amount, note in (('0.10', 'qa-twd-decimal-0'), ('0.20', 'qa-twd-decimal-1')):
            base = {
                'csrf_token': token,
                'ledger_id': '1',
                'type': 'expense',
                'amount': amount,
                'entry_date': '2025-02-17',
                'currency': 'TWD',
                'category_preset': '__custom__',
                'category': 'QA Decimal TWD',
                'note': note,
            }
            status, headers, _ = Fixture.request(path, base)
            self.assertEqual(status, 302)
            self.assertIn('success=1', headers.get('Location', ''))

        with Fixture.connect() as db:
            rows = db.execute(
                'SELECT amount_cents, original_amount_cents, exchange_rate, currency '
                'FROM entries WHERE note IN (?, ?) ORDER BY id',
                ('qa-twd-decimal-0', 'qa-twd-decimal-1'),
            ).fetchall()
            self.assertEqual(rows, [(10, 10, 10000, 'TWD'), (20, 20, 10000, 'TWD')])
            total = db.execute(
                'SELECT SUM(amount_cents) FROM entries WHERE note IN (?, ?)',
                ('qa-twd-decimal-0', 'qa-twd-decimal-1'),
            ).fetchone()[0]
            self.assertEqual(total, 30)


if __name__ == '__main__':
    unittest.main()