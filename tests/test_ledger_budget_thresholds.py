"""Ledger budget threshold banner regression using a disposable fixture ledger."""
import unittest
from datetime import datetime, timedelta, timezone
from urllib.parse import unquote_plus

import test_print_and_comments as f


class LedgerBudgetThresholdTest(unittest.TestCase):
    def setUp(self):
        Fixture = f.AccountingTest
        self.addCleanup(Fixture.doClassCleanups)
        Fixture.setUpClass()
        self.Fixture = Fixture
        with Fixture.connect() as db:
            self.uid = db.execute('SELECT user_id FROM entries WHERE id=1').fetchone()[0]

    def get(self, path):
        status, _, body = self.Fixture.request(path)
        self.assertEqual(status, 200)
        return body

    def post(self, path, fields):
        status, headers, _ = self.Fixture.request(path, {**fields, 'csrf_token': self.Fixture.csrf})
        self.assertEqual(status, 302)
        self.assertIn('success=1', headers['Location'])
        return unquote_plus(headers['Location'])

    def test_ledger_budget_thresholds_and_clearing(self):
        Fixture = self.Fixture
        # Move TODAY calculation into the test as Taipei date object
        TaipeiTZ = timezone(timedelta(hours=8))
        today = datetime.now(TaipeiTZ).date()

        with Fixture.connect() as db:
            led = db.execute('INSERT INTO ledgers(name,owner_id) VALUES(?,?)', ('測試預算分流', self.uid)).lastrowid
            db.execute("INSERT INTO ledger_access(ledger_id,user_id,role) VALUES(?,?,'owner')", (led, self.uid))

        path = f'/ledger.php?ledger_id={led}'

        # Set monthly budget to 100.00
        self.post(path, dict(action='set_budget', monthly_budget='100.00'))

        # Assert DB monthly_budget_cents exactly 10000 after setting
        with Fixture.connect() as db:
            budget_cents = db.execute('SELECT monthly_budget_cents FROM ledgers WHERE id = ?', (led,)).fetchone()[0]
            self.assertEqual(budget_cents, 10000)

        # Three actual expense amounts: 80.00, 20.00, 0.01 (cumulative)
        expense_assertions = [
            ('80.00', 'budget-banner-warn', '-80.00', '20.00'),
            ('20.00', 'budget-banner-danger', '-100.00', '0.00'),
            ('0.01', 'budget-banner-danger', '-100.01', '已超支 0.01 元'),
        ]

        for amount, banner_css, spent_fragment, remaining_fragment in expense_assertions:
            self.post(f'/add_entry.php?ledger_id={led}', dict(
                type='expense',
                currency='TWD',
                amount=amount,
                entry_date=today.isoformat(),
                category_preset='其他',
                note='測試帳本預算'
            ))
            body = self.get(path)

            # Assert banner div class
            self.assertIn(f'class="budget-banner {banner_css}"', body)

            # Assert exact markup fragments in budget box
            self.assertIn(
                f'已花費 <strong class="mono">{spent_fragment}</strong>',
                body
            )
            if remaining_fragment.startswith('已超支'):
                self.assertIn(f'<strong>{remaining_fragment}</strong>', body)
            else:
                self.assertIn(
                    f'還剩 <strong class="mono">{remaining_fragment}</strong>',
                    body
                )

        # Assert DB monthly_budget_cents is 10000 still (budget still set)
        with Fixture.connect() as db:
            budget_cents = db.execute('SELECT monthly_budget_cents FROM ledgers WHERE id = ?', (led,)).fetchone()[0]
            self.assertEqual(budget_cents, 10000)

        # While budget still set, GET previous month by subtracting a day from today.replace(day=1)
        prev_month = today.replace(day=1) - timedelta(days=1)
        # Request the ledger page; assert no budget banner or box for previous month view
        body = self.get(f"{path}&ym={prev_month:%Y-%m}")
        self.assertNotRegex(body, r'<div class="budget-(?:banner|box)')

        # Clear current month budget
        self.post(path, dict(action='set_budget', monthly_budget=''))

        # Assert DB monthly_budget_cents is 0 after clearing
        with Fixture.connect() as db:
            budget_cents = db.execute('SELECT monthly_budget_cents FROM ledgers WHERE id = ?', (led,)).fetchone()[0]
            self.assertEqual(budget_cents, 0)

        # Same assertion after clearing current month: no budget banner or box
        self.assertNotRegex(self.get(path), r'<div class="budget-(?:banner|box)')


if __name__ == '__main__':
    unittest.main()