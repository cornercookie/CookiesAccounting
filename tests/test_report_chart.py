"""Reviewer-local HTTP test of [#13] on a disposable fixture."""
import re
import unittest
import test_print_and_comments as fixture


class ChartEmptyCase(unittest.TestCase):
    def setUp(self):
        Fixture = fixture.AccountingTest
        self.addCleanup(Fixture.doClassCleanups)
        Fixture.setUpClass()

    def test_monthly_chart_has_empty_state_with_income_only(self):
        Fixture = fixture.AccountingTest
        with Fixture.connect() as db:
            uid = db.execute('SELECT user_id FROM entries WHERE id=1').fetchone()[0]
            db.execute('INSERT INTO entries (ledger_id,user_id,amount_cents,type,category,entry_date,note) VALUES (1,?,?,?,?,?,?)', (uid, 700, 'income', '薪資', '2022-07-01', '只有收入'))
        status, _, page = Fixture.request('/ledger.php?ledger_id=1&ym=2022-07')
        self.assertEqual(status, 200)
        self.assertTrue('只有收入' in page, 'income row missing')
        self.assertFalse('class="category-chart"' in page, 'no expenses but an SVG was rendered')
        self.assertTrue('尚無支出紀錄' in page, 'income-only month did not show the no-expenses message')
        status, _, filtered = Fixture.request('/ledger.php?ledger_id=1&ym=2022-07&entry_date=2022-07-01')
        self.assertEqual(status, 200)
        self.assertFalse('尚無支出紀錄' in filtered, 'filtered day incorrectly shows monthly chart empty state')
        status, _, month_with_expense = Fixture.request('/ledger.php?ledger_id=1&ym=2025-02')
        self.assertEqual(status, 200)
        self.assertTrue('class="category-chart"' in month_with_expense, 'positive expense missing its chart')
        self.assertFalse('尚無支出紀錄' in month_with_expense, 'positive expense got an empty state')

    def test_category_chart_bars_fit_within_viewbox(self):
        Fixture = fixture.AccountingTest
        categories = ['QA食費', 'QA交通', 'QA房租', 'QA水電', 'QA醫療', 'QA娛樂', 'QA教育', 'QA其他']
        with Fixture.connect() as db:
            uid = db.execute('SELECT user_id FROM entries WHERE id=1').fetchone()[0]
            for cat in categories:
                db.execute(
                    'INSERT INTO entries (ledger_id,user_id,amount_cents,type,category,entry_date,note) VALUES (1,?,?,?,?,?,?)',
                    (uid, 234, 'expense', cat, '2022-07-01', 'QA ' + cat),
                )
        status, _, page = Fixture.request('/ledger.php?ledger_id=1&ym=2022-07')
        self.assertEqual(status, 200)
        match = re.search(r'<svg class="category-chart".*?</svg>', page, re.DOTALL)
        if match is None:
            self.fail('category chart SVG missing')
        svg = match.group(0)
        height_match = re.search(r'viewBox="0 0 \d+ (\d+)"', svg)
        if height_match is None:
            self.fail('category chart viewBox height missing')
        height = int(height_match.group(1))
        texts = [re.sub(r'<[^>]+>', '', t) for t in re.findall(r'<text[^>]*>(.*?)</text>', svg, re.DOTALL)]
        for cat in categories:
            self.assertIn(cat, texts, 'category text missing from chart: ' + cat)
        rects = re.findall(r'<rect\b[^>]*>', svg)
        self.assertEqual(len(rects), 8, 'expected exactly 8 category bars')
        max_bottom = 0
        for rect in rects:
            width_match = re.search(r'width="(\d+)"', rect)
            bar_height_match = re.search(r'height="(\d+)"', rect)
            y_match = re.search(r'y="(\d+)"', rect)
            if width_match is None or bar_height_match is None or y_match is None:
                self.fail('category bar missing width/height/y: ' + rect)
            width = int(width_match.group(1))
            bar_height = int(bar_height_match.group(1))
            y = int(y_match.group(1))
            self.assertGreater(width, 0, 'bar width must be positive')
            self.assertGreater(bar_height, 0, 'bar height must be positive')
            max_bottom = max(max_bottom, y + bar_height)
        self.assertLessEqual(max_bottom, height, 'category bars overflow the chart viewBox height')


if __name__ == '__main__':
    unittest.main()
