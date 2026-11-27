"""Reviewer-local HTTP test of report period totals on a disposable fixture."""
import re
import unittest
import test_print_and_comments as fixture


class ReportPeriodsCase(unittest.TestCase):
    def setUp(self):
        Fixture = fixture.AccountingTest
        self.addCleanup(Fixture.doClassCleanups)
        Fixture.setUpClass()

    @staticmethod
    def footer_cells(page):
        tfoot = re.search(r'<tfoot>(.*?)</tfoot>', page, re.DOTALL)
        if tfoot is None:
            raise AssertionError('report footer missing')
        cells = re.findall(r'<td[^>]*>(.*?)</td>', tfoot.group(1), re.DOTALL)
        return [re.sub(r'<[^>]+>', '', cell).strip() for cell in cells]

    @staticmethod
    def empty_text(page):
        empty = re.search(r'<p class="report-empty">(.*?)</p>', page, re.DOTALL)
        if empty is None:
            raise AssertionError('report-empty paragraph missing')
        return re.sub(r'<[^>]+>', '', empty.group(1)).strip()

    def test_report_period_totals_and_empty_states(self):
        Fixture = fixture.AccountingTest
        with Fixture.connect() as db:
            uid = db.execute('SELECT user_id FROM entries WHERE id=1').fetchone()[0]
            db.executemany(
                'INSERT INTO entries (ledger_id, user_id, amount_cents, type, entry_date, deleted) VALUES (1, ?, ?, ?, ?, ?)',
                [(uid, amount, kind, date, deleted) for amount, kind, date, deleted in (
                    (90111, 'income', '2024-09-30', 0),
                    (123456, 'income', '2024-10-01', 0),
                    (5412, 'transfer', '2024-11-08', 0),
                    (23456, 'expense', '2024-12-31', 0),
                    (999999, 'expense', '2024-12-31', 1),
                    (77777, 'income', '2025-01-01', 0),
                )],
            )
        for quarter in ('Q4', '4'):
            with self.subTest(quarter=quarter):
                status, _, body = Fixture.request('/quarterly_report.php?ledger_id=1&year=2024&quarter=' + quarter)
                self.assertEqual(status, 200)
                self.assertEqual(self.footer_cells(body)[-4:], ['+1,234.56', '-234.56', '54.12', '+1,000.00'])
        status, _, body = Fixture.request('/yearly_report.php?ledger_id=1&year=2024')
        self.assertEqual(status, 200)
        self.assertEqual(self.footer_cells(body)[-4:], ['+2,135.67', '-234.56', '54.12', '+1,901.11'])
        for path in ('/quarterly_report.php?ledger_id=1&year=2024&quarter=Q1', '/yearly_report.php?ledger_id=1&year=2023'):
            with self.subTest(empty=path):
                status, _, body = Fixture.request(path)
                self.assertEqual(status, 200)
                self.assertIn('無記錄', self.empty_text(body))


if __name__ == '__main__':
    unittest.main()