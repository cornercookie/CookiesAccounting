"""Reviewer-local HTTP test of [#17] calendar navigation: 本月 link resets to current month."""
import unittest
import html
import re
import urllib.parse

import test_print_and_comments as fixture


class CalendarNavigation(unittest.TestCase):
    def setUp(self):
        Fixture = fixture.AccountingTest
        self.addCleanup(Fixture.doClassCleanups)
        Fixture.setUpClass()

    def test_navigation_本月_resets_to_current_utc8_month(self):
        # GET historical 2024-12 calendar ledger_id=1
        status, _, page = fixture.AccountingTest.request(
            '/calendar.php?ym=2024-12&ledger_id=1'
        )
        self.assertEqual(status, 200)

        # Extract the HTML link labelled "本月" using a whitespace-safe regex
        match = re.search(
            r'<a\s+href="([^"]*)"[^>]*>本月</a>',
            page,
            re.DOTALL,
        )
        self.assertIsNotNone(match, '本月 link not found in page')
        raw_url = html.unescape(match.group(1))

        # Parse the URL and assert the ym parameter
        parsed = urllib.parse.urlsplit(raw_url)
        query_params = urllib.parse.parse_qs(parsed.query)
        # Preserve authorized single-ledger scope: ym should be present and ledger_id=1
        self.assertIn('ym', query_params, 'ym parameter missing from 本月 link')
        # ledger_id=1 should be preserved
        self.assertIn('ledger_id', query_params, 'ledger_id parameter missing from 本月 link')
        self.assertEqual(query_params['ledger_id'][0], '1',
                         'ledger_id should be 1')

        # Assert urlsplit result scheme and netloc empty, path exactly "./calendar.php"
        self.assertEqual(parsed.scheme, '', 'scheme should be empty')
        self.assertEqual(parsed.netloc, '', 'netloc should be empty')
        self.assertEqual(parsed.path, './calendar.php', 'path should be ./calendar.php')

        # Use now_taipei as an aware datetime object with UTC+8
        import datetime
        now_taipei = datetime.datetime.now(datetime.timezone(
            datetime.timedelta(hours=8)
        ))

        # The ym should equal current UTC+8 time formatted YYYY-MM
        ym_str = now_taipei.strftime("%Y-%m")
        self.assertEqual(
            query_params['ym'][0],
            ym_str,
            f'本月 ym={query_params["ym"][0]} should equal current UTC+8 month {ym_str}',
        )

        # Follow the relative URL via fixture.request and verify exact calendar caption
        # for current Taipei month
        follow_status, _, follow_page = fixture.AccountingTest.request(
            "/" + parsed.path.removeprefix("./") + "?" + parsed.query
        )
        self.assertEqual(follow_status, 200)
        # Verify the calendar caption shows the current Taipei month
        caption_match = re.search(
            r'<caption[^>]*id="calendar-title"[^>]*>([^<]+)</caption>',
            follow_page,
            re.DOTALL,
        )
        self.assertIsNotNone(caption_match,
                             'calendar caption not found after following 本月 link')
        caption_text = caption_match.group(1)
        expected_caption = f"{now_taipei.year:04d} 年 {now_taipei.month} 月 行事曆"
        self.assertEqual(
            caption_text,
            expected_caption,
            f'Calendar caption "{caption_text}" should equal "{expected_caption}"'
        )


if __name__ == '__main__':
    unittest.main(verbosity=2)