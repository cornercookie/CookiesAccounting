"""Exercise PHP/SQLite under Apache at a subdirectory, with disposable data only.
Run: python tests/apache_smoke.py (Docker and php:8.4-apache are required locally).
"""
import base64
import csv
import html
from http.cookiejar import CookieJar, DefaultCookiePolicy
import io
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import subprocess
import tempfile
import time
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode, urljoin
from urllib.request import Request, HTTPRedirectHandler, HTTPCookieProcessor, build_opener

ROOT = Path(__file__).resolve().parents[1]


def docker(*args):
    return subprocess.run(['docker', *args], capture_output=True, check=True, timeout=45).stdout.decode().strip()


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class LocalOnlyCookiePolicy(DefaultCookiePolicy):
    # This test client only calls the disposable 127.0.0.1 Apache over HTTP.
    # Production continues sending Secure cookies and no code/config is patched.
    def __init__(self):
        super().__init__(secure_protocols=('https', 'wss', 'http'))


def client(base):
    assert base.startswith('http://127.0.0.1:')
    opener = build_opener(NoRedirect(), HTTPCookieProcessor(CookieJar(policy=LocalOnlyCookiePolicy())))

    def request(path, data=None, content_type=None):
        assert not path.startswith('/')
        headers = {}
        if isinstance(data, dict):
            data = urlencode(data).encode()
            content_type = 'application/x-www-form-urlencoded'
        if content_type:
            headers['Content-Type'] = content_type
        try:
            response = opener.open(Request(base + path, data, headers), timeout=6)
        except HTTPError as exc:
            response = exc
        with response:
            return response.status, response.headers, response.read()

    return request


def token(body):
    match = re.search(rb'name="csrf_token"\s+value="([^"]+)"', body)
    assert match, 'Missing CSRF form field'
    return match.group(1).decode()


def ok_redirect(response, expected):
    status, headers, _ = response
    assert status == 302 and expected in headers.get('Location', ''), (status, headers.get('Location'), expected)
    return headers['Location']


