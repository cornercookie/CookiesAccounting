# 全家人記帳系統 — Task 3 後台管理（多使用者 + 管理員 + 共同帳本）

## 1. 新增/變更檔案

| 檔案 | 說明 |
|------|------|
| `admin/users.php` | 後台使用者管理（僅管理員）：列出所有使用者；建立、重設密碼、變更角色、刪除使用者 |
| `admin/ledgers.php` | 後台共同帳本管理（僅管理員）：列出所有帳本；建立帳本；每個帳本的存取權限新增/更新/移除 |
| `dashboard.php` | 增加管理員導覽連結（後台使用者管理 / 後台帳本管理，僅 `role === admin` 時顯示） |
| `TASK3_NOTES.md` | 本說明 |

## 2. 網址與存取

- 後台使用者管理：`./admin/users.php`
- 後台帳本管理：`./admin/ledgers.php`
- 兩頁均於最開頭呼叫 `requireAdmin()`；非管理員或未登入會導向 `./login.php`
- 入口：登入後於儀表板（`dashboard.php`）的「身份：管理員」區塊看到後台連結

## 3. admin/users.php 規則

- 列出所有使用者：id、帳號、角色（管理員／成員）、建立時間
- **新增使用者**：帳號（2–32 字元，英數字與底線）、密碼（至少 6 字元）、角色（admin / member）；帳號重複時拒絕
- **重設密碼**：每一列使用者各自表單，輸入新密碼後重設
- **變更角色**：每一列使用者下拉選單切換角色；不可變更自己的角色；不可將最後一位管理員改為成員
- **刪除使用者**：不可刪除自己；不可刪除最後一位管理員；刪除時會同步移除其 `ledger_access` 與 `entries`（預備陳述式、交易）
- 每張表單皆有 `csrf_token` 隱藏欄，POST 時以 `checkCsrf()` 驗證；失敗則導回 `?error=1`
- 所有操作後以 `redirect('./users.php?success=1')` / `?error=1`（帶 `msg` 說明）導回，訊息經 `h()` 轉義

## 4. admin/ledgers.php 規則

- 列出所有帳本：id、名稱、擁有者帳號、建立時間
- **新增帳本**：名稱、擁有者（所有使用者下拉選單）；建立帳本同時為擁有者建立 `ledger_access`（role = owner）
- **每個帳本的存取管理**：顯示目前存取列表（使用者、身份、是否為擁有者）；「新增/更新存取」表單（使用者下拉選單、身份 owner / member / viewer），已存在則更新角色，不存在則新增
- **移除存取**：每一列有移除表單（確認對話框）；**不得移除帳本擁有者本身的存取權限**（伺服器端強制）；帳本擁有者身份一律固定為 owner，不得變更
- 每張表單皆有 `csrf_token`，POST 時驗證；操作後以 `redirect('./ledgers.php?success=1')` / `?error=1` 導回

## 5. 維持的專案硬性約束

- `declare(strict_types=1)`，`require_once __DIR__ . '/../functions.php'`
- `requireAdmin()`；全部輸出經 `h()` 轉義（XSS 防護）
- 所有查詢皆使用 PDO 預備陳述式（具名佔位符），不拼字串 SQL；`pdo_sqlite`、`ATTR_EMULATE_PREPARES=false`
- `PRAGMA journal_mode=WAL` + `busy_timeout=5000`（由 `db.php` / `getPdo()` 提供）
- 相對路徑 `./`（跨目錄用 `../`，如 `../assets/style.css`、`../dashboard.php`）
- 不使用 FLOAT（本後台不處理金額；系統金額皆以整數分儲存）
- Session 登入、`csrf_token` 於登入成功時產生

## 6. 注意事項（本機未執行）

本機沒有 PHP 執行環境（`php` 指令不存在），故未能以 `php -l` 或內建伺服器執行驗證；已以程式檢查新增/變更的 PHP 檔案大括號、括號、方括號配對正確。部署至 Hostinger 後，請以實際登入管理員帳號驗證：建立成員、重設密碼、變更角色、刪除使用者（含最後一位管理員與自我刪除受阻）、建立帳本、新增/移除存取（含移除擁有者受阻）。
