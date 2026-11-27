"""Reviewer-local HTTP test of comparison report category rows on a disposable fixture."""
import html
import re
import unittest
from datetime import datetime, timedelta, timezone

import test_print_and_comments as f


class ComparisonReportCase(unittest.TestCase):
    def setUp(self):
        self.addCleanup(f.AccountingTest.doClassCleanups)
        f.AccountingTest.setUpClass()

    @staticmethod
    def category_row(page):
        for row in re.findall(r'<tr[^>]*>(.*?)</tr>', page, re.DOTALL):
            cells = re.findall(r'<td[^>]*>(.*?)</td>', row, re.DOTALL)
            cells = [html.unescape(re.sub(r'<[^>]+>', '', c)).strip() for c in cells]
            if cells and cells[0] == 'QA Comparison':
                return cells
        return None

    def test_comparison_report_category_rows(self):
        now = datetime.now(timezone(timedelta(hours=8)))
        month_start = now.replace(day=1).date()
        prior_month = (month_start - timedelta(days=1)).replace(day=1)
        year_start = now.replace(month=1, day=1).date()
        prior_year = year_start.replace(year=year_start.year - 1)
        periods = {'month': (month_start, prior_month), 'year': (year_start, prior_year)}
        ledgers = {}
        with f.AccountingTest.connect() as db:
            uid = db.execute('SELECT user_id FROM entries WHERE id=1').fetchone()[0]
            for mode, (cur, prev) in periods.items():
                lid = db.execute('INSERT INTO ledgers(name,owner_id) VALUES(?,?)', (mode, uid)).lastrowid
                db.execute('INSERT INTO ledger_access(ledger_id,user_id,role) VALUES(?,?,?)', (lid, uid, 'owner'))
                db.executemany(
                    'INSERT INTO entries (ledger_id,user_id,amount_cents,type,category,entry_date,note) VALUES(?,?,?,?,?,?,?)',
                    [(lid, uid, amt, kind, 'QA Comparison', date.isoformat(), note) for amt, kind, date, note in (
                        (8000, 'expense', cur, 'current'),
                        (5000, 'expense', prev, 'prior'),
                        (99999, 'transfer', cur, 'transfer'),
                    )],
                )
                ledgers[mode] = lid
        for mode, lid in ledgers.items():
            with self.subTest(mode=mode):
                status, _, body = f.AccountingTest.request('/comparison_report.php?ledger_id=%d&mode=%s' % (lid, mode))
                self.assertEqual(status, 200)
                self.assertIn('<svg', body)
                self.assertEqual(self.category_row(body), ['QA Comparison', '-80.00', '-50.00', '30.00', '60%'])

    def test_comparison_chart_contains_all_category_bars(self):
        now = datetime.now(timezone(timedelta(hours=8)))
        cur = now.replace(day=1).date()
        prev = (cur - timedelta(days=1)).replace(day=1)
        cats = ['QA Chart %d' % i for i in range(1, 9)]
        with f.AccountingTest.connect() as db:
            uid = db.execute('SELECT user_id FROM entries WHERE id=1').fetchone()[0]
            lid = db.execute('INSERT INTO ledgers(name,owner_id) VALUES(?,?)', ('chart', uid)).lastrowid
            db.execute('INSERT INTO ledger_access(ledger_id,user_id,role) VALUES(?,?,?)', (lid, uid, 'owner'))
            db.executemany(
                'INSERT INTO entries (ledger_id,user_id,amount_cents,type,category,entry_date,note) VALUES(?,?,?,?,?,?,?)',
                [(lid, uid, amt, 'expense', cat, date.isoformat(), 'chart')
                 for cat in cats for amt, date in ((234, cur), (100, prev))],
            )
        status, _, body = f.AccountingTest.request('/comparison_report.php?ledger_id=%d&mode=month' % lid)
        self.assertEqual(status, 200)
        match = re.search(
            r'<svg[^>]*class="category-chart"[^>]*viewBox="0 0 \d+ (\d+)"[^>]*>(.*?)</svg>',
            body, re.DOTALL)
        if match is None:
            self.fail('category chart SVG missing')
        height = float(match.group(1))
        svg = match.group(2)
        texts = [html.unescape(re.sub(r'<[^>]+>', '', t)) for t in re.findall(r'<text[^>]*>(.*?)</text>', svg, re.DOTALL)]
        for cat in cats:
            self.assertIn(cat, texts)
        bars = [tuple(float(v) for v in (x, y, w, h)) for x, y, w, h in
                re.findall(r'<rect x="(\d+)" y="(\d+)" width="(\d+)" height="(\d+)"', svg)
                if int(y) != 20]
        self.assertEqual(len(bars), 16)
        for _, _, w, h in bars:
            self.assertGreater(w, 0)
            self.assertGreater(h, 0)
        self.assertLessEqual(max(y + h for _, y, _, h in bars), height)


if __name__ == '__main__':
    unittest.main()