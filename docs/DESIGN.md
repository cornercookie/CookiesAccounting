---
name: 全家人記帳系統
description: 傳票總帳——收支各自記、帳共用看；原生會計傳票語彙的家庭共同帳本介面
colors:
  paper: "#f6f3ea"
  paper-2: "#fffdf1"
  paper-3: "#efece0"
  ink: "#23272b"
  ink-2: "#6b675a"
  ink-3: "#8f8b7d"
  line: "#d8d4c6"
  side-2: "#2e3338"
  side-dim: "#a9a495"
  red: "#c0392b"
  red-deep: "#a02c20"
  red-tint: "#f6e3df"
  blue: "#1f4e79"
  blue-deep: "#163a5b"
  blue-tint: "#e3ebf3"
  focus: "#1f4e79"
typography:
  display:
    fontFamily: "DFKai-SB, 標楷體, KaiTi, STKaiti, 'Noto Serif TC', PMingLiU, serif"
    fontSize: "1.75rem"
    fontWeight: 700
    lineHeight: 1.25
    letterSpacing: "0.02em"
  title:
    fontFamily: "DFKai-SB, 標楷體, KaiTi, STKaiti, 'Noto Serif TC', PMingLiU, serif"
    fontSize: "1.375rem"
    fontWeight: 700
    lineHeight: 1.35
  body:
    fontFamily: "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Noto Sans TC', 'Microsoft JhengHei', sans-serif"
    fontSize: "1rem"
    fontWeight: 400
    lineHeight: 1.55
  label:
    fontFamily: "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Noto Sans TC', 'Microsoft JhengHei', sans-serif"
    fontSize: "0.875rem"
    fontWeight: 700
    lineHeight: 1.4
    letterSpacing: "0.02em"
  mono:
    fontFamily: "'Courier New', Courier, ui-monospace, monospace"
    fontSize: "1rem"
    fontWeight: 700
    lineHeight: 1.5
rounded:
  chip: "2px"
  sm: "3px"
spacing:
  xs: "4px"
  sm: "8px"
  md: "12px"
  lg: "16px"
  xl: "24px"
  2xl: "32px"
  3xl: "48px"
  4xl: "64px"
components:
  button-primary:
    backgroundColor: "{colors.red}"
    textColor: "#fffdf1"
    rounded: "{rounded.sm}"
    padding: "11px 24px"
  button-primary-hover:
    backgroundColor: "{colors.red-deep}"
    textColor: "#fffdf1"
    rounded: "{rounded.sm}"
    padding: "11px 24px"
  button-secondary:
    backgroundColor: "transparent"
    textColor: "{colors.blue}"
    rounded: "{rounded.sm}"
    padding: "11px 24px"
  button-danger:
    backgroundColor: "{colors.red}"
    textColor: "#fffdf1"
    rounded: "{rounded.sm}"
    padding: "11px 24px"
  input:
    backgroundColor: "{colors.paper-2}"
    textColor: "{colors.ink}"
    rounded: "{rounded.sm}"
    padding: "12px 12px"
  plate:
    backgroundColor: "{colors.paper-2}"
    textColor: "{colors.ink}"
    rounded: "0"
    padding: "16px 16px 12px"
  chip-vno:
    backgroundColor: "{colors.blue}"
    textColor: "#fffdf1"
    rounded: "{rounded.chip}"
    padding: "2px 7px"
  chip-role:
    backgroundColor: "{colors.blue-tint}"
    textColor: "{colors.blue}"
    rounded: "{rounded.chip}"
    padding: "2px 8px"
---

# Design System: 全家人記帳系統

## Overview

**Creative North Star: "傳票總帳"**

這個系統不是一張漂亮的表單——它是一本全家共用的傳票簿。收入聯紅抬頭、支出聯藍抬頭、編號逐筆裝訂；每一筆家庭開銷都能對上號、月結核對。介面樸實實在，像抽屜裡那本真正被每天翻動的帳冊：不炫技、不粉飾，靠印刷級的分色、格線與騎縫線說話。顏色只出現在它有工作的地方——紅是收入、藍是支出、墨是殼與線；彩色頂線板塊和雙色編號 chip 是僅有的「全彩時刻」，其餘版面維持雙色印刷的克制。

