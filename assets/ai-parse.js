// CookiesAccounting AI 一句話記帳腳本（僅 add_entry.php 使用）
// 外部化，避免 CSP 'unsafe-inline'
(function () {

  var toggle = document.querySelector('.ai-toggle');
  var panel = document.getElementById('ai-panel');
  var aiText = document.getElementById('aiText');
  var aiParseBtn = document.getElementById('aiParseBtn');
  var aiClearBtn = document.getElementById('aiClearBtn');
  var aiStatus = document.getElementById('aiStatus');

  if (!toggle || !panel) return;

  // 展開/摺疊 AI 區塊
  toggle.addEventListener('click', function () {
    var expanded = toggle.getAttribute('aria-expanded') === 'true';
    toggle.setAttribute('aria-expanded', String(!expanded));
    panel.hidden = expanded;
    if (!expanded) aiText.focus();
  });

  // 清除 AI 輸入與狀態
  aiClearBtn.addEventListener('click', function () {
    aiText.value = '';
    aiStatus.textContent = '';
    aiStatus.className = 'ai-status';
  });

  // 呼叫後端 ai_parse.php 解析文字，並填入表單欄位
  aiParseBtn.addEventListener('click', function () {
    var text = aiText.value.trim();
    if (!text) {
      aiStatus.textContent = '請先輸入收支內容。';
      aiStatus.className = 'ai-status ai-error';
      return;
    }

    aiParseBtn.disabled = true;
    aiParseBtn.textContent = '解析中…';
    aiStatus.textContent = '正在向 AI 解析…';
    aiStatus.className = 'ai-status';

    var csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';

    fetch('./ai_parse.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json; charset=utf-8',
        'X-CSRF-Token': csrfToken
      },
      body: JSON.stringify({ text: text })
    })
    .then(function (resp) { return resp.json(); })
    .then(function (data) {
      aiParseBtn.disabled = false;
      aiParseBtn.textContent = '解析並填入表單';

      if (!data.ok) {
        aiStatus.textContent = data.error || '解析失敗，請手動填寫。';
        aiStatus.className = 'ai-status ai-error';
        return;
      }

      var d = data.data;
      var filled = [];

      if (d.amount !== null) {
        document.getElementById('amount').value = d.amount;
        filled.push('金額 ' + d.amount);
      }
      if (d.category !== null && typeof d.category === "string") {
        var cat = d.category.trim();
        if (cat !== '') {
          var presetSelect = document.getElementById('category_preset');
          if (presetSelect) {
            var matched = false;
            for (var i = 0; i < presetSelect.options.length; i++) {
              var opt = presetSelect.options[i];
              if (opt.value !== '' && opt.value !== '__custom__' && opt.value === cat) {
                presetSelect.selectedIndex = i;
                matched = true;
                break;
              }
            }
            if (!matched) {
              presetSelect.value = "__custom__";
            }
            presetSelect.dispatchEvent(new Event('change', { bubbles: true }));
          }
          document.getElementById('category').value = cat;
          filled.push('分類 ' + cat);
        }
      }
      if (d.date !== null) {
        document.getElementById('entry_date').value = d.date;
        filled.push('日期 ' + d.date);
      }
      if (d.type !== null) {
        document.getElementById('type').value = d.type;
        filled.push('類型 ' + (d.type === 'income' ? '收入' : '支出'));
      }
      if (d.description !== null && d.description !== '') {
        document.getElementById('note').value = d.description;
        filled.push('備註 ' + d.description);
      }

      aiStatus.textContent = filled.length > 0
        ? '已填入：' + filled.join('，') + '（請確認無誤後再儲存）'
        : 'AI 未能解析出可用欄位，請手動填寫。';
      aiStatus.className = 'ai-status ' + (filled.length > 0 ? 'ai-ok' : 'ai-warn');

      document.getElementById('amount').focus();
    })
    .catch(function () {
      aiParseBtn.disabled = false;
      aiParseBtn.textContent = '解析並填入表單';
      aiStatus.textContent = '網路錯誤，請手動填寫。';
      aiStatus.className = 'ai-status ai-error';
    });
  });

})();
