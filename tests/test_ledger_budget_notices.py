import re
import unittest
from datetime import datetime, timedelta, timezone
import test_print_and_comments as fixture


def exercise(A):
    today = datetime.now(timezone(timedelta(hours=8))).date()
    name = 'QA Budget Notice Regression'
    with A.connect() as db:
        uid = db.execute('SELECT user_id FROM entries WHERE id=1').fetchone()[0]
        led = db.execute('INSERT INTO ledgers(name,owner_id) VALUES(?,?)', (name, uid)).lastrowid
        db.execute('INSERT INTO ledger_access(ledger_id,user_id,role) VALUES(?,?,?)', (led, uid, 'owner'))
    ledger = f'/ledger.php?ledger_id={led}'
    dash = '/dashboard.php'

    def post(url, **fields):
        status, headers, _ = A.request(url, dict(csrf_token=A.csrf, **fields))
        assert status == 302 and 'success=1' in headers.get('Location', ''), (status, headers.get('Location'))

    def get(url):
        status, _, body = A.request(url)
        assert status == 200, (url, status)
        return body

    def dashboard_row(body):
        return next(row for row in re.findall(r'<tr\b[^>]*>(.*?)</tr>', body, re.S)
                    if f'./ledger.php?ledger_id={led}"' in row)

    def notice(body):
        return re.findall(r'<div class="budget-banner budget-banner-(\w+)" role="alert">\s*'
                          rf'<strong>{name}</strong> 本月預算已使用 <strong class="num">(\d+)%</strong>：\s*'
                          r'([^\s]+)\s*（預算 100\.00 元，已支出 <strong class="num">([\d.]+)</strong> 元）', body)

    failures = []
    post(ledger, action='set_budget', monthly_budget='100.00')
    for add, cents, percent, status, full, chip, remainder in (
        ('79.99', 7999, 79, 'ok', '預算尚充足', None, '20.01'),
        ('0.01', 8000, 80, 'warn', '接近預算上限', '近上限', '20.00'),
        ('20.00', 10000, 100, 'danger', '已達預算上限', '已達預算上限', '0.00'),
        ('0.01', 10001, 100, 'danger', '已超過預算', '已超支', '0.01'),
        ('49.99', 15000, 150, 'danger', '已超過預算', '已超支', '50.00'),
    ):
        post(f'/add_entry.php?ledger_id={led}', type='expense', currency='TWD', amount=add,
             entry_date=today.isoformat(), category_preset='其他', note='QA budget notice')
        with A.connect() as db:
            assert db.execute('SELECT SUM(amount_cents) FROM entries WHERE ledger_id=? AND type=? AND deleted=0',
                              (led, 'expense')).fetchone() == (cents,)
        amt = f'{cents // 100}.{cents % 100:02d}'
        expected = [] if chip is None else [(status, str(percent), full, amt)]
        body_l, body_d = get(ledger), get(dash)
        for label, body in [('ledger', body_l), ('dashboard', body_d)]:
            count = body.count('本月預算已使用')
            if count != len(expected) or notice(body) != expected:
                failures.append((amt, label, count, notice(body), expected))
        if f'<span class="budget-chip {status}">{full}</span>' not in body_l:
            failures.append((amt, 'ledger chip', full))
        row_chips = re.findall(r'<span class="budget-chip (\w+)">([^<]+)</span>', dashboard_row(body_d))
        want_chip = [] if chip is None else [(status, chip)]
        if row_chips != want_chip:
            failures.append((amt, 'dashboard chip', row_chips, want_chip))
        assert f'<span class="budget-fill" style="width: {min(percent, 100)}%;">' in body_l
        assert f'已花費 <strong class="mono">-{amt}</strong>' in body_l
        assert (f'<strong>已超支 {remainder} 元</strong>' if cents > 10000 else
                f'還剩 <strong class="mono">{remainder}</strong>') in body_l
    prev = today.replace(day=1) - timedelta(days=1)
    old = get(f'{ledger}&ym={prev:%Y-%m}')
    assert '本月預算已使用' not in old and 'class="budget-box ' not in old
    post(ledger, action='set_budget', monthly_budget='')
    with A.connect() as db:
        assert db.execute('SELECT monthly_budget_cents FROM ledgers WHERE id=?', (led,)).fetchone() == (0,)
    assert all('本月預算已使用' not in get(url) for url in (ledger, dash))
    assert 'budget-chip' not in dashboard_row(get(dash))
    assert not failures, failures


class TestLedgerBudgetNotices(unittest.TestCase):
    def test_ledger_and_dashboard_budget_notices(self):
        A = fixture.AccountingTest
        self.addCleanup(A.doClassCleanups)
        A.setUpClass()
        exercise(A)


if __name__ == "__main__":
    unittest.main()