def posted_file(fields, key, name, mime, data):
    boundary = secrets.token_hex(16)
    parts = [f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode() for k, v in fields.items()]
    parts += [f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"; filename="{name}"\r\nContent-Type: {mime}\r\n\r\n'.encode(), data, f'\r\n--{boundary}--\r\n'.encode()]
    return b''.join(parts), f'multipart/form-data; boundary={boundary}'


def main():
    image = os.environ.get('PHP_APACHE_IMAGE', 'php:8.4-apache')
    password = 'Fixture-' + secrets.token_urlsafe(24) + '!1'
    member_password = 'Viewer-' + secrets.token_urlsafe(24) + '!1'
    name = 'cookies-smoke-' + secrets.token_hex(6)
    with tempfile.TemporaryDirectory(dir=os.environ.get('HERMES_TEST_TMP') or None) as temp:
        root = Path(temp) / 'accounting'
        root.mkdir()
        for source in [*ROOT.glob('*.php'), ROOT / '.htaccess']:
            shutil.copy2(source, root)
        for folder in ('admin', 'assets'):
            shutil.copytree(ROOT / folder, root / folder)
        (root / 'private_data').mkdir()
        shutil.copy2(ROOT / 'private_data/.htaccess', root / 'private_data/.htaccess')
        (root / '.env').write_text(f'ADMIN_USERNAME=fixture_admin\nADMIN_PASSWORD={password}\nGEMINI_API_KEY=\n', encoding='utf-8')
        docker('run', '--rm', '--detach', '--name', name, '--publish', '127.0.0.1::80', image)
        try:
            docker('cp', str(root), name + ':/var/www/html/')
            docker('exec', name, 'sh', '-ec', "printf '<Directory /var/www/html/accounting>\\nAllowOverride All\\n</Directory>\\n' > /etc/apache2/conf-enabled/accounting-fixture.conf; chown -R www-data:www-data /var/www/html/accounting; apache2ctl -k graceful")
            port = docker('port', name, '80/tcp').split(':')[-1]
            base = f'http://127.0.0.1:{port}/accounting/'
            guest = client(base)
            for _ in range(25):
                try:
                    status, headers, body = guest('login.php')
                    if status == 200:
                        break
                except URLError:
                    pass
                time.sleep(.2)
            else:
                raise AssertionError('Apache fixture login page unavailable')
            assert 'secure' in headers['Set-Cookie'].lower()
            for path in ('.env', 'private_data/.htaccess'):
                assert guest(path)[0] == 403, path
            ok_redirect(guest('ledger.php?ledger_id=1'), 'login.php')
            bad = guest('login.php', {'username': 'fixture_admin', 'password': 'wrong', 'csrf_token': token(body)})
            assert bad[0] == 200 and '帳號或密碼錯誤'.encode() in bad[2]
            print('OK Apache subfolder, Secure cookie, anonymous rejection, wrong password')
            admin = guest
            ok_redirect(admin('login.php', {'username': 'fixture_admin', 'password': password, 'csrf_token': token(bad[2])}), 'dashboard.php')
            assert admin('private_data/cookies_xxxx.db')[0] == 403
            assert admin('private_data/error.log')[0] == 403
            print('OK .env, SQLite and logs cannot be served')

            def query(sql):
                # Fixed SQL in the disposable test container; no app secrets read.
                php = '$db = new PDO("sqlite:/var/www/html/accounting/private_data/cookies_xxxx.db"); echo json_encode($db->query($argv[1])->fetchAll(PDO::FETCH_NUM));'
                return json.loads(docker('exec', name, 'php', '-r', php, '--', sql))

            form = 'add_entry.php?ledger_id=1'
            csrf = token(admin(form)[2])
            fields = {'type': 'expense', 'amount': '10.25', 'entry_date': '2025-02-17', 'category_preset': '__custom__', 'category': '測試分類', 'note': '阿帕契測試', 'currency': 'USD', 'exchange_rate': '32.5000'}
            blocked = admin(form, fields)
            assert blocked[0] == 200 and '請求驗證失敗'.encode() in blocked[2]
            assert query("SELECT id FROM entries WHERE note='阿帕契測試'") == []
            fields['csrf_token'] = csrf
            png = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jvZkAAAAASUVORK5CYII=')
            data, mime = posted_file(fields, 'receipt', 'fixture.png', 'image/png', png)
            ok_redirect(admin(form, data, mime), 'success=1')
            rows = query("SELECT id, amount_cents, original_amount_cents, exchange_rate, category FROM entries WHERE note='阿帕契測試'")
            assert len(rows) == 1 and rows[0][1:] == [33313, 1025, 325000, '測試分類'], rows
            eid = rows[0][0]
            receipt = admin(f'view_receipt.php?id={eid}&mode=inline')
            assert receipt[0] == 200 and receipt[2] == png, receipt[0]
            ledger_page = admin('ledger.php?ledger_id=1&ym=2025-02')
            assert ledger_page[0] == 200, ledger_page[0]
            thumb = re.search(rb'<a href="([^"]*view_receipt\.php\?id=' + str(eid).encode() + rb'[^"]*)" class="receipt-thumb"', ledger_page[2])
            assert thumb, 'Missing receipt-thumb anchor for uploaded entry'
            href = html.unescape(thumb.group(1).decode())
            expected = f'view_receipt.php?id={eid}&mode=inline'
            assert href == './' + expected, href
            inline = admin(expected)
            assert inline[0] == 200 and inline[1].get_content_type() == 'image/png', inline[0]
            assert inline[1]['Content-Disposition'].startswith('inline'), inline[1]['Content-Disposition']
            assert inline[2] == png
            default = admin(f'view_receipt.php?id={eid}')
            assert default[0] == 200 and default[1]['Content-Disposition'].startswith('attachment'), default[0]
            assert default[2] == png
            edit = f'edit_entry.php?ledger_id=1&id={eid}'
            ok_redirect(admin(edit, {**fields, 'amount': '11.25'}), 'success=1')
            assert query(f'SELECT amount_cents FROM entries WHERE id={eid}') == [[36563]]
            print('OK CSRF rejection, add, edit, integer-cent rounding, receipt upload and protected read')

            recurring = 'ledger.php?ledger_id=1'
            recurring_fields = {'csrf_token': csrf, 'action': 'create_recurring', 'name': '測試固定開銷', 'amount': '32.50', 'category': '餐費', 'type': 'expense', 'start_date': '2027-02-01', 'next_due_date': '2027-02-02'}
            ok_redirect(admin(recurring, recurring_fields), 'success=1')
            rid = query("SELECT id FROM recurring_entries WHERE name='測試固定開銷'")[0][0]
            ok_redirect(admin(recurring, {**recurring_fields, 'action': 'edit_recurring', 'recurring_id': rid, 'amount':'41.10'}), 'success=1')
            assert query(f'SELECT amount_cents FROM recurring_entries WHERE id={rid}') == [[4110]]
            print('OK recurring create and edit persisted')

            data, mime = posted_file({'csrf_token': csrf}, 'csv_file', 'fixture.csv', 'text/csv', '日期,類型,金額,分類,記錄者,備註\n2025-02-18,支出,88.50,餐費,fixture_admin,匯入測試\n2025-02-18,不存在,1,餐費,fixture_admin,不可匯入\n'.encode())
            imported = admin('import_csv.php?ledger_id=1', data, mime)
            assert imported[0] == 200
            assert query("SELECT amount_cents FROM entries WHERE note='匯入測試'") == [[8850]]
            assert query("SELECT id FROM entries WHERE note='不可匯入'") == []
            _, _, raw = admin('export_csv.php?ledger_id=1&format=csv&ym=2025-02')
            assert raw.startswith(b'\xef\xbb\xbf')
            assert any('匯入測試' in row for row in csv.reader(io.StringIO(raw.decode('utf-8-sig'))))
            status, hdr, raw = admin('export_csv.php?ledger_id=1&format=json&ym=2025-02')
            assert status == 200 and hdr.get_content_type() == 'application/json' and '匯入測試' in json.dumps(json.loads(raw), ensure_ascii=False)
            for path in ('export_pdf.php?ledger_id=1&ym=2025-02', f'export_pdf.php?ledger_id=1&entry={eid}'):
                status, hdr, raw = admin(path)
                assert status == 200 and hdr.get_content_type() == 'text/html' and b'data-print-page' in raw
            print('OK multipart CSV import, invalid row omitted, CSV/JSON export, HTML printing')

            for path, title in [('yearly_report.php?ledger_id=1', '年度報表'), ('quarterly_report.php?ledger_id=1', '季度報表'), ('calendar.php?ledger_id=1&ym=2025-02', '行事曆'), ('comparison_report.php?ledger_id=1', '比較報表'), ('category_budget.php?ledger_id=1','類別預算')]:
                status, _, raw = admin(path)
                assert status == 200 and title.encode() in raw, path
            assert '匯入測試'.encode() in admin('search.php?q=' + urlencode({'q':'匯入測試'}).split('=',1)[1])[2]
            print('OK report routes render, search finds persisted note')

            # [#27] 類別預算編輯回填值不得帶千分位：1234.56 必須原樣出現在 editable 欄位並可原樣提交
            budget_fields = {'csrf_token': csrf, 'action': 'create', 'category': '預算類別', 'monthly_budget': '1234.56'}
            ok_redirect(admin('category_budget.php?ledger_id=1', budget_fields), 'success=1')
            rows = query("SELECT id, monthly_budget_cents FROM category_budgets WHERE category='預算類別' AND is_active = 1")
            assert len(rows) == 1 and rows[0][1] == 123456, rows
            bid = rows[0][0]
            status, _, raw = admin(f'category_budget.php?ledger_id=1&edit_id={bid}')
            assert status == 200
            match = re.search(rb'name="monthly_budget"\s+[^>]*value="([^"]*)"', raw)
            assert match, 'Missing monthly_budget input field'
            assert match.group(1).decode() == '1234.56', match.group(1)
            ok_redirect(admin('category_budget.php?ledger_id=1', {
                'csrf_token': token(raw), 'action': 'update', 'id': bid,
                'category': '預算類別', 'monthly_budget': '1234.56',
            }), 'success=1')
            assert query(f"SELECT monthly_budget_cents FROM category_budgets WHERE id={bid}") == [[123456]]
            print('OK category budget edit prefill ungrouped and resubmit unchanged')

            ok_redirect(admin('admin/users.php', {'csrf_token': csrf, 'action': 'create_user', 'username': 'fixture_viewer', 'password': member_password, 'role': 'member'}), 'success=1')
            viewer_id = query("SELECT id FROM users WHERE username='fixture_viewer'")[0][0]
            ok_redirect(admin('admin/ledgers.php', {'csrf_token': csrf, 'action': 'add_access', 'ledger_id': '1', 'user_id': viewer_id, 'role': 'viewer'}), 'success=1')
            viewer = client(base)
            ok_redirect(viewer('login.php', {'username': 'fixture_viewer', 'password': member_password, 'csrf_token': token(viewer('login.php')[2])}), 'dashboard.php')
            viewer_token = token(viewer('change_password.php')[2])
            denied = viewer(form, {**fields, 'note':'檢視者不能寫入', 'csrf_token': viewer_token})
            assert (denied[0] == 200 and '無法新增記錄'.encode() in denied[2]) or (denied[0] == 302 and 'error=1' in denied[1].get('Location','')), denied[0]
            assert query("SELECT id FROM entries WHERE note='檢視者不能寫入'") == []
            assert viewer('category_budget.php?ledger_id=1')[0] == 200
            assert viewer('admin/users.php')[0] in (302,403)
            print('OK admin creates viewer, viewer can read budget but not write or access admin')

            ok_redirect(admin('delete_entry.php', {'csrf_token': csrf, 'id': eid, 'ledger_id': 1}), 'success=1')
            assert query(f'SELECT deleted FROM entries WHERE id={eid}') == [[1]]
            assert admin(f'export_pdf.php?ledger_id=1&entry={eid}')[0] == 404
            logout = admin('logout.php')
            assert logout[0] == 200 and '確定要登出嗎'.encode() in logout[2]
            ok_redirect(admin('logout.php', {'csrf_token': token(logout[2])}), 'login.php')
            ok_redirect(admin('dashboard.php'), 'login.php')
            print('OK soft-delete persisted, deleted print denied, logout')
        finally:
            docker('stop', name)


if __name__ == '__main__':
    main()
