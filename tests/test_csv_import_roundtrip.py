"""CSV export/import round-trip regressions ([#34-B1]); run with PHP_BIN."""
import secrets
import unittest
import urllib.error
import urllib.request

import test_print_and_comments as f


ENTRY_DATE = '2025-02-17'
ENTRY_FIELDS = 'entry_date,type,amount_cents,category,note,currency,exchange_rate,original_amount_cents'


class CsvImportRoundtripCase(unittest.TestCase):
    def setUp(self):
        Fixture = f.AccountingTest
        self.addCleanup(Fixture.doClassCleanups)
        Fixture.setUpClass()
        with Fixture.connect() as db:
            host = db.execute('SELECT user_id FROM entries WHERE id=1').fetchone()[0]
            # 匯出以年月篩選；將既有兩筆記錄放在同一個測試月份。
            db.execute('UPDATE entries SET entry_date=? WHERE ledger_id=1', (ENTRY_DATE,))
            db.executemany(
                'INSERT INTO entries(ledger_id,user_id,amount_cents,type,category,entry_date,note,currency,exchange_rate,original_amount_cents)'
                ' VALUES(1,?,?,?,?,?,?,?,?,?)',
                [
                    (host, 123450, 'expense', '餐費', ENTRY_DATE, '=1+1', 'TWD', 10000, 123450),
                    (host, 500, 'expense', '-負號', ENTRY_DATE, '分類前綴', 'TWD', 10000, 500),
                ],
            )

    def post_csv(self, csv_bytes):
        Fixture = f.AccountingTest
        boundary = '----CsvImport' + secrets.token_hex(16)
        parts = [
            f'--{boundary}\r\nContent-Disposition: form-data; name="csrf_token"\r\n\r\n{Fixture.csrf}\r\n'.encode(),
            f'--{boundary}\r\nContent-Disposition: form-data; name="csv_file"; filename="roundtrip.csv"\r\nContent-Type: text/csv\r\n\r\n'.encode(),
            csv_bytes,
            f'\r\n--{boundary}--\r\n'.encode(),
        ]
        request = urllib.request.Request(
            Fixture.base + '/import_csv.php?ledger_id=1',
            data=b''.join(parts),
            headers={'Content-Type': f'multipart/form-data; boundary={boundary}'},
        )
        try:
            response = Fixture.opener.open(request, timeout=5)
        except urllib.error.HTTPError as exc:
            response = exc
        with response:
            return response.status, response.read().decode('utf-8')

    def test_exported_csv_reimports_all_fields_exactly(self):
        Fixture = f.AccountingTest
        status, headers, csv_text = Fixture.request('/export_csv.php?ledger_id=1&format=csv&ym=2025-02')
        self.assertEqual(status, 200)
        self.assertEqual(headers.get_content_type(), 'text/csv')
        self.assertTrue(csv_text.startswith('\ufeff'))

        with Fixture.connect() as db:
            expected = set(db.execute(
                f'SELECT {ENTRY_FIELDS} FROM entries WHERE ledger_id=1 AND deleted=0'
            ).fetchall())
            self.assertEqual(len(expected), 4)
            db.execute('UPDATE entries SET deleted=1 WHERE ledger_id=1')

        status, body = self.post_csv(csv_text.encode('utf-8'))
        self.assertEqual(status, 200)
        self.assertIn('匯入完成：共 4 筆記錄已成功匯入。', body)
        with Fixture.connect() as db:
            actual = set(db.execute(
                f'SELECT {ENTRY_FIELDS} FROM entries WHERE ledger_id=1 AND deleted=0'
            ).fetchall())
        self.assertEqual(actual, expected)

    def test_legacy_csv_rejects_zero_and_imports_valid_row(self):
        Fixture = f.AccountingTest
        legacy_csv = (
            '\ufeff日期,類型,金額,分類,記錄者,備註\r\n'
            '2025-02-18,支出,0,餐費,fixture_admin,零金額\r\n'
            '2025-02-18,支出,88.50,餐費,fixture_admin,舊版有效列\r\n'
        ).encode('utf-8')
        with Fixture.connect() as db:
            before = db.execute('SELECT COUNT(*) FROM entries WHERE ledger_id=1').fetchone()[0]

        status, body = self.post_csv(legacy_csv)
        self.assertEqual(status, 200)
        self.assertIn('金額必須大於 0', body)
        self.assertIn('成功 1 筆，失敗 1 筆', body)
        with Fixture.connect() as db:
            self.assertEqual(db.execute('SELECT COUNT(*) FROM entries WHERE ledger_id=1').fetchone()[0], before + 1)
            row = db.execute(
                f'SELECT {ENTRY_FIELDS} FROM entries WHERE ledger_id=1 AND note=? AND deleted=0',
                ('舊版有效列',),
            ).fetchone()
            self.assertEqual(row, ('2025-02-18', 'expense', 8850, '餐費', '舊版有效列', 'TWD', 10000, 8850))
            self.assertIsNone(db.execute('SELECT id FROM entries WHERE note=?', ('零金額',)).fetchone())


if __name__ == '__main__':
    unittest.main(verbosity=2)
