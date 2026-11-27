"""Entry acknowledge (confirm/dispute) regressions; run with PHP_BIN."""
import unittest
import test_print_and_comments as fixture


class TestEntryAck(unittest.TestCase):
    """Test entry acknowledge: confirm entry 1, dispute entry 2."""

    def setUp(self):
        Fixture = fixture.AccountingTest
        self.addCleanup(Fixture.doClassCleanups)
        Fixture.setUpClass()

    def test_confirm_entry_1_and_dispute_entry_2(self):
        # Obtain CSRF token from ledger page
        status, headers, body = fixture.AccountingTest.request(
            '/ledger.php?ledger_id=1&ym=2025-02'
        )
        csrf = fixture.AccountingTest.token(body)

        # Confirm entry 1 (POST with action='confirm')
        status, headers, body = fixture.AccountingTest.request(
            '/entry_ack.php',
            {'entry_id': '1', 'action': 'confirm', 'csrf_token': csrf},
        )
        self.assertEqual(status, 302)
        self.assertIn('success=1', headers['Location'])

        # Dispute entry 2 (POST with action='dispute')
        status, headers, body = fixture.AccountingTest.request(
            '/entry_ack.php',
            {'entry_id': '2', 'action': 'dispute', 'csrf_token': csrf},
        )
        self.assertEqual(status, 302)
        self.assertIn('success=1', headers['Location'])

        # Assert saved status using exact schema column names from entry_ack.php
        with fixture.AccountingTest.connect() as db:
            entry1_ack_status = db.execute(
                'SELECT ack_status FROM entries WHERE id = 1'
            ).fetchone()[0]
            self.assertEqual(entry1_ack_status, 'confirmed')

            entry2_ack_status = db.execute(
                'SELECT ack_status FROM entries WHERE id = 2'
            ).fetchone()[0]
            self.assertEqual(entry2_ack_status, 'disputed')

        # Assert relevant audit records using exact action state names from entry_ack.php
        with fixture.AccountingTest.connect() as db:
            confirm_audit = db.execute(
                'SELECT action, detail FROM audit_log '
                'WHERE action = ? ORDER BY id DESC LIMIT 1',
                ('entry_confirm',),
            ).fetchone()
            self.assertIsNotNone(confirm_audit)
            action, detail = confirm_audit
            self.assertIn('entry #1', detail)

            dispute_audit = db.execute(
                'SELECT action, detail FROM audit_log '
                'WHERE action = ? ORDER BY id DESC LIMIT 1',
                ('entry_dispute',),
            ).fetchone()
            self.assertIsNotNone(dispute_audit)
            action, detail = dispute_audit
            self.assertIn('entry #2', detail)


if __name__ == '__main__':
    unittest.main()