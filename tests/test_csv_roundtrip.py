"""Reviewer-local data-fidelity test for the CSV export round-trip."""
import csv
import io
import json
import unittest
import test_print_and_comments as f


CATEGORY = '茶飲 & 點心'
NOTE = '合記,含"雙引號" & <角括號> 標記\n檔案路徑 C:\\Users\\測試\\note.txt'
NOTE_MARKER = '合記'
ENTRY_DATE = '2025-02-17'
EXPECTED_KEYS = ['日期', '類型', '原幣別', '原幣金額', '匯率', '台幣金額 (元)', '分類', '記錄者', '備註']


class CsvRoundtripCase(unittest.TestCase):
    def setUp(self):
        Fixture = f.AccountingTest
        self.addCleanup(Fixture.doClassCleanups)
        Fixture.setUpClass()

    def test_csv_roundtrip_preserves_category_and_note(self):
        Fixture = f.AccountingTest
        with Fixture.connect() as db:
            db.execute('UPDATE entries SET category=?,note=? WHERE id=1', (CATEGORY, NOTE))

        status, headers, text = Fixture.request('/export_csv.php?ledger_id=1&ym=2025-02')
        self.assertEqual(status, 200)
        self.assertEqual(headers.get_content_type(), 'text/csv')
        self.assertTrue(text.startswith('\ufeff'))

        reader = csv.DictReader(io.StringIO(text[1:]), strict=True)
        self.assertEqual(list(reader.fieldnames or ()), EXPECTED_KEYS)
        row = next(
            r for r in reader
            if r['日期'] == ENTRY_DATE and NOTE_MARKER in r['備註']
        )
        self.assertEqual(row['分類'], CATEGORY)
        self.assertEqual(row['備註'], NOTE)

        status, _, json_text = Fixture.request('/export_csv.php?ledger_id=1&ym=2025-02&format=json')
        self.assertEqual(status, 200)
        data = json.loads(json_text)
        rows = data if isinstance(data, list) else next(
            value for value in data.values() if isinstance(value, list)
        )
        control = next(
            r for r in rows
            if r['日期'] == ENTRY_DATE and NOTE_MARKER in r['備註']
        )
        self.assertEqual(control['分類'], row['分類'])
        self.assertEqual(control['備註'], row['備註'])


if __name__ == '__main__':
    unittest.main()