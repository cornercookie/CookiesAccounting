// CookiesAccounting 共用前端腳本（外部化，避免 CSP 'unsafe-inline'）
// 在所有內頁引入，取代各頁重複的內聯 <script>
(function () {

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
        var d = new Date(today + 'T00:00:00');
        d.setDate(d.getDate() + offset);
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

    window.openEditRecurringFromElement = function (btn) {
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
    };

    window.closeEditRecurring = function () {
      modal.style.display = 'none';
    };

    window.addEventListener('click', function (event) {
      if (event.target === modal) {
        closeEditRecurring();
      }
    });
  })();

})();
