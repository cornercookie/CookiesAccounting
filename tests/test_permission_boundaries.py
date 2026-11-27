"""Permission boundary regressions ([#34-A]); run with PHP_BIN."""
import unittest
from urllib.parse import unquote_plus

import test_print_and_comments as f


class TestPermissionBoundaries(unittest.TestCase):
    """Assert role/ownership boundaries by DB state, not HTTP status."""

    def setUp(self):
        Fixture = f.AccountingTest
        self.addCleanup(Fixture.doClassCleanups)
        Fixture.setUpClass()
        with Fixture.connect() as db:
            # 帳本 1 的存取者：fixture_admin（擁有者），測試中以 SQL 改其角色
            self.uid = db.execute('SELECT user_id FROM entries WHERE id=1').fetchone()[0]

    def post(self, path, fields):
        """POST with the fixture CSRF token; returns the redirect target."""
        status, headers, _ = f.AccountingTest.request(path, {**fields, 'csrf_token': f.AccountingTest.csrf})
        self.assertEqual(status, 302)
        return unquote_plus(headers.get('Location', ''))

    def insert_user(self, db, username):
        return db.execute(
            "INSERT INTO users(username, password_hash, role) VALUES(?, 'not-a-real-login-hash', 'member')",
            (username,),
        ).lastrowid

    def insert_entry(self, db, ledger_id, user_id, type_, amount_cents, note):
        return db.execute(
            'INSERT INTO entries(ledger_id,user_id,amount_cents,type,category,entry_date,note,currency,exchange_rate,original_amount_cents)'
            ' VALUES(?,?,?,?,?,?,?,?,?,?)',
            (ledger_id, user_id, amount_cents, type_, '其他', '2025-02-18', note, 'TWD', 10000, amount_cents),
        ).lastrowid

    def test_viewer_cannot_generate_recurring(self):
        with f.AccountingTest.connect() as db:
            db.execute("UPDATE ledger_access SET role='viewer' WHERE ledger_id=1 AND user_id=?", (self.uid,))
            rid = db.execute(
                'INSERT INTO recurring_entries(ledger_id,user_id,name,amount_cents,type,category,start_date,next_due_date,is_active)'
                " VALUES(1,?,'重複-權限測試',500,'expense','其他','2025-02-01','2025-02-20',1)",
                (self.uid,),
            ).lastrowid
            before = db.execute('SELECT COUNT(*) FROM entries WHERE ledger_id=1').fetchone()[0]
        location = self.post('/ledger.php?ledger_id=1', dict(action='generate_recurring', recurring_id=str(rid)))
        self.assertIn('error=1', location)
        self.assertIn('您沒有權限新增記錄', location)
        with f.AccountingTest.connect() as db:
            after = db.execute('SELECT COUNT(*) FROM entries WHERE ledger_id=1').fetchone()[0]
        self.assertEqual(after, before)

    def test_member_void_is_limited_to_own_entries(self):
        with f.AccountingTest.connect() as db:
            db.execute("UPDATE ledger_access SET role='member' WHERE ledger_id=1 AND user_id=?", (self.uid,))
            other = self.insert_user(db, 'boundary_member')
            foreign_id = self.insert_entry(db, 1, other, 'expense', 1000, '他人記錄-權限測試')
            own_id = self.insert_entry(db, 1, self.uid, 'expense', 2000, '自己記錄-權限測試')
        # member 作廢他人記錄：DB 狀態不變
        location = self.post('/delete_entry.php', dict(id=str(foreign_id), ledger_id='1'))
        self.assertIn('error=1', location)
        with f.AccountingTest.connect() as db:
            self.assertEqual(db.execute('SELECT deleted FROM entries WHERE id=?', (foreign_id,)).fetchone()[0], 0)
        # member 作廢自己建立的記錄：允許並寫入 deleted=1
        location = self.post('/delete_entry.php', dict(id=str(own_id), ledger_id='1'))
        self.assertIn('success=1', location)
        with f.AccountingTest.connect() as db:
            self.assertEqual(db.execute('SELECT deleted FROM entries WHERE id=?', (own_id,)).fetchone()[0], 1)

    def test_member_edit_is_limited_to_own_entries(self):
        with f.AccountingTest.connect() as db:
            db.execute("UPDATE ledger_access SET role='member' WHERE ledger_id=1 AND user_id=?", (self.uid,))
            other = self.insert_user(db, 'boundary_editor')
            foreign_id = self.insert_entry(db, 1, other, 'expense', 1000, '他人記錄-編輯測試')
            own_id = self.insert_entry(db, 1, self.uid, 'expense', 2000, '自己記錄-編輯測試')
        fields = dict(amount='888.88', currency='TWD', exchange_rate='1.0000', entry_date='2025-02-18',
                      type='expense', category_preset='', category='餐費', note='竄改測試')
        # member 編輯他人記錄：amount_cents 不變
        location = self.post(f'/edit_entry.php?ledger_id=1&id={foreign_id}', fields)
        self.assertIn('error=1', location)
        with f.AccountingTest.connect() as db:
            self.assertEqual(db.execute('SELECT amount_cents FROM entries WHERE id=?', (foreign_id,)).fetchone()[0], 1000)
        # member 編輯自己建立的記錄：允許並寫入新金額（TWD 原幣 888.88 元 = 88888 分）
        location = self.post(f'/edit_entry.php?ledger_id=1&id={own_id}', fields)
        self.assertIn('success=1', location)
        with f.AccountingTest.connect() as db:
            self.assertEqual(db.execute('SELECT amount_cents FROM entries WHERE id=?', (own_id,)).fetchone()[0], 88888)

    def test_array_query_param_does_not_crash_ledger(self):
        status, _, _ = f.AccountingTest.request('/ledger.php?ledger_id=1&entry_date%5B%5D=x')
        self.assertNotEqual(status, 500)

    def test_viewer_on_target_ledger_cannot_pair_transfer(self):
        with f.AccountingTest.connect() as db:
            other = self.insert_user(db, 'boundary_pair_owner')
            lid = db.execute('INSERT INTO ledgers(name,owner_id) VALUES(?,?)', ('權限測試第二本', other)).lastrowid
            db.execute("INSERT INTO ledger_access(ledger_id,user_id,role) VALUES(?,?,'owner')", (lid, other))
            # fixture_admin 在帳本 1 為 owner、在第二本帳本僅為 viewer
            db.execute("INSERT INTO ledger_access(ledger_id,user_id,role) VALUES(?,?,'viewer')", (lid, self.uid))
            entry_1 = self.insert_entry(db, 1, self.uid, 'transfer', 1000, '配對來源-權限測試')
            entry_2 = self.insert_entry(db, lid, other, 'transfer', 900, '配對目標-權限測試')
        location = self.post('/transfer_pair.php', dict(action='pair_transfer', entry_id_1=str(entry_1), entry_id_2=str(entry_2)))
        self.assertIn('error=1', location)
        with f.AccountingTest.connect() as db:
            pairs = db.execute('SELECT transfer_pair_id FROM entries WHERE id IN (?, ?) ORDER BY id', (entry_1, entry_2)).fetchall()
        self.assertEqual(pairs, [(None,), (None,)])


if __name__ == '__main__':
    unittest.main(verbosity=2)
