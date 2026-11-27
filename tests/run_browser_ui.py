"""Launch local test fixture for the independent Chrome probes; no production credentials."""
import os
import subprocess
import tempfile
from datetime import datetime, timedelta, timezone
from pathlib import Path
import test_print_and_comments as fixture


def main():
    probe = Path(__file__).with_name("browser_ui.cjs")
    A = fixture.AccountingTest
    try:
        A.setUpClass()
        with A.connect() as db:
            uid = db.execute('SELECT user_id FROM entries WHERE id=1').fetchone()[0]
            db.execute('INSERT INTO recurring_entries(ledger_id,user_id,name,amount_cents,category,type,start_date,next_due_date) VALUES(1,?,?,?,?,?,?,?)', (uid,'測試固定開銷',3250,'餐費','expense','2025-02-01','2025-02-17'))
            taipei_now = datetime.now(timezone(timedelta(hours=8)))
            current_month_start = taipei_now.date().replace(day=1)
            previous_month_start = (current_month_start - timedelta(days=1)).replace(day=1)
            db.execute(
                'INSERT INTO entries(ledger_id,user_id,category,amount_cents,type,entry_date,note) VALUES(?,?,?,?,?,?,?)',
                (1, uid, 'QA Comparison Browser', 234, 'expense', current_month_start.isoformat(), 'QA Comparison Browser current month'),
            )
            db.execute(
                'INSERT INTO entries(ledger_id,user_id,category,amount_cents,type,entry_date,note) VALUES(?,?,?,?,?,?,?)',
                (1, uid, 'QA Comparison Browser', 100, 'expense', previous_month_start.isoformat(), 'QA Comparison Browser previous month'),
            )
        sid = next(c.value for h in A.opener.handlers if hasattr(h, 'cookiejar') for c in h.cookiejar if c.name=='cookies_accounting')
        with tempfile.TemporaryDirectory(dir=os.environ.get('HERMES_TEST_TMP')) as profile:
            subprocess.run(['node', str(probe)], env={**os.environ, 'COOKIES_QA_ONLY': '', 'COOKIES_CHROME_PROFILE':profile, 'COOKIES_TEST_BASE':A.base, 'COOKIES_TEST_SESSION':sid}, check=True, timeout=45)
        with A.connect() as db:
            assert db.execute('SELECT deleted FROM entries WHERE id=1').fetchone() == (0,)
            assert db.execute('SELECT category, amount_cents, entry_date FROM entries WHERE note=?', ("測試 AI 確認後才儲存",)).fetchall() == [('測試水果',5000,'2025-02-17')]
        print('SQLite confirmed manual save of fixture response; original entry not deleted')
    finally:
        A.doClassCleanups()


if __name__ == "__main__":
    main()