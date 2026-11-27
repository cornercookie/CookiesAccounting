"""Reviewer-local HTTP test of the legacy multi-currency entry redirect on a disposable fixture."""
import unittest
import urllib.parse
from html.parser import HTMLParser

import test_print_and_comments as fixture


class FormProbe(HTMLParser):
    """Collect the POST form action, selected option values and input values by name."""

    def __init__(self):
        super().__init__()
        self.action = None
        self._picked = False
        self.selected = {}
        self.inputs = {}
        self._select = None
        self._inside = False

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'form' and not self._picked and attrs.get('method', '').upper() == 'POST':
            self._picked = True
            self.action = attrs.get('action')
            self._inside = True
            return
        if not self._inside:
            return
        if tag == 'select':
            self._select = attrs.get('name')
        elif tag == 'option':
            if 'selected' in attrs and self._select is not None:
                self.selected[self._select] = attrs.get('value')
        elif tag == 'input':
            name = attrs.get('name')
            if name is not None:
                self.inputs[name] = attrs.get('value')

    def handle_endtag(self, tag):
        if tag == 'form' and self._inside:
            self._inside = False
            self._select = None
        elif tag == 'select':
            self._select = None


class LegacyCurrencyEntryCase(unittest.TestCase):
    def setUp(self):
        Fixture = fixture.AccountingTest
        self.addCleanup(Fixture.doClassCleanups)
        Fixture.setUpClass()

    def test_legacy_currency_entry_redirects_and_persists(self):
        Fixture = fixture.AccountingTest
        query = 'ledger_id=1&currency=USD&amount=10.25&rate=32.5000&entry_date=2025-02-17&type=income'
        status, headers, _ = Fixture.request('/multi_currency.php?' + query)
        self.assertEqual(status, 302)
        location = headers['Location']
        parts = urllib.parse.urlsplit(location)
        self.assertEqual(parts.scheme, '')
        self.assertEqual(parts.netloc, '')
        self.assertEqual(parts.fragment, '')
        self.assertEqual(parts.path, './add_entry.php')
        self.assertEqual(urllib.parse.parse_qs(parts.query, keep_blank_values=True), {
            'ledger_id': ['1'],
            'currency': ['USD'],
            'amount': ['10.25'],
            'rate': ['32.5000'],
            'entry_date': ['2025-02-17'],
            'type': ['income'],
        })

        status, _, body = Fixture.request('/add_entry.php?' + parts.query)
        self.assertEqual(status, 200)
        probe = FormProbe()
        probe.feed(body)
        self.assertEqual(probe.selected.get('currency'), 'USD')
        self.assertEqual(probe.selected.get('type'), 'income')
        self.assertEqual(probe.inputs.get('amount'), '10.25')
        self.assertEqual(probe.inputs.get('exchange_rate'), '32.5000')
        self.assertEqual(probe.inputs.get('entry_date'), '2025-02-17')
        csrf_token = probe.inputs.get('csrf_token')
        self.assertTrue(csrf_token)

        action = urllib.parse.urlsplit(probe.action)
        self.assertEqual(action.scheme, '')
        self.assertEqual(action.netloc, '')
        self.assertEqual(action.fragment, '')
        self.assertEqual(action.path, './add_entry.php')
        self.assertEqual(urllib.parse.parse_qs(action.query, keep_blank_values=True), {'ledger_id': ['1']})
        submit_path = '/' + action.path.removeprefix('./') + '?' + action.query
        fields = {
            'csrf_token': csrf_token,
            'amount': '10.25',
            'currency': 'USD',
            'exchange_rate': '32.5000',
            'entry_date': '2025-02-17',
            'type': 'income',
            'category_preset': '__custom__',
            'category': 'QA Merge Entry',
            'note': 'QA legacy foreign bookmark',
        }
        status, headers, _ = Fixture.request(submit_path, fields)
        self.assertEqual(status, 302)
        self.assertIn('success=1', headers['Location'])

        with Fixture.connect() as db:
            rows = db.execute(
                'SELECT amount_cents, original_amount_cents, exchange_rate, currency, type, entry_date '
                'FROM entries WHERE note = ?',
                ('QA legacy foreign bookmark',),
            ).fetchall()
        self.assertEqual(rows, [(33313, 1025, 325000, 'USD', 'income', '2025-02-17')])


if __name__ == '__main__':
    unittest.main()