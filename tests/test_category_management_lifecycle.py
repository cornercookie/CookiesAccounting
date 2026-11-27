"""Category management lifecycle regression; run with PHP_BIN."""
import re
import shutil
import unittest
from urllib.parse import parse_qs, unquote_plus, urlsplit

import test_print_and_comments as f


class TestCategoryManagementLifecycle(unittest.TestCase):
    def setUp(self):
        self.addCleanup(f.AccountingTest.doClassCleanups)
        f.AccountingTest.setUpClass()
        d = f.AccountingTest.db.parent.parent / "admin"
        d.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(f.ROOT / "admin" / "category_management.php", d / "category_management.php")

    def post(self, path, fields):
        status, headers, _ = f.AccountingTest.request(path, {**fields, 'csrf_token': f.AccountingTest.csrf})
        self.assertEqual(status, 302)
        self.assertIn('success=1', headers['Location'])
        return unquote_plus(headers['Location'])

    def test_admin_category_create_rename_merge_and_disable(self):
        src = '測試分類甲'
        dst = '測試分類乙'
        renamed = '測試分類丙'
        note1 = '測試分類合併'
        note2 = 'QA Second Ledger 合併'
        url = '/admin/category_management.php'
        for name in (src, dst):
            self.post(url, dict(action='create_category', name=name))
        with f.AccountingTest.connect() as db:
            ids = dict(db.execute('SELECT name,id FROM categories WHERE name IN (?,?)', (src, dst)).fetchall())
            owner_id = db.execute('SELECT user_id FROM entries WHERE id=1').fetchone()[0]
            cur = db.execute('INSERT INTO ledgers (name, owner_id) VALUES (?,?)', ('QA Second Ledger', owner_id))
            second_ledger = cur.lastrowid
            db.execute('INSERT INTO ledger_access (ledger_id, user_id, role) VALUES (?,?,?)', (second_ledger, owner_id, 'owner'))
            control = db.execute('SELECT category, amount_cents FROM entries WHERE id=1').fetchone()
        self.post(url, dict(action='edit_category', category_id=ids[dst], name=renamed))
        self.post('/add_entry.php?ledger_id=1', dict(type='expense', currency='TWD', amount='2.34', entry_date='2025-02-17', category_preset='__custom__', category=src, note=note1))
        self.post('/add_entry.php?ledger_id=' + str(second_ledger), dict(type='expense', currency='TWD', amount='5.67', entry_date='2025-02-17', category_preset='__custom__', category=src, note=note2))
        self.post(url, dict(action='merge_category', source_category_id=ids[src], target_category_id=ids[dst]))
        with f.AccountingTest.connect() as db:
            self.assertEqual(db.execute('SELECT is_active FROM categories WHERE id=?', (ids[src],)).fetchone(), (0,))
            self.assertEqual(db.execute('SELECT is_active,name FROM categories WHERE id=?', (ids[dst],)).fetchone(), (1, renamed))
            rows = db.execute('SELECT ledger_id,category,amount_cents FROM entries WHERE note IN (?,?) ORDER BY ledger_id', (note1, note2)).fetchall()
            self.assertEqual(rows, [(1, renamed, 234), (second_ledger, renamed, 567)])
            self.assertEqual(db.execute('SELECT category,amount_cents FROM entries WHERE id=1').fetchone(), control)

    def test_category_merge_rolls_back_on_second_write(self):
        src = 'QA Rollback Source'
        dst = 'QA Rollback Target'
        note = 'QA Rollback Entry'
        url = '/admin/category_management.php'
        for name in (src, dst):
            self.post(url, dict(action='create_category', name=name))
        with f.AccountingTest.connect() as db:
            ids = dict(db.execute('SELECT name,id FROM categories WHERE name IN (?,?)', (src, dst)).fetchall())
        self.post('/add_entry.php?ledger_id=1', dict(type='expense', currency='TWD', amount='2.34', entry_date='2025-02-17', category_preset='__custom__', category=src, note=note))
        with f.AccountingTest.connect() as db:
            self.assertEqual(db.execute('SELECT category,amount_cents FROM entries WHERE note=?', (note,)).fetchall(), [(src, 234)])
            db.execute(
                "CREATE TRIGGER qa_abort_merge BEFORE UPDATE OF is_active ON categories "
                "WHEN OLD.name='QA Rollback Source' AND NEW.is_active=0 AND EXISTS "
                "(SELECT 1 FROM entries WHERE note='QA Rollback Entry' AND category='QA Rollback Target') "
                "BEGIN SELECT RAISE(ABORT, 'QA transaction fault'); END"
            )
        status, headers, _ = f.AccountingTest.request(url, dict(action='merge_category', source_category_id=ids[src], target_category_id=ids[dst], csrf_token=f.AccountingTest.csrf))
        self.assertEqual(status, 302)
        location = urlsplit(headers['Location'])
        self.assertEqual(location.path, './category_management.php')
        query = parse_qs(location.query)
        msg_sig = query.pop('msg_sig')
        self.assertIsInstance(msg_sig, list)
        self.assertEqual(len(msg_sig), 1)
        self.assertRegex(msg_sig[0], r'^[0-9a-f]{64}$')
        self.assertEqual(query, {'error': ['1'], 'msg': ['合併失敗，請稍後再試。']})
        with f.AccountingTest.connect() as db:
            self.assertEqual(db.execute('SELECT is_active FROM categories WHERE id=?', (ids[src],)).fetchone(), (1,))
            self.assertEqual(db.execute('SELECT is_active FROM categories WHERE id=?', (ids[dst],)).fetchone(), (1,))
            self.assertEqual(db.execute('SELECT category,amount_cents FROM entries WHERE note=?', (note,)).fetchall(), [(src, 234)])
            self.assertEqual(db.execute("SELECT COUNT(*) FROM audit_log WHERE action='category_merge'").fetchone(), (0,))

    def test_category_form_input_boundaries(self):
        name50 = '測' * 50
        name51 = '測' * 51
        distinct = 'QA Category Distinct'
        url = '/admin/category_management.php'
        for name in (name50, distinct):
            self.post(url, dict(action='create_category', name=name))
        with f.AccountingTest.connect() as db:
            ids = dict(db.execute('SELECT name,id FROM categories WHERE name IN (?,?)', (name50, distinct)).fetchall())
        duplicate_msg = '該分類名稱已經存在，請使用其他名稱。'
        maxlen_msg = '分類名稱最多 50 個字元。'
        cases = [
            ('duplicate create', dict(action='create_category', name=distinct), duplicate_msg),
            ('duplicate edit', dict(action='edit_category', category_id=ids[distinct], name=name50), duplicate_msg),
            ('maxlen create', dict(action='create_category', name=name51), maxlen_msg),
            ('maxlen edit', dict(action='edit_category', category_id=ids[name50], name=name51), maxlen_msg),
            ('merge same ids', dict(action='merge_category', source_category_id=ids[name50], target_category_id=ids[name50]), '來源分類與目標分類不可相同。'),
            ('merge absent target', dict(action='merge_category', source_category_id=ids[name50], target_category_id=999999999), '目標分類不存在。'),
        ]
        for label, fields, msg in cases:
            with self.subTest(input=label):
                status, headers, _ = f.AccountingTest.request(url, {**fields, 'csrf_token': f.AccountingTest.csrf})
                self.assertEqual(status, 302)
                location = urlsplit(headers['Location'])
                self.assertEqual(location.scheme, '')
                self.assertEqual(location.netloc, '')
                self.assertEqual(location.fragment, '')
                self.assertEqual(location.path, './category_management.php')
                query = parse_qs(location.query, keep_blank_values=True)
                msg_sig = query.pop('msg_sig')
                self.assertIsInstance(msg_sig, list)
                self.assertEqual(len(msg_sig), 1)
                self.assertRegex(msg_sig[0], r'^[0-9a-f]{64}$')
                self.assertEqual(query, {'error': ['1'], 'msg': [msg]})
        with f.AccountingTest.connect() as db:
            self.assertEqual(db.execute('SELECT name,is_active FROM categories WHERE id=?', (ids[name50],)).fetchone(), (name50, 1))
            self.assertEqual(db.execute('SELECT name,is_active FROM categories WHERE id=?', (ids[distinct],)).fetchone(), (distinct, 1))
            self.assertEqual(db.execute('SELECT COUNT(*) FROM categories WHERE name=?', (name51,)).fetchone(), (0,))


if __name__ == "__main__":
    unittest.main()