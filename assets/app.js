// CookiesAccounting 共用前端腳本（外部化，避免 CSP 'unsafe-inline'）
// 在所有內頁引入，取代各頁重複的內聯 <script>
(function () {

  // 保持 CSP 只允許同源腳本；取消確認時不提交表單。
  document.addEventListener('submit', function (event) {
    var message = event.submitter?.dataset.confirm || event.target.dataset.confirm;
    if (message && !window.confirm(message)) event.preventDefault();
  });

  // ---------- 工具：Cookie 讀寫 ----------
  function getCookie(name) {
    var value = '; ' + document.cookie;
    var parts = value.split('; ' + name + '=');
    if (parts.length === 2) return parts.pop().split(';').shift();
    return null;
  }
  function setCookie(name, value, days) {
    var expires = new Date();
    expires.setTime(expires.getTime() + days * 24 * 60 * 60 * 1000);
    document.cookie = name + '=' + value + '; expires=' + expires.toUTCString() + '; path=/; SameSite=Lax';
  }

  // ---------- 字級切換：Cookie 讀寫與 .font-large 切換 ----------
  (function initFontSizeToggle() {
    var toggle = document.getElementById('fontSizeToggle');
    if (!toggle) return;

    var fontSize = getCookie('font_size');
    if (fontSize === 'large') {
      document.body.classList.add('font-large');
      toggle.textContent = '一般 A';
    }

    toggle.addEventListener('click', function () {
      if (document.body.classList.contains('font-large')) {
        document.body.classList.remove('font-large');
        setCookie('font_size', 'normal', 90);
        toggle.textContent = '大字 A';
      } else {
        document.body.classList.add('font-large');
        setCookie('font_size', 'large', 90);
        toggle.textContent = '一般 A';
      }
    });
  })();

  // ---------- 語音播報：操作完成後朗讀結果（ledger.php） ----------
  (function initVoicePlayback() {
    if (!window.speechSynthesis) return;
    var voiceText = document.body && document.body.dataset && document.body.dataset.voiceText;
    if (!voiceText) return;
    var utterance = new SpeechSynthesisUtterance(voiceText);
    utterance.lang = 'zh-TW';
    window.speechSynthesis.speak(utterance);
  })();

  // ---------- 快速日期按鈕：今天 / 昨天 ----------
  (function initQuickDateButtons() {
    var input = document.getElementById('entry_date');
    if (!input) return;

    var today = input.dataset.today || '';
    if (!today) return;

    document.querySelectorAll('.quick-date-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var offset = parseInt(btn.getAttribute('data-offset') || '0', 10);
        var d = new Date(today + 'T00:00:00Z');
        d.setUTCDate(d.getUTCDate() + offset);
        var ymd = d.toISOString().slice(0, 10);
        input.value = ymd;
      });
    });
  })();

  // ---------- 類別欄位：下拉選單與自訂輸入框互動 ----------
  (function initCategoryPreset() {
    var presetSelect = document.getElementById('category_preset');
    var categoryInput = document.getElementById('category');
    if (!presetSelect || !categoryInput) return;

    function updateCategoryInput() {
      var val = presetSelect.value;
      if (val === '__custom__') {
        categoryInput.disabled = false;
        categoryInput.focus();
      } else if (val !== '') {
        categoryInput.value = val;
        categoryInput.disabled = true;
      } else {
        categoryInput.disabled = false;
      }
    }

    presetSelect.addEventListener('change', updateCategoryInput);
    updateCategoryInput();
  })();

  // ---------- 編輯重複記錄模態框（ledger.php） ----------
  (function initEditRecurringModal() {
    var modal = document.getElementById('editRecurringModal');
    if (!modal) return;

    function openEditRecurring(btn) {
      document.getElementById('edit_recurring_id').value = btn.dataset.recurringId;
      document.getElementById('edit_recurring_name').value = btn.dataset.name;
      var amountCents = parseInt(btn.dataset.amountCents, 10);
      var dollars = Math.floor(Math.abs(amountCents) / 100);
      var cents = Math.abs(amountCents) % 100;
      var amountStr = dollars + (cents > 0 ? '.' + String(cents).padStart(2, '0') : '');
      document.getElementById('edit_recurring_amount').value = amountStr;
      document.getElementById('edit_recurring_type').value = btn.dataset.type;
      document.getElementById('edit_recurring_category').value = btn.dataset.category;
      document.getElementById('edit_recurring_start_date').value = btn.dataset.startDate;
      document.getElementById('edit_recurring_next_due').value = btn.dataset.nextDueDate;
      modal.style.display = 'block';
    }

    function closeEditRecurring() {
      modal.style.display = 'none';
    }

    document.querySelectorAll('[data-recurring-id]').forEach(function (button) {
      button.addEventListener('click', function () { openEditRecurring(button); });
    });
    document.querySelectorAll('[data-close-recurring]').forEach(function (button) {
      button.addEventListener('click', closeEditRecurring);
    });

    window.addEventListener('click', function (event) {
      if (event.target === modal) {
        closeEditRecurring();
      }
    });
  })();

  // ---------- 留言展開/收合與字元計數（ledger.php [#21]） ----------
  (function initCommentToggle() {
    // 留言展開按鈕
    document.querySelectorAll('.comment-toggle').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var entryId = this.dataset.entryId;
        var row = document.getElementById('comments-' + entryId);
        var isExpanded = this.getAttribute('aria-expanded') === 'true';
        if (row) {
          row.style.display = isExpanded ? 'none' : 'table-row';
          this.setAttribute('aria-expanded', isExpanded ? 'false' : 'true');
        }
      });
    });

    // 字元計數即時更新
    document.querySelectorAll('.comment-textarea').forEach(function (textarea) {
      var maxLen = parseInt(textarea.getAttribute('maxlength') || '200', 10);
      var countId = textarea.getAttribute('aria-describedby');
      var countEl = countId ? document.getElementById(countId) : null;

      function updateCount() {
        var len = textarea.value.length;
        if (countEl) {
          countEl.textContent = len + ' / ' + maxLen;
          countEl.style.color = len > maxLen ? 'var(--red)' : 'var(--ink-3)';
        }
      }

      textarea.addEventListener('input', updateCount);
      updateCount(); // 初始化
    });
  })();

  // ---------- 幣別切換：顯示/隱藏匯率欄位（add_entry.php / edit_entry.php）----------
  (function initCurrencyToggle() {
    var currencySelect = document.getElementById('currency');
    var exchangeRateField = document.getElementById('exchangeRateField');
    var exchangeRateInput = document.getElementById('exchange_rate');
    if (!currencySelect || !exchangeRateField || !exchangeRateInput) return;

    function toggleExchangeRate() {
      if (currencySelect.value === 'TWD') {
        exchangeRateField.style.display = 'none';
        exchangeRateInput.value = '1.0000';
        exchangeRateInput.removeAttribute('required');
      } else {
        exchangeRateField.style.display = '';
        exchangeRateInput.setAttribute('required', '');
      }
    }

    toggleExchangeRate();
    currencySelect.addEventListener('change', toggleExchangeRate);
  })();

  // ---------- 金額格式化（供 transfer_pair 差額預覽使用）----------
  window.formatCents = function (cents) {
    var neg = cents < 0;
    var abs = Math.abs(cents);
    var dollars = Math.floor(abs / 100);
    var c = abs % 100;
    return (neg ? '-' : '') + dollars.toLocaleString() + '.' + String(c).padStart(2, '0');
  };

  // ---------- 月結單及傳票列印 ----------
  document.querySelectorAll('[data-print-page]').forEach(function (button) {
    button.addEventListener('click', function () { window.print(); });
  });

  // ---------- 匯出連結月份更新（ledger.php）----------
  (function initExportMonth() {
    var select = document.getElementById('exportYmSelect');
    if (!select) return;
    var links = [
      document.querySelector('a[href*="export_csv.php"][href*="format=csv"]'),
      document.querySelector('a[href*="export_csv.php"][href*="format=json"]')
    ];
    select.addEventListener('change', function () {
      links.forEach(function (link) {
        if (!link) return;
        var url = new URL(link.href, window.location.origin);
        url.searchParams.set('ym', select.value);
        link.href = './export_csv.php' + url.search;
      });
    });
  })();

  // ---------- 轉帳配對差額預覽（transfer_pair.php）----------
  (function initTransferPair() {
    var select1 = document.getElementById('entry_id_1');
    var select2 = document.getElementById('entry_id_2');
    var diffPreview = document.getElementById('diffPreview');
    var diffText = document.getElementById('diffText');
    if (!select1 || !select2 || !diffPreview || !diffText) return;

    function updateDiffPreview() {
      var opt1 = select1.options[select1.selectedIndex];
      var opt2 = select2.options[select2.selectedIndex];
      if (!opt1.value || !opt2.value) {
        diffPreview.style.display = 'none';
        return;
      }
      if (opt1.value === opt2.value) {
        diffPreview.style.display = 'none';
        return;
      }
      var amt1 = parseInt(opt1.dataset.amount || '0', 10);
      var amt2 = parseInt(opt2.dataset.amount || '0', 10);
      var diff = Math.abs(amt1 - amt2);
      var diffStr = typeof window.formatCents === 'function' ? window.formatCents(diff) : String(diff);
      var html = '差額：<strong class="mono">' + diffStr + '</strong> 元';
      if (diff > 0) {
        html += ' <span class="warn">(金額不等，配對時將記錄差額)</span>';
      }
      diffText.innerHTML = html;
      diffPreview.style.display = 'block';
    }

    var reset = document.getElementById('resetPairButton');
    if (reset) reset.addEventListener('click', function () {
      select1.value = '';
      select2.value = '';
      diffPreview.style.display = 'none';
    });

    select1.addEventListener('change', updateDiffPreview);
    select2.addEventListener('change', updateDiffPreview);
  })();

})();