密度是帳冊密度：資訊密但節奏清楚（緊湊成組、組間寬鬆、標題上方多留一拍）。長輩是第一讀者：標楷體大字、千分位等寬數字、≥44px 觸控目標、明確的焦點環，沒有依賴滑過才顯示的資訊。

**Key Characteristics:**

- 五種材料：米紙地、墨黑殼、傳票朱紅（收入）、傳票鐵藍（支出）、等寬 Courier 讀數。
- 雙色印刷紀律：彩色只進有框板塊、編號 chip 與狀態，版面主體維持墨＋紙。
- 傳票編號（`收-008` / `支-012`）是逐筆分錄的身份證，由資料庫 id 推導，不新造號碼。
- 零漸層、零投影（登入卡例外一層柔影）；深度靠色頂線與騎縫線（dashed rule）呈現。
- 標楷體顯示字＊系統黑體內文＊Courier 等寬數字三級字型。

## Colors

米紙與墨黑構成版面；紅與藍是收入／支出的語法，不是裝飾。

### Primary

- **傳票朱紅** (#c0392b): 收入側與「會生效於金錢的主動作」——立傳票、儲存記錄、建立使用者／帳本、登入；收入聯 plate 頂線、收-編號 chip、阿公級按鈕的對比色；`#a02c20` (red-deep) 為按下/hover 態。
- **傳票鐵藍** (#1f4e79): 支出側語法——支出 plate 頂線、支-編號 chip、金額支出欄、焦點環（`#1f4e79`）與連結；`#163a5b` (blue-deep) 為按下態；`#e3ebf3` (blue-tint) 為 member chip 底、次要按鈕 hover 底。

### Neutral

- **帳冊米紙** (#f6f3ea): 全站地（`body`/`.main`）。
- **傳票米紙** (#fffdf1): 內容面——plates、表格、輸入框、快速入口卡、登入卡。
- **暗米樣板** (#efece0): 樣板/role tag 底色、警示成功態（`#eaf1e6` 用於成功條）。
- **碳墨** (#23272b): 側欄與表格抬頭、h/標題、主按鈕（ink 版）、焦點對比基準；`#2e3338` 為側欄列 hover/active。
- **輔助灰** (#6b675a): 次要內文、標籤；`#8f8b7d` (ink-3) 為側欄小標與提示。
- **細線** (#d8d4c6): 所有邊框與騎縫線；`#a9a495` (side-dim) 為側欄次要文字。

### Named Rules

**The Two-Ink Rule.** 彩色只服務「收/支」語法與狀態；正文、表格、側欄一律墨＋紙。任何全彩元素必須能回答紅/藍在這裡各代表什麼。

**The Red-Is-Action Rule.** 朱紅 (#c0392b) 保留給「改動金錢」的主動作與收入語意；不作裝飾、不當大面積背景（側欄的紅僅 1px 邊線與品牌雙色塊）。

## Typography

**Display Font:** 標楷體 (DFKai-SB / KaiTi 堆疊；Windows 內建，無需自架字型檔）
**Body Font:** 系統黑體堆疊（Noto Sans TC / Microsoft JhengHei / Segoe UI）
**Mono Font:** Courier New（傳票編號、金額、讀數，全為 tabular）

**Character:** 標楷體是台灣傳票與公文的手寫印刷字——顯示字帶「人在蓋章」的手作感；黑體負責高速辨識的內文；Courier 負責機器可對數字。三級字型各自成職，不混用。

### Hierarchy

- **Display** (700, 1.75rem/28px, lh 1.25, ls .02em): 頁面 h1（儀表板／帳本名稱／新增記錄…）。
- **Title** (700, 1.375rem/22px, lh 1.35): 區塊 h2/h3：「最近 50 筆記錄」「新增使用者」。
- **Body** (400, 1rem/16px, lh 1.55): 內文與表格；表格 0.875rem。
- **Label** (700, 0.875rem, ls .02em): 表單標籤（.label）；輸入最小高 48px。
- **Mono** (700, 1rem, tabular): 編號 chip、金額欄（.num 右對齊）、日期、回饋讀數。

### Named Rules

**The Tabular Rule.** 一切數字（金額、編號、日期、ID）都用等寬 Courier 對齊；千分位一律顯示（`42,000.00`），長輩上不帶逗號的 6 位數會被誤讀。

**The Kai Rule.** 標題只用標楷體；禁止用系統黑體、Impact 或任何「通用粗黑」當顯示字。

## Layout

雙欄殼：左 232px 碳墨側欄（`.side`，桌機固定、≤720px 變 sticky 頂欄＋橫向捲動導覽）＋右米紙主頁（`.main`，內容最大寬 1080px，`.sheet` 置中）。帳本頁（`ledger.php`／`add_entry.php`）側欄多一段「這本帳」：帳本編號＋名稱＋成員角色名單（`ledger_access` 唯讀查詢），0720px 以下隱藏。

節奏：spacing 4px 基數；成組緊湊（12px 內）、組間寬鬆（24–32px）、標題上方多一拍（24px+）。Dashboard 快速入口為 1.5/1/1 不對稱三欄（首格墨底），≤720px 單欄。≤900px 板塊（.plates）轉單欄、表單（.form-grid）單欄、登入頁雙欄→單欄。≤720px 隱藏手機非必要欄（`.hide-m`：備註、記錄數）、表格 `min-width: 0` 並縮級字。

## Elevation & Depth

**純扁平草紙感。** 無漸層、無投影、無光暈；深度只由（1）色頂線＋邊框的 plate 聯票、（2）dashed 騎縫線（`.tear`）、（3）墨/紙色階差（side vs main）、（4）焦點環（`box-shadow: 0 0 0 3px #1f4e79`）四種手法呈現。登入頁單例外：`.login-card` 一層柔影（`0 1px 2px rgba(35,39,43,.12), 0 4px 14px rgba(35,39,43,.07)`）讓單卡浮在碳墨底上。

### Named Rules

**The Flat-Voucher Rule.** 任何面都在紙上，不在空中；陰影只出現在登入卡。hover 回饋用色階（surface 變暗、邊線轉墨）代替位移與光暈。

## Shapes

圓角預算：chips 2px、按鈕/輸入/卡片 3px（`--radius`）、板塊 plates 0（方正）。所有框線 1px 細線（#d8d4c6）；強調邊框只有兩種尺度：plate 頂 5px 色頂線、側欄桌面右緣 1px 朱紅、登入卡 6px double 墨黑頂。焦點環：3px 實色 `#1f4e79` outline，`outline-offset: 2px`；`::selection` parchment `#e8d9a6`。

## Components

### Buttons

- **Shape:** 3px 圓角（`--radius: 3px`），`min-height: 44px`（.btn.sm 38px／手機 44px）。
- **Primary (.btn.primary):** 傳票朱紅底 (#c0392b)／#fffdf1 字，700 字重；用於立傳票、儲存記錄、建立、登入——僅「改動金錢或狀態」的動作。hover #a02c20。
- **Secondary (.btn.secondary):** 透明底、1.5px #1f4e79 線、鐵藍字；hover 底 #e3ebf3；次要導航（返回列表、管理頁互連）。
- **Danger (.btn.danger):** 同 Primary 的紅底——作廢／刪除等破壞性動作，與收入紅同語源但永遠帶 `onclick="confirm(...)"`。
- **Disabled:** `.btn:disabled` opacity .45 + cursor not-allowed（自刪按鈕）。

### Chips

- **傳票編號 chip (.vno.in / .vno.out):** 2px 圓角、Courier 700、白字；in=#c0392b、out=#1f4e79。內容為 `收-`／`支-` + 零補齊三碼的 entry id。
- **角色 chip (.role-tag.owner/.admin/.member/.viewer):** owner/admin 朱紅底 tinted (#f6e3df 底、#c0392b 字、#c0392b 線）；member 鐵藍（#e3ebf3/#1f4e79)；viewer 灰。側欄成員名單用 `.role-chip.small`（9px、mono、透明底白字）。

### Cards / Containers

- **Plate（三聯合計牌）:** 0 圓角；頂 5px 實色頂線（ink=結餘、朱紅=收入、鐵藍=支出）；內文米紙 #fffdf1、1px line 邊框；label mono 10px tracked（.28em）＋值 mono 700 1.65rem/千分位。
- **快速入口 (.quick):** 米紙卡、1px line 邊框、hover 轉墨邊；首格 `.quick-grid .quick:first-child` 墨底反襯（非第二強調色，仍是墨）。
- **登入卡 (.login-card):** 雙線墨黑頂（6px double）、柔影（唯一投影），窄 400px。

### Inputs / Fields

- **Style:** 米紙 2 底 (#fffdf1)、1px line 邊框、3px 圓角、min-height 48px；placeholder = var(--ink-2)。
- **Focus:** 邊線轉 #1f4e79 + 3px 實色焦點環（單一焦點語彙全站一致）。
- **錯誤態:** `.alert.error`（朱紅 tint、#7a241a 字，≥4.5:1）；成功 `.alert.success` 綠 (#eaf1e6/#1d4a24)；`role="alert"/"status"` 已就位。

### Navigation

- **桌機側欄:** 碳墨殼、FAMILY VOUCHER mono kicker + 標楷體系統名＋紅藍雙色塊品牌標記；`.nav-item` 左 3px 朱紅 active 線的紙上白字（世界的 active 語法，非違式邊框）；帳務/後台 mono 分節標。
- **手機 ≤720px:** sticky 頂欄、品牌 mono＋名（雙色塊隱藏）、橫向捲動導覽、2px 下緣朱紅 active。

### [Signature Component] 三聯合計牌 (.plates / .plate)

本系統的記憶點。本月收入（+）、支出（−）、結餘三板成「一聯票據」：頂線色分工（紅/藍/墨）、標籤 mono tracked 大寫、數值 tabular 等寬單行、千分位顯示。新增記錄成功後 success alert 顯示「傳票 支-129 已立，請核對。」——立票—核對—月結的儀式在此閉合；編號同步出現在分錄表 chip。

## Do's and Don'ts

### Do:

- **Do** 讓彩色只回答「這是收還是支、這是會生效還是取消」；其餘一律墨＋紙。
- **Do** 一切數字等寬 Courier 右對齊、千分位顯示、收入紅前綴 `+`、支出藍前綴 `−`。
- **Do** 破壞性動作（作廢/刪除）永遠紅底＋點擊確認（`confirm()`），且自我刪除按鈕必須 `:disabled`。
- **Do** 手機先驗證：≥44px 觸控、導覽可橫向捲動、非必要欄 `.hide-m` 隱藏而非擠壓。
- **Do** 沿用騎縫線（dashed 2px #d8d4c6）分節，代替實線邊框堆疊。

### Don't:

- **Don't** 用 system display face（Impact / Arial Black / 系統黑體重字）當顯示字——顯示字是標楷體，不換人。
- **Don't** 加漸層、光暈、彩色投影、玻璃擬態（flat voucher rule；登入卡是唯一投影例外）。
- **Don't** 引入第二個強調色（例：紫、橘）作為按鈕或連結；鐵藍就是連結色。
- **Don't** 把朱紅用於大面積背景或裝飾；它同時是「收入」與「改動」的語法，泛用會毀掉兩層語意。
- **Don't** 在標題上方加 kicker/eyebrow 小字標籤（細節見 craft floor；標題自己扛重量）。
