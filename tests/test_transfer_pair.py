"""Transfer pair persistence and visible-state regressions; run with PHP_BIN."""
import re
import unittest
from urllib.parse import unquote_plus

import test_print_and_comments as f


class TestTransferPair(unittest.TestCase):
    """Test transfer pair persists and has visible state."""

    def setUp(self):
        Fixture = f.AccountingTest
        self.addCleanup(Fixture.doClassCleanups)
        Fixture.setUpClass()
        with Fixture.connect() as db:
            self.uid = db.execute('SELECT user_id FROM entries WHERE id=1').fetchone()[0]

    def post(self, path, fields):
        status, headers, _ = f.AccountingTest.request(path, {**fields, 'csrf_token': f.AccountingTest.csrf})
        self.assertEqual(status, 302)
        self.assertIn('success=1', headers['Location'])
        return unquote_plus(headers['Location'])

    def test_pair_persists_and_has_visible_state(self):
        with f.AccountingTest.connect() as db:
            lid = db.execute('INSERT INTO ledgers(name,owner_id) VALUES(?,?)', ('測試第二本', self.uid)).lastrowid
            db.execute("INSERT INTO ledger_access(ledger_id,user_id,role) VALUES(?,?,'owner')", (lid, self.uid))
        note_1 = '配對測試標記-1'
        note_2 = '配對測試標記-2'
        for led, amount, nt in ((1, '10.25', note_1), (lid, '9.00', note_2)):
            self.post(f'/add_entry.php?ledger_id={led}', dict(type='transfer', currency='TWD', amount=amount, entry_date='2025-02-17', category_preset='其他', note=nt))
        with f.AccountingTest.connect() as db:
            ids = [x[0] for x in db.execute('SELECT id FROM entries WHERE note IN (?, ?) ORDER BY id', (note_1, note_2))]
        self.assertEqual(len(ids), 2)
        self.assertNotIn('已配對', f.AccountingTest.request('/ledger.php?ledger_id=1&ym=2025-02&type=transfer')[2])
        location = self.post('/transfer_pair.php', dict(action='pair_transfer', entry_id_1=ids[0], entry_id_2=ids[1]))
        self.assertIn('差額 1.25', location)
        with f.AccountingTest.connect() as db:
            self.assertEqual(db.execute('SELECT id,transfer_pair_id FROM entries WHERE note IN (?, ?) ORDER BY id', (note_1, note_2)).fetchall(), [(ids[0], ids[1]), (ids[1], ids[0])])
            detail = db.execute("SELECT detail FROM audit_log WHERE action='transfer_pair' ORDER BY id DESC LIMIT 1").fetchone()[0]
            self.assertEqual(detail, f'entry_id_1={ids[0]} entry_id_2={ids[1]} diff_cents=125')
        for led, own_note, other_note in ((1, note_1, note_2), (lid, note_2, note_1)):
            status, _, body = f.AccountingTest.request(f'/ledger.php?ledger_id={led}&ym=2025-02&type=transfer')
            self.assertEqual(status, 200)
            row = next(tr for tr in re.findall(r'<tr\b[^>]*>.*?</tr>', body, re.S) if own_note in tr)
            self.assertIn('已配對', row)
            self.assertNotIn(other_note, row)
        pair_page = f.AccountingTest.request('/transfer_pair.php')[2]
        self.assertNotIn(note_1, pair_page)
        self.assertNotIn(note_2, pair_page)


if __name__ == '__main__':
    unittest.main()