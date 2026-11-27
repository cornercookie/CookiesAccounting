"""Reviewer-local HTTP test of search pagination on a disposable fixture."""
import re
import unittest
import urllib.parse
import test_print_and_comments as fixture


class SearchPaginationCase(unittest.TestCase):
    def setUp(self):
        Fixture = fixture.AccountingTest
        self.addCleanup(Fixture.doClassCleanups)
        Fixture.setUpClass()

    @staticmethod
    def note_suffixes(page):
        return re.findall(r'搜尋頁測試-(\d{3})', page)

    def seed(self):
        Fixture = fixture.AccountingTest
        with Fixture.connect() as db:
            uid = db.execute('SELECT user_id FROM entries WHERE id=1').fetchone()[0]
            db.executemany(
                'INSERT INTO entries (ledger_id, user_id, amount_cents, type, category, entry_date, note) '
                'VALUES (1, ?, ?, ?, ?, ?, ?)',
                [
                    (uid, 100 + i, 'expense', 'QA Search', '2024-06-11', f'搜尋頁測試-{i:03d}')
                    for i in range(55)
                ],
            )

    def search(self, **params):
        query = urllib.parse.urlencode(params)
        return fixture.AccountingTest.request('/search.php?' + query)

    def test_search_pagination_and_amount_filters(self):
        self.seed()

        status, _, body = self.search(keyword='搜尋頁測試', date_from='2024-06-01', date_to='2024-06-30')
        self.assertEqual(status, 200)
        page1 = self.note_suffixes(body)
        self.assertEqual(len(page1), 50)
        self.assertEqual(set(page1), {f'{i:03d}' for i in range(5, 55)})

        status, _, body = self.search(keyword='搜尋頁測試', date_from='2024-06-01', date_to='2024-06-30', page=2)
        self.assertEqual(status, 200)
        page2 = self.note_suffixes(body)
        self.assertEqual(len(page2), 5)
        self.assertEqual(set(page2), {f'{i:03d}' for i in range(5)})

        status, _, body = self.search(
            keyword='搜尋頁測試', date_from='2024-06-01', date_to='2024-06-30',
            amount_min='1.52', amount_max='1.53',
        )
        self.assertEqual(status, 200)
        ranged = self.note_suffixes(body)
        self.assertEqual(len(ranged), 2)
        self.assertEqual(set(ranged), {'052', '053'})


if __name__ == '__main__':
    unittest.main()