# AGENTS.md - 專案通用開發規則

> 此檔案僅放置**跨任務都適用的通用規則**。專案具體知識（架構、資料庫、業務邏輯、權限等）請參考 [Agents/](./Agents/) 資料夾內的文件。

---

## 專案概覽

- **專案名稱**：全家人記帳系統
- **技術棧**：純 PHP 8.1+ + SQLite (WAL 模式) + 原生 HTML/CSS/JS
- **部署目標**：cPanel 主機（PHP 8.1+、`pdo_sqlite`），無 Composer、無 Node.js
- **資料庫**：`private_data/*.db`（含 `-wal` / `-shm`），由 `.htaccess` 封鎖直接存取
- **介面語言**：繁體中文 (zh-TW)，時區 Asia/Taipei

---

## 硬性約束（不可違反）

| 規則 | 說明 |
|------|------|
| **PDO 預備陳述式** | 所有查詢必須使用 PDO Prepared Statements，具名佔位符，`ATTR_EMULATE_PREPARES=false` |
| **整數分金額** | 金額以整數分 (`amount_cents`) 儲存，顯示時除以 100，**禁用 FLOAT** |
| **CSRF 防護** | 所有狀態變更 POST 請求皆需 CSRF Token，以 `hash_equals()` 恆時比較 |
| **輸出轉義** | 所有輸出經 `h()` (`htmlspecialchars` + `ENT_QUOTES \| ENT_SUBSTITUTE`, UTF-8) 轉義 |
| **相對路徑** | 所有路徑為相對路徑（`./` 開頭） |
| **Session 登入** | 使用 `requireLogin()` 檢查 `$_SESSION['user_id']`，登入後 `session_regenerate_id(true)` |
| **權限模型** | 透過 `ledger_access` 表做 IDOR 防護，角色：`owner` / `member` / `viewer` |
| **審計日誌** | 關鍵操作寫入 `audit_log` 表，僅新增不刪除 |

---

## 開發流程規範

### 任務編號規範

> **新任務請以流水號創建資料，如：`[#1]增加某個功能`**

- 所有新功能、Bug 修復、技術債務任務皆需在 Planning.md、Design.md、審查報告、Git commit 等文件中標註任務編號
- 流水號依專案全局遞增，不重置
- 任務編號格式：`[#<數字>]`，例如 `[#1]`、`[#2]`、`[#15]`

### 文件產出流程

```
Planning.md  →  Design.md（合併 Implementation.md，不再另存）
    ↓
設計階段三階段審查：Spec / Code / Security（需經獨立第三方審查通過）
    ↓
實作完成後三階段審查：Spec / Code / Security
    ↓
審查通過後更新 Design.md（同步實作細節與驗收結果）
```

### Design.md 要求

- **盡可能使用圖表**：流程圖 / 時序圖 / 架構圖（視任務挑選，不用每種都畫）
- **使用 Mermaid** 語法繪製圖表
- **每個功能各自產出** Design.md，非專案單一份文件

### 審查機制

| 階段 | 時機 | 重點 | 審查人員 |
|------|------|------|----------|
| **Spec 審查** | Design.md 產出後、實作前 | 需求完整性、規格一致性、邊界條件 | 獨立第三方 |
| **Code 審查** | 設計階段：設計可行性、資料庫變更、API 設計<br>實作階段：程式碼品質、命名、錯誤處理、效能、測試覆蓋 | 獨立第三方 |
| **Security 審查** | 設計階段：威脅模型、權限邊界、資料流<br>實作階段：SQL Injection、XSS、CSRF、權限繞過、資料外洩 | 獨立第三方 |

> **所有審查需由獨立第三方進行，不可由設計/實作同一人自審。**

### 文件結構慣例

```
CookiesAccounting/
├── *.php              # 主要頁面（入口點）
├── admin/             # 管理員後台頁面
├── assets/            # 靜態資源
│   ├── style.css      # 樣式表
│   ├── app.js         # 主要 JavaScript
│   └── ai-parse.js    # AI 解析相關
├── private_data/      # 私密資料（資料庫、錯誤日誌、上傳檔案）
│   ├── *.db           # SQLite 資料庫
│   ├── error.log      # PHP 錯誤日誌
│   └── uploads/       # 上傳檔案（收據圖片等）
├── docs/              # 專案文件
│   ├── DESIGN.md      # 設計系統主文件
│   ├── PRODUCT.md     # 產品規格
│   ├── FINAL_NOTES.md # 部署與最終說明
│   ├── PLANNING_INDEX.md # 規劃文件索引
│   └── <feature>/     # 各功能規劃與設計
│       ├── planning.md
│       └── Design.md
├── Agents/            # 專案知識庫（供 AI/開發者參考）
│   ├── service-architecture.md  # 服務架構（含前後端歸屬）
│   ├── system-architecture.md   # 系統架構圖與說明
│   ├── database-schema.md       # 資料庫結構（ERD、欄位定義、索引）
│   ├── business-logic.md        # 核心業務邏輯與規則
│   ├── user-permissions.md      # 使用者權限模型與矩陣
│   └── design-system.md         # 設計系統規範（色彩、字型、元件、版面）
├── AGENTS.md          # 本檔案（通用規則）
├── .env.example       # 環境變數範本
├── .gitignore
└── README.md
```

