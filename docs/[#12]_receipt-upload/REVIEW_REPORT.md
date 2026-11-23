# 獨立第三方審查報告：[#12] 拍照收據 / 發票小圖夾

## 審查資訊

| 項目 | 內容 |
|------|------|
| 審查對象 | `docs/[#12]_receipt-upload/Design.md` (v1.0) |
| 對應規劃 | `docs/[#12]_receipt-upload/planning.md` |
| 審查角色 | 獨立第三方審查者（非設計者、非實作者） |
| 審查日期 | 2026-11-23 |
| 審查範圍 | Spec（規格完整性）、Code（程式碼品質）、Security（安全性）三階段並行審查 |

---

## 審查結論

**判定：有條件通過（Conditional Pass）**

Design.md 核心架構與安全防護（SEC-02）方向正確，但存在 **5 項阻塞交付的缺口**（標註 🔴）、**3 項建議改善**（標註 🟡）。需由實作團隊修正後再由本審查者複查，始可交付。

---

## 一、規格審查 — 關於 Planning 驗收條件完整性

| 驗收條件 (Planning §AC) | Design.md 是否涵蓋 | 實作現況 | 判定 |
|------------------------|--------------------|----------|------|
| AC1: 新增記錄表單含選填圖片上傳欄位 | ✅ §3.1 | ✅ add_entry.php, edit_entry.php | 通過 |
| AC2: 後端安全檢查、亂數重新命名、私密目錄 | ✅ §2、§3.1 | ✅ handleReceiptUpload() | 通過 |
| AC3: 明細/詳情頁透過安全路由顯示縮圖、點擊放大、未授權不可存取 | ✅ §3.2 | ⚠️ **縮圖顯示有 Bug（見下）** | 🔴 **阻塞** |
| AC4: 完整檔案大小/格式錯誤提示 | ✅ §4 | ✅ 表單錯誤回顯 | 通過 |

### 🔴 SPEC-01：縮圖顯示機制與 `view_receipt.php` 衝突（阻塞交付）

**問題**：
- Design §3.2：「在分錄表格的『備註』或獨立欄位顯示小圖圖示。點擊時經由 `view_receipt.php?id=<entry_id>` 取得大圖」
- 實作 `ledger.php:850-851` 以 `<img src="./view_receipt.php?id=...">` 嵌入縮圖
- `view_receipt.php:88` 設定 `Content-Disposition: attachment` 強制下載，**導致 `<img>` 無法顯示縮圖（瀏覽器會嘗試下載而非渲染）**

**影響**：明細頁「收據」欄位將顯示為破圖，使用者無法預覽縮圖，僅能點擊連結下載大圖。

**規格缺口**：Design 未定義「縮圖怎麼顯示」，僅說「顯示小圖圖示」，未考慮安全串流端點同時服務「縮圖內嵌」與「大圖下載」的衝突。

**建議修正方案**（擇一）：
1. `view_receipt.php` 新增查詢參數 `?mode=inline`（或 `&thumb=1`），內嵌時送 `Content-Disposition: inline`、大圖下載保持 `attachment`；
2. 或另建 `view_receipt_thumb.php` 專供縮圖，不強制下載；
3. 或前端改用 CSS `object-fit: cover` 配合固定尺寸容器，仍由同一端點提供但需解決 `attachment` 問題。

> **審查要求**：Design.md 需補充「縮圖顯示技術方案」章節，明確定義如何同時滿足「內嵌預覽」與「安全下載」兩種行為。

---

### 🔴 SPEC-02：審計日誌動作代碼未定義

**問題**：
- Design §2、§3 提到「關鍵操作寫入 audit_log」，但未列出具體 action 代碼
- 實作使用：`entry_create`、`entry_edit`、`entry_delete`、`entry_receipt_view`
- Planning SEC-02 要求「關鍵操作寫入 audit_log 表，僅新增不刪除」

**建議**：Design §4 或 §5 新增「審計日誌動作代碼對照表」，至少包含：
| 動作 | action 代碼 | detail 範例 |
|------|-------------|-------------|
| 建立記錄（含收據） | `entry_create` | `entry #123 · ledger 1 · expense · 123.45` |
| 編輯記錄（更換收據） | `entry_edit` | 同上 |
| 作廢記錄（清理收據） | `entry_delete` | `entry #123 · ledger 1` |
| 檢視收據大圖 | `entry_receipt_view` | `entry #123 · receipt private_data/uploads/abc.jpg` |

---

### 🔴 SPEC-03：Nginx 部署時私密目錄防護缺乏具體指引

**問題**：
- Design §5 註記：「部署於 Nginx 時，`.htaccess` 不生效，需於伺服器層級另行封鎖 `private_data/uploads/` 直接存取」
- 未給具體 Nginx 設定範例，導致部署者可能遺漏此關鍵防護

**建議**：補充 Nginx 設定範例：
```nginx
location /private_data/ {
    deny all;
    return 403;
}
# 或
location /private_data/uploads/ {
    internal;  # 僅允許內部 redirect（X-Accel-Redirect）存取
}
```

---

### 🔴 SPEC-04：上傳目錄 `.htaccess` 內容未定義

**問題**：
- Design §2 提到「由 `.htaccess` 拒絕直接靜態存取」
- 未給出具體 `.htaccess` 內容，實作時可能遺漏

**建議**：補充 `private_data/uploads/.htaccess` 內容：
```apache
# 禁止直接存取上傳檔案
Require all denied
# 或
Order Deny,Allow
Deny from all
```

---

### 🔴 SPEC-05：檔案權限設定未文檔化

**問題**：
- 實作 `handleReceiptUpload()` 以 `@chmod($destPath, 0640)` 設定檔案權限
- Design 未提及檔案/目錄權限要求（`0770` 目錄、`0640` 檔案）

**建議**：Design §2 補充「私密目錄權限要求」：
- `private_data/uploads/` 目錄：`0770`（擁有者為 Web 伺服器使用者）
- 上傳檔案：`0640`

---

## 二、程式碼品質審查

### 🟡 CODE-01：`handleReceiptUpload()` 重複程式碼於 `add_entry.php` 與 `edit_entry.php`

**現況**：`handleReceiptUpload()` 定義於 `add_entry.php` 內部，`edit_entry.php` 未共用（複製貼上或需 include）。

**風險**：維護兩份相同邏輯，易導致不一致（如 MIME 白名單、大小限制、命名規則）。

**建議**：將 `handleReceiptUpload()` 移至 `functions.php` 為共用函數，兩檔 `require_once` 即可。Design §5 應明確註記「共用函數位置」。

---

### 🟡 CODE-02：縮圖載入效能考量

**現況**：明細表每列收據縮圖皆以 `<img src="./view_receipt.php?id=...">` 請求完整圖片，再由瀏覽器縮放至 48×48。

**風險**：
- 原始圖可達 5MB，明細頁 50 筆 → 潛在 250MB 頻寬
- 手機網路下載緩慢，影響體驗

**建議**：
1. 上傳時同步產生縮圖（如 200×200，儲存為 `private_data/uploads/thumb_<random>.jpg`），縮圖端點回傳較小檔案；
2. 或 `view_receipt.php` 支援 `?w=200&h=200` 參數即時縮放（需 GD/Imagick）；
3. 至少在 Design 註記「已知限制：縮圖直接載入原圖，大量資料時頻寬較大」。

---

### 🟡 CODE-03：`view_receipt.php` 缺乏 `ETag` / `Last-Modified` 快取驗證

**現況**：僅送 `Cache-Control: private, max-age=3600`，無條件快取驗證標頭。

**建議**：補充 `ETag`（檔案 hash 或 mtime+size）與 `Last-Modified`，配合 `If-None-Match` / `If-Modified-Since` 減少重複下載頻寬。

---

## 三、安全性審查

### ✅ SEC-01：上傳檔案驗證完整（符合 SEC-02）

| 檢查項目 | 實作 | 判定 |
|----------|------|------|
| 上傳成功狀態（`UPLOAD_ERR_OK`） | ✅ `$error !== UPLOAD_ERR_OK` 檢查 | 通過 |
| 檔案大小上限 5MB | ✅ `$size > 5 * 1024 * 1024` | 通過 |
| MIME Type（`finfo_file`） | ✅ `image/jpeg/png/webp` 白名單 | 通過 |
| 副檔名對應 MIME（非信任原檔名） | ✅ `$allowedMime[$mime]` 映射 | 通過 |
| 亂數重新命名（`bin2hex(random_bytes(16))`） | ✅ 防 WebShell、防碰撞 | 通過 |
| 存檔後二次驗證 MIME | ✅ 防上傳過程被替換 | 通過 |
| 檔案權限 `0640` | ✅ `@chmod($destPath, 0640)` | 通過 |

### ✅ SEC-02：讀取路徑遍歷防護完整

`view_receipt.php:48-61` 三層防護：
1. `str_starts_with($receiptPath, 'private_data/uploads/')` 前綴檢查
2. `realpath()` 解析後確認仍在 `uploads/` 目錄下
3. `is_file($realPath)` 確認存在

### ✅ SEC-03：IDOR 防護完整

- 上傳/編輯/刪除：皆驗證 `ledger_access` 權限（owner/member 可寫）
- 檢視收據：`view_receipt.php` JOIN `ledger_access` 驗證用戶對該帳本有存取權
- 作廢清理：`delete_entry.php` 同樣驗證權限後再刪除實體檔

### ✅ SEC-04：CSRF 防護完整

- 所有 POST 表單含 `csrf_token` 隱藏欄位
- `checkCsrf()` 使用 `hash_equals()` 恆時比較

### ✅ SEC-05：輸出轉義完整

- 所有動態輸出經 `h()` (`htmlspecialchars` + `ENT_QUOTES | ENT_SUBSTITUTE`)

### 🔴 SEC-06：縮圖內嵌時 `Content-Disposition: attachment` 造成資安風險（低）

**問題**：若攻擊者誘導受害者造訪惡意頁面嵌入 `<img src="view_receipt.php?id=X">`，瀏覽器會自動下載收據圖片，雖然有 `Referrer-Policy: no-referrer`，但仍可能洩露收據存在性。

**現況**：實作為強制下載，縮圖又無法顯示，屬「功能缺陷」大於「資安風險」。修正 SPEC-01 時同步解決（縮圖用 `inline`、大圖保持 `attachment`）。

---

## 四、資料庫與遷移審查

| 項目 | 狀態 | 備註 |
|------|------|------|
| `entries.receipt_path` 欄位 | ✅ 已在 `db.php ensureSchema()` 以 `ALTER TABLE ADD COLUMN` 冪等遷移 | 通過 |
| 索引需求 | ℹ️ 目前查詢模式不需索引 `receipt_path` | 可接受 |
| 軟刪除時清理實體檔 | ✅ `delete_entry.php:78-87` | 通過 |
| 編輯更換收據時刪除舊檔 | ✅ `edit_entry.php:106-110` | 通過 |

---

## 五、測試計畫完整性

| 測試案例 (Design §4) | 實作覆蓋 | 判定 |
|---------------------|----------|------|
| JPEG/PNG/WebP 上傳成功 | ✅ | 通過 |
| PHP/HTML 檔被拒 | ✅ MIME 白名單 | 通過 |
| 超過 5MB 被拒 | ✅ | 通過 |
| 未登入/無權限無法存取 `view_receipt.php` | ✅ `requireLogin()` + `ledger_access` JOIN | 通過 |
| 作廢記錄關聯圖片被清理 | ✅ `delete_entry.php` | 通過 |
| 上傳失敗時表單重新顯示、提示錯誤、不靜默新增 | ✅ `add_entry.php:90-93`、`edit_entry.php:111-114` | 通過 |
| **縮圖正確顯示於明細表** | ❌ **實作有 Bug（SPEC-01）** | 🔴 **阻塞** |
| **編輯更換收據後舊檔被刪除** | ✅ | 通過 |

---

## 六、需修正清單（交付前必須完成）

| 編號 | 類型 | 說明 | 負責 | 複查標準 |
|------|------|------|------|----------|
| SPEC-01 | 🔴 阻塞 | Design 補充「縮圖顯示技術方案」，解決 `view_receipt.php` 同時服務縮圖內嵌與大圖下載的衝突 | 設計/實作 | Design 新增章節；實作修正縮圖顯示 |
| SPEC-02 | 🔴 阻塞 | Design 新增「審計日誌動作代碼對照表」 | 設計 | Design 包含表格 |
| SPEC-03 | 🔴 阻塞 | Design 補充 Nginx 部署私密目錄防護範例 | 設計 | Design 有 Nginx 設定範例 |
| SPEC-04 | 🔴 阻塞 | Design 補充 `private_data/uploads/.htaccess` 內容 | 設計 | Design 有 .htaccess 範例 |
| SPEC-05 | 🔴 阻塞 | Design 補充檔案/目錄權限要求（0770/0640） | 設計 | Design 有權限說明 |
| CODE-01 | 🟡 建議 | `handleReceiptUpload()` 移至 `functions.php` 共用 | 實作 | 兩檔共用同一函數 |
| CODE-02 | 🟡 建議 | Design 註記縮圖載入效能已知限制或規劃縮圖機制 | 設計/實作 | Design 有說明 |
| CODE-03 | 🟡 建議 | `view_receipt.php` 補充 ETag/Last-Modified | 實作 | 有條件快取標頭 |

---

## 七、複查流程

1. 實作團隊依上表修正 Design.md 與相關程式碼
2. 通知本審查者進行複查
3. 本審查者驗證所有 🔴 項目已關閉、🟡 項目已評估處理
4. 全部通過後更新 Design.md 版本號（v1.1），標記「審查通過，可交付」

---

## 附錄：實作檔案清單（供複查參考）

| 檔案 | 角色 | 關鍵函數 |
|------|------|----------|
| `add_entry.php` | 新增記錄（含收據上傳） | `handleReceiptUpload()` |
| `edit_entry.php` | 編輯記錄（含更換收據） | `handleReceiptUpload()`（複製版） |
| `view_receipt.php` | 收據安全串流（縮圖/大圖） | — |
| `delete_entry.php` | 作廢記錄（清理收據） | — |
| `ledger.php` | 明細顯示（含收據縮圖欄） | — |
| `functions.php` | 共用函數庫 | `h()`, `logAudit()`, `requireLogin()`, `checkCsrf()`, `getPdo()` |
| `db.php` | 資料庫連線與 Schema 遷移 | `ensureSchema()`（含 `receipt_path` ADD COLUMN） |
| `private_data/uploads/.htaccess` | Apache 私密目錄防護 | 需建立（Design 需定義內容） |

---

**審查者簽署**：獨立第三方審查者  
**日期**：2026-11-23  
**狀態**：有條件通過 —— 待修正上述 5 項阻塞項目後複查