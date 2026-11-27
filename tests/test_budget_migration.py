"""Budget migration test converted from verified_probe.py."""
import threading
from concurrent.futures import ThreadPoolExecutor
import sqlite3
import unittest
import test_print_and_comments as fixture


# module is import-inert; no side-effects at import time


class BudgetMigrationCase(unittest.TestCase):
    def setUp(self):
        Fixture = fixture.AccountingTest
        self.addCleanup(Fixture.doClassCleanups)
        Fixture.setUpClass()

    def test_budget_migration(self):
        A = fixture.AccountingTest
        CAT = 'QA Migration Budget'
        OLD = 'uq_category_budgets_ledger_category_active'
        NEW = 'uq_category_budgets_active_only'
        INSERT = 'INSERT INTO category_budgets(ledger_id,category,monthly_budget_cents,is_active) VALUES(1,?,?,?)'
        PATH = '/category_budget.php?ledger_id=1'

        def indexes(db):
            return dict(db.execute(
                'SELECT name,sql FROM sqlite_master WHERE tbl_name=? AND type=?',
                ('category_budgets', 'index')
            ))

        def rows(db):
            return db.execute(
                'SELECT id,monthly_budget_cents,is_active FROM category_budgets '
                'WHERE ledger_id=1 AND category=? ORDER BY id', (CAT,)
            ).fetchall()

        def history(expected):
            with A.connect() as db:
                actual = [(amt, flag) for _, amt, flag in rows(db)]
            assert actual == expected, actual

        def post(**fields):
            status, headers, _ = A.request(
                PATH, dict(csrf_token=A.csrf, **fields)
            )
            assert status == 302 and 'success=1' in headers.get('Location', ''), \
                (status, headers.get('Location'))

        # --- setup database: synthetic category, index replacement, data preservation ---
        with A.connect() as db:
            db.execute('INSERT INTO categories(name,is_active) VALUES(?,1)', (CAT,))
            db.execute('DROP INDEX uq_category_budgets_active_only')
            db.execute('CREATE UNIQUE INDEX uq_category_budgets_ledger_category_active ON category_budgets(ledger_id,category,is_active)')
            db.executemany(INSERT, [(CAT, 321, 0), (CAT, 654, 1)])
            before = rows(db)
            assert OLD in indexes(db) and NEW not in indexes(db)

        # --- normal form: successful CSRF-protected actions ---
        status, _, body = A.request(PATH)
        assert status == 200 and CAT in body, status

        with A.connect() as db:
            ix = indexes(db)
            assert OLD not in ix and 'WHERE is_active = 1' in ix[NEW], ix
            assert rows(db) == before

        post(action='disable', id=before[1][0])
        post(action='create', category=CAT, monthly_budget='11.37')

        # --- duplicate form error ---
        failed, headers, _ = A.request(
            PATH, dict(csrf_token=A.csrf, action='create', category=CAT, monthly_budget='77.77')
        )
        assert failed == 302 and 'error=1' in headers.get('Location', ''), failed

        history([(321, 0), (654, 0), (1137, 1)])

        with A.connect() as db:
            last_id = rows(db)[-1][0]
        post(action='disable', id=last_id)
        history([(321, 0), (654, 0), (1137, 0)])

        # --- real SQLite threads with same barrier and two connections ---
        sync = threading.Barrier(2, timeout=5)

        def writer(_):
            with A.connect() as db:
                sync.wait()
                try:
                    db.execute(INSERT, (CAT, 975, 1))
                except sqlite3.IntegrityError as error:
                    assert error.sqlite_errorname == 'SQLITE_CONSTRAINT_UNIQUE', error
                    return 'unique'
            return 'committed'

        with ThreadPoolExecutor(max_workers=2) as workers:
            verdicts = sorted(workers.map(writer, range(2)))
        assert verdicts == ['committed', 'unique'], verdicts

        history([(321, 0), (654, 0), (1137, 0), (975, 1)])


if __name__ == '__main__':
    unittest.main()