> **注意**：專案不維護 `CLAUDE.md`、`maintenance.md`。所有跨任務通用規則集中於 `AGENTS.md`；專案知識拆分至 `Agents/` 資料夾。

---

## 專案知識文件索引

詳細專案知識請參考 [Agents/](./Agents/) 資料夾：

| 文件 | 說明 |
|------|------|
| [Agents/service-architecture.md](./Agents/service-architecture.md) | 服務架構（含前後端歸屬） |
| [Agents/system-architecture.md](./Agents/system-architecture.md) | 系統架構圖與說明 |
| [Agents/database-schema.md](./Agents/database-schema.md) | 資料庫結構（ERD、欄位定義、索引） |
| [Agents/business-logic.md](./Agents/business-logic.md) | 核心業務邏輯與規則 |
| [Agents/user-permissions.md](./Agents/user-permissions.md) | 使用者權限模型與矩陣 |
| [Agents/design-system.md](./Agents/design-system.md) | 設計系統規範（色彩、字型、元件、版面） |

---

## 常用專案函數

| 函數 | 用途 | 定義位置 |
|------|------|----------|
| `h($str)` | HTML 輸出轉義（XSS 防護） | `functions.php` |
| `toCents($str)` | 金額字串轉整數分 | `functions.php` |
| `formatCents($cents)` | 整數分格式化為金額字串 | `functions.php` |
| `isValidDate($str)` | 日期格式驗證 (Y-m-d) | `functions.php` |
| `requireLogin()` | 登入檢查，未登入導向 login.php | `functions.php` |
| `csrfToken()` | 產生 CSRF Token | `functions.php` |
| `checkCsrf()` | 驗證 CSRF Token | `functions.php` |
| `logAudit($action, $detail)` | 寫入 audit_log | `functions.php` |
| `getPdo()` | 取得 PDO 連線 (WAL、busy_timeout) | `db.php` |

---

## 開發檢查清單（每次提交前）

- [ ] 所有 SQL 使用 PDO 預備陳述式
- [ ] 所有金額以整數分儲存/運算
- [ ] 所有 POST 表單有 CSRF Token
- [ ] 所有輸出經 `h()` 轉義
- [ ] 所有路徑使用 `./` 相對路徑
- [ ] 權限檢查透過 `ledger_access` 表
- [ ] 關鍵操作寫入 `audit_log`
- [ ] 無硬編碼密鑰/密碼（需從 `.env` 讀取）
- [ ] PHP 語法檢查通過（`php -l`）
- [ ] 手機版面測試（≥44px 觸控目標、單欄佈局）

---

## 撰寫 Planning.md 指引

> 用途是：**將開發者提供的需求整理成 `planning.md`，不自行新增需求，也不直接設計或實作。**

### 固定架構（標題保留英文、內容使用台灣繁體中文）

| 章節 | 內容 |
|---|---|
| `# [Topic]` | 一句話主題，沿用開發者用詞 |
| Description | 要解決什麼、需求範圍 |
| User Story | 角色、功能與目的；資訊不足就寫「無補充」 |
| To Reproduce | bug 的重現步驟與環境；非 bug 標示不適用 |
| Expected behavior | bug 修正後的預期行為；非 bug 指向需求與驗收章節 |
| Acceptance Criteria | 用 `- [ ]` 列出可判定通過與否的驗收條件 |
| Additional context | 補充資訊、待確認、非本期範圍 |

### 主要限制

- 開頭只加一句註解：「本文件為規劃文件，不完全是實作依據。」
- 不增刪範本章節，不加版本表等額外區塊。
- **只整理開發者提供的需求、限制與驗收要求**；不自行補功能、邊界案例或驗收條件。
- 可以讀程式與文件了解現況，但不能把讀到的內容變成新需求。
- 影響範圍或驗收的模糊事項列為「待確認」，不自行定案。
- 沿用專案存放慣例；位置或 issue 編號不明時先詢問。
- 不擅自覆寫既有 Planning；確認後，未經指示不得修改。
- 後續設計與實作交由 `dev-workflow` 技能處理。

---

## 參考技能

- 開發流程規範：`dev-workflow` 技能
- 系統文件規範：`system-documentation-standard` 技能
- 系統規格撰寫：`system-spec-writer` 技能
- 系統架構文件：`system-architecture-doc` 技能
- 系統架構圖表：`system-architecture-diagrams` 技能
- 代碼審查：`code-reviewer` / `security-reviewer` 技能

---

> **注意**：此檔案為通用規則，具體功能規劃請參考 `docs/<feature>/planning.md` 與 `docs/<feature>/Design.md`。