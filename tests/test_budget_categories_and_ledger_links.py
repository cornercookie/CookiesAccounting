"""Category budget options, ledger transfer total, and calendar link regressions."""
import re
import unittest

import test_print_and_comments as f


class TestBudgetCategoriesAndLedgerLinks(unittest.TestCase):
    def setUp(self):
        Fixture = f.AccountingTest
        self.addCleanup(Fixture.doClassCleanups)
        Fixture.setUpClass()
        with Fixture.connect() as db:
            self.uid = db.execute('SELECT user_id FROM entries WHERE id=1').fetchone()[0]

    def test_budget_categories_and_ledger_links(self):
        Fixture = f.AccountingTest
        path = '/category_budget.php?ledger_id=1'

        status, _, body = Fixture.request(path)
        self.assertEqual(status, 200)
        datalist = re.search(r'<datalist\s+id="category-suggestions"[^>]*>(.*?)</datalist>', body, re.S)
        self.assertIsNotNone(datalist)
        self.assertRegex(datalist.group(1), r'<option value="餐費"')

        status, headers, _ = Fixture.request(path, {
            'action': 'create',
            'category': '交通',
            'monthly_budget': '1000',
            'csrf_token': Fixture.csrf,
        })
        self.assertEqual(status, 302)
        self.assertIn('success=1', headers.get('Location', ''))
        with Fixture.connect() as db:
            rows = db.execute(
                'SELECT monthly_budget_cents FROM category_budgets '
                'WHERE ledger_id=1 AND category=? AND is_active=1',
                ('交通',),
            ).fetchall()
        self.assertEqual(rows, [(100000,)])

        status, headers, _ = Fixture.request(path, {
            'action': 'create',
            'category': '自訂預算類別',
            'monthly_budget': '1000',
            'csrf_token': Fixture.csrf,
        })
        self.assertEqual(status, 302)
        self.assertIn('success=1', headers.get('Location', ''))
        with Fixture.connect() as db:
            rows = db.execute(
                'SELECT monthly_budget_cents FROM category_budgets '
                'WHERE ledger_id=1 AND category=? AND is_active=1',
                ('自訂預算類別',),
            ).fetchall()
        self.assertEqual(rows, [(100000,)])

        status, headers, _ = Fixture.request(path, {
            'action': 'create',
            'category': '<script>',
            'monthly_budget': '1000',
            'csrf_token': Fixture.csrf,
        })
        self.assertEqual(status, 302)
        self.assertIn('error=1', headers.get('Location', ''))
        with Fixture.connect() as db:
            count = db.execute(
                'SELECT COUNT(*) FROM category_budgets WHERE ledger_id=1 AND category=?',
                ('<script>',),
            ).fetchone()[0]
        self.assertEqual(count, 0)

        with Fixture.connect() as db:
            db.execute(
                "INSERT INTO entries(ledger_id,user_id,amount_cents,type,category,entry_date,note) "
                "VALUES(1,?,4321,'transfer','其他','2025-02-18','QA 轉帳總計')",
                (self.uid,),
            )
        status, _, body = Fixture.request('/ledger.php?ledger_id=1&ym=2025-02')
        self.assertEqual(status, 200)
        summary = re.search(
            r'<div class="plates">(.*?)'
            r'<form method="POST" action="\./ledger\.php\?ledger_id=1" class="budget-form">',
            body,
            re.S,
        )
        self.assertIsNotNone(summary)
        self.assertRegex(
            summary.group(1),
            r'<div class="plate transfer"><small>[^<]*轉帳</small>'
            r'<div class="v">43\.21</div></div>',
        )
        self.assertIn('calendar.php?ledger_id=1', body)


if __name__ == '__main__':
    unittest.main()
