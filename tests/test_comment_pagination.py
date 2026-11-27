"""Reviewer-local HTTP test of comment pagination on a disposable fixture."""
import re
import unittest
import urllib.parse
import test_print_and_comments as fixture


class CommentPaginationCase(unittest.TestCase):
    def setUp(self):
        Fixture = fixture.AccountingTest
        self.addCleanup(Fixture.doClassCleanups)
        Fixture.setUpClass()

    @staticmethod
    def note_suffixes(page):
        return re.findall(r'分頁記錄-(\d{3})', page)

    @staticmethod
    def comment_suffixes(page):
        return re.findall(r'留言項目-(\d{3})', page)

    def seed(self):
        Fixture = fixture.AccountingTest
        with Fixture.connect() as db:
            uid = db.execute('SELECT user_id FROM entries WHERE id=1').fetchone()[0]
            db.executemany(
                'INSERT INTO entries (ledger_id, user_id, amount_cents, type, category, entry_date, note) '
                'VALUES (1, ?, ?, ?, ?, ?, ?)',
                [
                    (uid, 100, 'expense', '其他', '2024-07-17', f'分頁記錄-{i:03d}')
                    for i in range(55)
                ],
            )
            entry_id = db.execute("SELECT id FROM entries WHERE note=?", ("分頁記錄-054",)).fetchone()[0]
            db.executemany(
                'INSERT INTO entry_comments (entry_id, user_id, comment) VALUES (?, ?, ?)',
                [
                    (entry_id, uid, f'留言項目-{i:03d}')
                    for i in range(51)
                ],
            )

    def ledger(self, **params):
        query = urllib.parse.urlencode(params)
        return fixture.AccountingTest.request('/ledger.php?' + query)

    def test_comment_pagination(self):
        self.seed()

        status, _, body = self.ledger(ledger_id=1, ym='2024-07', type='expense', page=1)
        self.assertEqual(status, 200)
        page1_notes = self.note_suffixes(body)
        self.assertEqual(len(page1_notes), 50)
        self.assertEqual(set(page1_notes), {f'{i:03d}' for i in range(5, 55)})
        self.assertEqual(self.comment_suffixes(body), [f'{i:03d}' for i in range(50, -1, -1)])

        status, _, body = self.ledger(ledger_id=1, ym='2024-07', type='expense', page=2)
        self.assertEqual(status, 200)
        page2_notes = self.note_suffixes(body)
        self.assertEqual(len(page2_notes), 5)
        self.assertEqual(set(page2_notes), {f'{i:03d}' for i in range(5)})
        self.assertEqual(self.comment_suffixes(body), [])


if __name__ == '__main__':
    unittest.main()