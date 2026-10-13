(function () {
  'use strict';
  const faToEn = (s) => s.replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));

  document.querySelectorAll('.ldm-form').forEach(form => {
    const card = form.closest('.ldm-card');
    const status = form.querySelector('.ldm-status');
    const button = form.querySelector('.ldm-submit');
    form.addEventListener('submit', async e => {
      e.preventDefault(); status.textContent = '';
      const data = new FormData(form);
      data.set('mobile', faToEn(data.get('mobile').trim()));
      data.append('action', 'lucky_draw_manager_submit'); data.append('nonce', LuckyDrawManager.nonce);
      button.disabled = true; button.classList.add('is-loading'); button.querySelector('span').textContent = 'در حال ثبت...';
      try {
        const response = await fetch(LuckyDrawManager.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data });
        const json = await response.json();
        if (!json.success) throw new Error(json.data && json.data.message ? json.data.message : 'ثبت اطلاعات انجام نشد.');
        showResult(card, json.data.code);
      } catch (err) { status.textContent = err.message || 'خطایی رخ داد؛ دوباره تلاش کنید.'; }
      finally { button.disabled = false; button.classList.remove('is-loading'); button.querySelector('span').textContent = 'ثبت و دریافت کد'; }
    });
  });

  function showResult(card, code) {
    card.querySelector('.ldm-form').hidden = true;
    card.querySelector('.ldm-intro').hidden = true;
    const result = card.querySelector('.ldm-result'); result.hidden = false;
    result.querySelector('.ldm-code span').textContent = code;
    result.querySelector('.ldm-copy').onclick = async function () {
      try { await navigator.clipboard.writeText(code); this.textContent = 'کپی شد ✓'; }
      catch (_) { const t=document.createElement('textarea');t.value=code;document.body.appendChild(t);t.select();document.execCommand('copy');t.remove();this.textContent='کپی شد ✓'; }
    };
    result.querySelector('.ldm-save').onclick = () => saveCodeImage(code);
  }

  function saveCodeImage(code) {
    const canvas = document.createElement('canvas'); canvas.width = 1080; canvas.height = 1080;
    const c = canvas.getContext('2d'), gradient = c.createLinearGradient(0,0,1080,1080);
    gradient.addColorStop(0,'#4c1d95'); gradient.addColorStop(1,'#db2777'); c.fillStyle=gradient; c.fillRect(0,0,1080,1080);
    c.fillStyle='#fff'; c.textAlign='center'; c.direction='rtl'; c.font='bold 62px sans-serif'; c.fillText('کد قرعه‌کشی شما',540,300);
    c.fillStyle='rgba(255,255,255,.14)'; roundRect(c,190,390,700,260,38); c.fill();
    c.fillStyle='#fff'; c.font='bold 170px monospace'; c.fillText(code,540,575);
    c.font='36px sans-serif'; c.fillText('این کد را تا روز قرعه‌کشی نگه دارید',540,780);
    const link=document.createElement('a'); link.download='lottery-'+code+'.png'; link.href=canvas.toDataURL('image/png'); link.click();
  }
  function roundRect(c,x,y,w,h,r){c.beginPath();c.moveTo(x+r,y);c.arcTo(x+w,y,x+w,y+h,r);c.arcTo(x+w,y+h,x,y+h,r);c.arcTo(x,y+h,x,y,r);c.arcTo(x,y,x+w,y,r);c.closePath();}
})();
