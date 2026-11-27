import re
import unittest
from datetime import datetime, timedelta, timezone
import test_print_and_comments as fixture


class TestMonthlyBudgetDateFilter(unittest.TestCase):
    def test_daily_view_preserves_monthly_budget_totals(self):
        A = fixture.AccountingTest
        self.addCleanup(A.doClassCleanups)
        A.setUpClass()
        first = datetime.now(timezone(timedelta(hours=8))).date().replace(day=1)
        second = first + timedelta(days=1)
        previous = first - timedelta(days=1)
        with A.connect() as db:
            uid = db.execute('SELECT user_id FROM entries WHERE id=1').fetchone()[0]
            ledger_id = db.execute('INSERT INTO ledgers(name,owner_id) VALUES(?,?)', ('QA Month Scope', uid)).lastrowid
            db.execute('INSERT INTO ledger_access(ledger_id,user_id,role) VALUES(?,?,?)', (ledger_id, uid, 'owner'))
        path = f'/ledger.php?ledger_id={ledger_id}'

        def post(url, **fields):
            status, headers, _ = A.request(url, dict(csrf_token=A.csrf, **fields))
            self.assertEqual(status, 302)
            self.assertIn('success=1', headers.get('Location', ''))

        def get(url):
            status, _, body = A.request(url)
            self.assertEqual(status, 200)
            return body

        post(path, action='set_budget', monthly_budget='100.00')
        for date, amount, note, entry_type in (
            (first, '2.00', 'QA daily kept', 'expense'),
            (second, '88.00', 'QA daily excluded', 'expense'),
            (first, '55.00', 'QA income', 'income'),
            (first, '33.00', 'QA transfer', 'transfer'),
            (previous, '36.00', 'QA historical', 'expense'),
        ):
            post(f'/add_entry.php?ledger_id={ledger_id}', type=entry_type, currency='TWD', amount=amount,
                 entry_date=date.isoformat(), category_preset='其他', note=note)
        with A.connect() as db:
            db.execute('INSERT INTO entries(ledger_id,user_id,amount_cents,type,category,entry_date,note,deleted) '
                       'VALUES(?,?,?,?,?,?,?,1)', (ledger_id, uid, 3400, 'expense', '其他', second.isoformat(), 'QA archived row'))
            self.assertEqual(db.execute('SELECT entry_date,SUM(amount_cents) FROM entries '
                                        'WHERE ledger_id=? AND deleted=0 AND type=? GROUP BY entry_date ORDER BY entry_date',
                                        (ledger_id, 'expense')).fetchall(),
                             [(previous.isoformat(), 3600), (first.isoformat(), 200), (second.isoformat(), 8800)])
        day = get(f'{path}&ym={first:%Y-%m}&entry_date={first.isoformat()}')
        self.assertIn('QA daily kept', day)
        self.assertNotIn('QA daily excluded', day)
        self.assertNotIn('QA historical', day)
        self.assertRegex(day, r'<div class="plate out"><small>[^<]+</small><div class="v">-2\.00</div>')
        banner = re.compile(r'<div class="budget-banner budget-banner-warn" role="alert">\s*'
                            r'<strong>QA Month Scope</strong> 本月預算已使用 <strong class="num">90%</strong>：\s*'
                            r'接近預算上限\s*（預算 100\.00 元，已支出 <strong class="num">90\.00</strong> 元）')
        for view, body in [('month', get(path)), ('day', day), ('dashboard', get('/dashboard.php'))]:
            with self.subTest(view=view):
                self.assertEqual(len(banner.findall(body)), 1, f'{view}: expected one 90% warning with 90.00 monthly spent')
        self.assertIn('已花費 <strong class="mono">-90.00</strong>', day)
        self.assertIn('還剩 <strong class="mono">10.00</strong>', day)
        self.assertIn('<span class="budget-fill" style="width: 90%;">', day)
        historical = get(f'{path}&ym={previous:%Y-%m}&entry_date={previous.isoformat()}')
        self.assertIn('QA historical', historical)
        self.assertNotIn('本月預算已使用', historical)
        self.assertNotIn('class="budget-box ', historical)


if __name__ == '__main__':
    unittest.main()
