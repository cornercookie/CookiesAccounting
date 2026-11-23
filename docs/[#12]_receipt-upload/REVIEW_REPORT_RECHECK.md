# 複查報告：[#12] 拍照收據 / 發票小圖夾

## 複查資訊

| 項目 | 內容 |
|------|------|
| 複查對象 | 修正後的程式碼與 `docs/[#12]_receipt-upload/Design.md` (v1.1) |
| 原審查報告 | `docs/[#12]_receipt-upload/REVIEW_REPORT.md` |
| 複查角色 | 獨立第三方審查者（同前） |
| 複查日期 | 2026-11-23 |

---

## 複查結論

**判定：全部通過，可交付**

所有 5 項 🔴 阻塞項目已修正，3 項 🟡 建議項目已處理。Design.md v1.1 完整記錄修正內容，程式碼符合 AGENTS.md 硬性約束與 SEC-02 安全要求。

---

## 逐項複查結果

### 🔴 SPEC-01：縮圖顯示機制修正 —— **已通過**

| 檢查點 | 結果 | 備註 |
|--------|------|------|
| `view_receipt.php` 支援 `mode=inline` / `mode=download` | ✅ | 第 25-28 行解析參數，第 113 行動態決定 `inline`/`attachment` |
| `ledger.php` 縮圖使用 `&mode=inline` | ✅ | 第 851 行 `<img src="./view_receipt.php?id=...&mode=inline">` |
| 大圖下載連結保持預設 `attachment` | ✅ | 第 850 行 `<a href="./view_receipt.php?id=...">` 無 mode 參數 |
| Design.md §3.1 詳細說明機制與對照表 | ✅ | 含模式、參數、Content-Disposition、用途四欄對照表 |

**驗證結論**：縮圖現在可正常內嵌顯示（`inline`），點擊連結下載大圖（`attachment`），兩種行為互不干擾。

---

### 🔴 SPEC-02：審計日誌動作代碼對照表 —— **已通過**

| 檢查點 | 結果 | 備註 |
|--------|------|------|
| Design.md §4 包含四種 action 代碼表 | ✅ | `entry_create`、`entry_edit`、`entry_delete`、`entry_receipt_view` |
| 實際程式碼使用相同代碼 | ✅ | `add_entry.php:114`、`edit_entry.php:140`、`delete_entry.php:88`、`view_receipt.php:121` |
| detail 格式一致 | ✅ | 皆含 entry #、ledger、type、amount 等關鍵資訊 |

---

### 🔴 SPEC-03：Nginx 部署私密目錄防護範例 —— **已通過**

| 檢查點 | 結果 | 備註 |
|--------|------|------|
| Design.md §6.1 提供 Nginx 設定範例 | ✅ | 方案 A（deny all）、方案 B（internal + X-Accel-Redirect） |
| 註記建議採用方案 A | ✅ | 簡單且安全 |

---

### 🔴 SPEC-04：`.htaccess` 內容範例 —— **已通過**

| 檢查點 | 結果 | 備註 |
|--------|------|------|
| Design.md §6.2 提供 .htaccess 範例 | ✅ | 含 Apache 2.4 `Require all denied` 與 2.2 相容寫法 |
| 實際 `private_data/uploads/.htaccess` 存在且內容正確 | ✅ | 已驗證檔案內容 |

---

### 🔴 SPEC-05：檔案/目錄權限要求 —— **已通過**

| 檢查點 | 結果 | 備註 |
|--------|------|------|
| Design.md §2 新增權限要求 | ✅ | 目錄 `0770`、檔案 `0640` |
| `functions.php::handleReceiptUpload()` 實作一致 | ✅ | 第 569 行 `mkdir(..., 0770)`、第 592 行 `chmod($destPath, 0640)` |

---

### 🟡 CODE-01：`handleReceiptUpload()` 移至 `functions.php` 共用 —— **已通過**

| 檢查點 | 結果 | 備註 |
|--------|------|------|
| `functions.php` 含共用函數（第 515-594 行） | ✅ | 含完整 PHPDoc、權限註記 |
| `add_entry.php` 移除區域定義 | ✅ | 第 122 行直接結束 PHP，改用共用函數 |
| `edit_entry.php` 無區域定義（已使用共用） | ✅ | 僅 `require_once functions.php` |
| 兩檔邏輯一致（MIME 白名單、大小、命名、二次驗證、權限） | ✅ | 共用同一函數保證一致 |

---

### 🟡 CODE-02：Design.md 註記縮圖載入效能已知限制 —— **已通過**

| 檢查點 | 結果 | 備註 |
|--------|------|------|
| Design.md §3.2 明確標註「已知限制」 | ✅ | 說明 50 筆 × 5MB = 250MB 潛在頻寬問題 |
| 提供未來改善方向 | ✅ | 產生縮圖、即時縮放參數兩條路徑 |

---

### 🟡 CODE-03：`view_receipt.php` 補充 ETag/Last-Modified —— **已通過**

| 檢查點 | 結果 | 備註 |
|--------|------|------|
| 計算 `ETag`（md5_file + size） | ✅ | 第 96 行 |
| 計算 `Last-Modified`（filemtime） | ✅ | 第 97 行 |
| 檢查 `If-None-Match` / `If-Modified-Since` | ✅ | 第 103-109 行，符合回傳 304 |
| 正確設定快取標頭 | ✅ | 第 99-100 行 |

---

## 程式碼品質與安全性複查

### AGENTS.md 硬性約束檢查

| 約束 | 狀態 | 驗證方式 |
|------|------|----------|
| PDO 預備陳述式 | ✅ | 所有查詢使用具名佔位符 |
| 整數分金額 | ✅ | `amount_cents`、 `toCents()`/`formatCents()` |
| CSRF 防護 | ✅ | 所有 POST 表單含 `csrf_token`、`hash_equals()` 驗證 |
| 輸出轉義 `h()` | ✅ | 所有動態輸出經 `h()` |
| 相對路徑 `./` | ✅ | 所有連結、重導向使用相對路徑 |
| Session 登入 `requireLogin()` | ✅ | 所有受保護頁面開頭呼叫 |
| 權限模型 `ledger_access` | ✅ | 所有查詢 JOIN `ledger_access` 驗證 |
| 審計日誌 `logAudit()` | ✅ | 關鍵操作皆記錄 |

### SEC-02 安全要求檢查

| 要求 | 狀態 | 實作位置 |
|------|------|----------|
| 禁止直接靜態存取 `private_data/uploads/` | ✅ | `.htaccess` + Nginx 範例 + `view_receipt.php` 路徑驗證 |
| 檔案強制重新命名（亂數） | ✅ | `bin2hex(random_bytes(16))` |
| MIME Type 嚴格檢查（finfo_file） | ✅ | 上傳時、讀取時雙重驗證 |
| 副檔名對應 MIME（不信任原檔名） | ✅ | `$allowedMime[$mime]` 映射 |
| 大小上限 5MB | ✅ | 上傳時檢查 |
| 二次驗證（存檔後再次檢查 MIME） | ✅ | `handleReceiptUpload()` 第 580-590 行 |
| 路徑遍歷防護（realpath 深度防禦） | ✅ | `view_receipt.php` 第 63-68 行 |
| 檔案權限 0640 / 目錄 0770 | ✅ | `mkdir(0770)` + `chmod(0640)` |
| 審計日誌記錄檢視操作 | ✅ | `entry_receipt_view` |

---

## 檔案變更清單

| 檔案 | 變更類型 | 主要內容 |
|------|----------|----------|
| `view_receipt.php` | 修正 | 支援 `mode=inline/download`、ETag/Last-Modified 條件快取 |
| `ledger.php` | 修正 | 縮圖 `<img>` 加上 `&mode=inline` |
| `functions.php` | 新增 | 共用 `handleReceiptUpload()`（含權限 0770/0640） |
| `add_entry.php` | 修正 | 移除區域 `handleReceiptUpload()`，改用共用函數 |
| `edit_entry.php` | 無變更 | 已使用共用函數（僅 `require_once functions.php`） |
| `private_data/uploads/.htaccess` | 既有 | Apache 防護（已就位） |
| `docs/[#12]_receipt-upload/Design.md` | 更新至 v1.1 | 完整記錄所有修正、新增代碼表、部署範例、效能備註 |

---

## 複查總結

所有阻塞項目（🔴）與建議項目（🟡）均已妥善修正並驗證通過。Design.md v1.1 完整記錄規格變更、技術決策、部署指引與審計代碼，符合專案文件規範。

**判定：可交付**

---

**複查者簽署**：獨立第三方審查者  
**日期**：2026-11-23  
**狀態**：全部通過 ✅