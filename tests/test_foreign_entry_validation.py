"""Reviewer-local HTTP test that invalid foreign-currency entries never persist."""
import unittest
import test_print_and_comments as f


class ForeignEntryValidationCase(unittest.TestCase):
    def setUp(self):
        Fixture = f.AccountingTest
        self.addCleanup(Fixture.doClassCleanups)
        Fixture.setUpClass()

    def test_invalid_foreign_entry_is_rejected_without_persisting(self):
        Fixture = f.AccountingTest
        path = '/add_entry.php?ledger_id=1&currency=USD'
        status, _, body = Fixture.request(path)
        self.assertTrue(status == 200)
        token = f.AccountingTest.token(body)
        note = 'QA invalid foreign input should not persist'
        base = {
            'csrf_token': token,
            'ledger_id': '1',
            'type': 'expense',
            'amount': '10.00',
            'exchange_rate': '32.5000',
            'entry_date': '2025-02-17',
            'currency': 'USD',
            'category_preset': '__custom__',
            'category': 'QA Invalid Foreign Input',
            'note': note,
        }
        for override, text in (
            ({'exchange_rate': '0.0000'}, '匯率格式不正確'),
            ({'exchange_rate': '10000.0001'}, '匯率格式不正確'),
            ({'amount': '0.00'}, '原幣金額不得為零'),
        ):
            with self.subTest(override=override):
                status, _, body = Fixture.request(path, {**base, **override})
                self.assertTrue(status == 200)
                self.assertTrue(text in body)
                with Fixture.connect() as db:
                    count = db.execute('SELECT COUNT(*) FROM entries WHERE note=?', (note,)).fetchone()
                self.assertEqual(count, (0,))


if __name__ == '__main__':
    unittest.main()