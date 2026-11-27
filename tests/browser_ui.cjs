// Repo-disposable Chrome QA. Provider response is an explicit fixture; no live Gemini request.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawn } = require('node:child_process');
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
async function until(fn, name, ms = 7000) {
  const deadline = Date.now() + ms;
  while (Date.now() < deadline) { if (await fn()) return; await sleep(50); }
  throw new Error('Timed out: ' + name);
}
async function main() {
  const base = new URL(process.env.COOKIES_TEST_BASE);
  assert.equal(base.hostname, '127.0.0.1');
  assert.equal(base.protocol, 'http:');
  const profile = process.env.COOKIES_CHROME_PROFILE;
  const chrome = spawn(process.env.CHROME_BIN || 'C:/Program Files/Google/Chrome/Application/chrome.exe',
    ['--headless=new','--no-first-run','--disable-background-networking','--remote-debugging-port=0','--user-data-dir='+profile,'about:blank'], {stdio:'ignore'});
  let ws;
  try {
    const portFile = path.join(profile,'DevToolsActivePort');
    await until(() => fs.existsSync(portFile), 'Chrome DevTools');
    const port = fs.readFileSync(portFile,'utf8').split('\n')[0];
    const tabs = await (await fetch('http://127.0.0.1:'+port+'/json/list',{signal:AbortSignal.timeout(4000)})).json();
    ws = new WebSocket(tabs.find(x => x.type === 'page').webSocketDebuggerUrl);
    await until(() => ws.readyState === WebSocket.OPEN,'DevTools socket');
    let serial = 0;
    const pending = new Map();
    const events = [];
    ws.addEventListener('message', ev => {
      const m = JSON.parse(ev.data);
      if (m.method === 'Fetch.requestPaused') {
        const {requestId, request} = m.params;
        let allowed = false;
        try { if (new URL(request.url).origin === base.origin) allowed = true; } catch {}
        (allowed ? cdp('Fetch.continueRequest', {requestId}) : cdp('Fetch.failRequest', {requestId, errorReason: 'BlockedByClient'}))
          .catch(() => { process.exitCode = 1; });
        return;
      }
      if (!pending.has(m.id)) { if (m.method) events.push(m); return; }
      const p = pending.get(m.id); pending.delete(m.id); clearTimeout(p.timer);
      m.error ? p.reject(Error(m.error.message)) : p.resolve(m.result);
    });
    const cdp = (method,params={}) => new Promise((resolve,reject) => {
      const id=++serial;
      const timer=setTimeout(() => { pending.delete(id); reject(Error('CDP timeout: '+method)); },6500);
      pending.set(id,{resolve,reject,timer}); ws.send(JSON.stringify({id,method,params}));
    });
    const run = async expression => {
      const r = await cdp('Runtime.evaluate',{expression,returnByValue:true});
      if (r.exceptionDetails) throw Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text);
      return r.result.value;
    };
    await cdp('Page.enable');
    await cdp('Network.enable');
    await cdp("Fetch.enable", {patterns: [{urlPattern: "*", requestStage: "Request"}]});
    assert.ok((await cdp('Network.setCookie',{name:'cookies_accounting',value:process.env.COOKIES_TEST_SESSION,url:base.href,httpOnly:true})).success);
    await cdp('Page.addScriptToEvaluateOnNewDocument',{source:`window.__qa = {csp: [], category: '測試水果'};
      document.addEventListener('securitypolicyviolation',e=>window.__qa.csp.push(e.violatedDirective));
      const nativeFetch=window.fetch.bind(window);
      window.fetch=(url,opts)=>url==='./ai_parse.php' && window.__qa.stub ? Promise.resolve(new Response(JSON.stringify({ok:true,data:{type:'expense',amount:'50.00',category:window.__qa.category,date:'2025-02-17',description:'測試 AI 確認後才儲存'}}),{headers:{'Content-Type':'application/json'}})) : nativeFetch(url,opts);`});
    const load=async (route,sel)=>{
      await cdp('Page.navigate',{url:new URL(route,base).href});
      await until(async () => { try {return await run(`location.pathname === ${JSON.stringify(new URL(route,base).pathname)} && document.readyState === 'complete' && !!document.querySelector(${JSON.stringify(sel)})`);} catch {return false;} },route);
    };
    const click=s=>run(`document.querySelector(${JSON.stringify(s)}).click()`);
    if (process.env.COOKIES_QA_ONLY !== 'ai') {
      await load('/add_entry.php?ledger_id=1','#currency');
      const rateState=()=>run(`(() => {const f=document.querySelector('#exchangeRateField');const r=document.querySelector('#exchange_rate');return [getComputedStyle(f).display==='none',r.required,r.value];})()`);
      assert.deepEqual(await rateState(),[true,false,'1.0000']);
      await run(`(() => {const s=document.querySelector('#currency');s.value='USD';s.dispatchEvent(new Event('change',{bubbles:true}));})()`);
      assert.deepEqual(await rateState(),[false,true,'1.0000']);
      await run(`(() => {document.querySelector('#exchange_rate').value='32.5000';const s=document.querySelector('#currency');s.value='TWD';s.dispatchEvent(new Event('change',{bubbles:true}));})()`);
      assert.deepEqual(await rateState(),[true,false,'1.0000']);
      await load('/add_entry.php?ledger_id=1&currency=USD&rate=32.5000','#currency');
      assert.deepEqual(await rateState(),[false,true,'32.5000']);
      assert.deepEqual(await run('window.__qa.csp'),[]);
      console.log('Chrome OK: currency toggle reveals rate field and preserves query-prefilled rate');
      for (const timezoneId of ['Asia/Taipei','America/Los_Angeles']) {
        await cdp('Emulation.setTimezoneOverride',{timezoneId});
        await load('/add_entry.php?ledger_id=1','#entry_date');
        const today=await run('document.querySelector("#entry_date").dataset.today');
        assert.match(today,/^\d{4}-\d{2}-\d{2}$/);
        const yesterday=new Date(Date.parse(today+'T00:00:00Z')-86400000).toISOString().slice(0,10);
        await click('.quick-date-btn[data-offset="-1"]');
        assert.equal(await run('document.querySelector("#entry_date").value'),yesterday);
        await click('.quick-date-btn[data-offset="0"]');
        assert.equal(await run('document.querySelector("#entry_date").value'),today);
        assert.deepEqual(await run('window.__qa.csp'),[]);
      }
      console.log('Chrome OK: server Taipei quick dates under two browser timezones');
      await load('/ledger.php?ledger_id=1&ym=2025-02','[data-recurring-id]');
      const monthlyChartBlue = await run(`getComputedStyle(document.querySelector('.category-stats svg rect')).fill`);
      assert.ok(monthlyChartBlue);
      await click('[data-recurring-id]');
      assert.equal(await run('getComputedStyle(document.querySelector("#editRecurringModal")).display'),'block');
      assert.equal(await run('document.querySelector("#edit_recurring_amount").value'),'32.50');
      await click('[data-close-recurring]');
      assert.equal(await run('getComputedStyle(document.querySelector("#editRecurringModal")).display'),'none');
      assert.deepEqual(await run('window.__qa.csp'),[]);
      console.log('Chrome OK: recurring edit modal open, prefill and close');
      const deleteButton='form[action*="delete_entry.php"] button[type="submit"]';
      const opening=until(()=>events.some(e=>e.method==='Page.javascriptDialogOpening'),'delete confirmation');
      const clicking=click(deleteButton);
      clicking.catch(()=>{});
      await opening;
      await cdp('Page.handleJavaScriptDialog',{accept:false});
      await clicking;
      assert.ok(await run('location.pathname.endsWith("/ledger.php")'));
      console.log('Chrome OK: delete dialog cancelled');
      const comparisonCategory = 'QA Comparison Browser';
      await load(`/comparison_report.php?ledger_id=1&mode=month&category=${encodeURIComponent(comparisonCategory)}`, '.comparison-chart svg');
      const comparisonColors = await run(`(() => {
        const rects = Array.from(document.querySelectorAll('.comparison-chart svg rect'));
        const legend = rects.filter(r => r.getAttribute('y') === '20');
        const bars = rects.filter(r => Number(r.getAttribute('y')) >= 60);
        return {
          count: rects.length,
          legendPaints: legend.map(r => getComputedStyle(r).fill),
          barPaints: bars.map(r => getComputedStyle(r).fill),
          barWidths: bars.map(r => Number(r.getAttribute('width'))),
        };
      })()`);
      assert.equal(comparisonColors.count, 4);
      assert.equal(comparisonColors.legendPaints.length, 2);
      assert.equal(comparisonColors.barPaints.length, 2);
      assert.ok(comparisonColors.barWidths[0] > 0);
      assert.ok(comparisonColors.barWidths[1] > 0);
      assert.equal(comparisonColors.legendPaints[0], comparisonColors.barPaints[0]);
      assert.equal(comparisonColors.legendPaints[1], comparisonColors.barPaints[1]);
      assert.notEqual(comparisonColors.legendPaints[0], comparisonColors.legendPaints[1]);
      assert.equal(comparisonColors.legendPaints[0], monthlyChartBlue);
      console.log('Chrome OK: monthly comparison chart preserves distinct A/B colors');
    }
    await load('/add_entry.php?ledger_id=1','#aiParseBtn');
    await click('.ai-toggle');
    await run('document.querySelector("#aiText").value="水果五十元"');
    if (process.env.COOKIES_QA_ONLY !== 'ai') {
      await click('#aiParseBtn');
      await until(()=>run('document.querySelector("#aiStatus").classList.contains("ai-error")'),'provider missing');
      assert.match(await run('document.querySelector("#aiStatus").textContent'),/金鑰|手動/);
      console.log('Chrome OK: real PHP missing-key fallback');
    }
    await run(`window.__qa.stub=true; const sel=document.querySelector('#category_preset');sel.value='交通';sel.dispatchEvent(new Event('change',{bubbles:true}));`);
    await click('#aiParseBtn');
    await until(()=>run('document.querySelector("#aiStatus").classList.contains("ai-ok")'),'AI fixture prefill');
    const formState=await run(`(() => {const d=new FormData(document.querySelector('#amount').form);return [d.get('category_preset'),d.get('category'),d.get('amount')];})()`);
    assert.deepEqual(formState,['__custom__','測試水果','50.00']);
    const manual=await run(`(() => {const f=document.querySelector('#amount').form; const d=new FormData(f); return [d.get('csrf_token')!==null,location.pathname,d.get('note')];})()`);
    assert.deepEqual(manual,[true,'/add_entry.php','測試 AI 確認後才儲存']);
    await run(`document.querySelector('#amount').form.requestSubmit()`);
    await until(()=>run('location.pathname.endsWith("/ledger.php") && document.readyState==="complete"'),'manual save');
    console.log('Chrome OK: AI response fixture overrides old preset, manual submit navigates successfully');
  } finally { if (ws) ws.close(); if (chrome.exitCode===null) chrome.kill(); }
}
if (require.main === module) {
  main().catch(e=>{console.error(e.message);process.exitCode=1;});
}
