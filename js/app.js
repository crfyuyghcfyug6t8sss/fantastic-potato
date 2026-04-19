// FB Manager v2 — Full SPA

const API = {
  async call(method, path, body = null, isForm = false) {
    const opts = { method, credentials: 'same-origin' };
    if (isForm) { opts.body = body; }
    else {
      opts.headers = { 'Content-Type': 'application/json' };
      if (body) opts.body = JSON.stringify(body);
    }
    const res  = await fetch('/api/' + path, opts);
    const data = await res.json();
    return data;
  },
  get:  (p)      => API.call('GET', p),
  post: (p, b)   => API.call('POST', p, b),
  form: (p, fd)  => API.call('POST', p, fd, true),
};

let S = { user: null, fbPage: null, promotePost: null, pendingBadges: {}, siteSettings: { site_name: 'FB Manager', site_logo: null } };

//  Init 
async function init() {
  // Load site settings first (public endpoint)
  const settRes = await API.get('user/site-settings').catch(() => null);
  if (settRes?.success) S.siteSettings = settRes.settings;

  const res = await API.get('auth/me');
  const path = location.pathname;
  const authPaths = ['/', '/login', '/register', '/otp'];

  if (res.success) {
    S.user = res.user;
    if (authPaths.includes(path)) goto(S.user.role === 'admin' ? '/admin' : '/dashboard');
    else bootApp();
  } else {
    if (!authPaths.includes(path)) goto('/login');
    else bootAuth(path);
  }
}

function goto(p) { history.pushState({}, '', p); init(); }
window.addEventListener('popstate', init);

//  Auth 
// ─────────────────────────────────────────────────────────────────────────────

let OTP = { phone: '', isNew: false, isAdmin: false, resendTimer: 0, resendInterval: null };

function bootAuth(path) {
  document.body.innerHTML = tplAuth();
  authStep('phone');
}

function tplAuth() {
  const name = esc(S.siteSettings.site_name || 'FB Manager');
  const logo = S.siteSettings.site_logo
    ? `<img src="${esc(S.siteSettings.site_logo)}" style="width:48px;height:48px;border-radius:13px;object-fit:cover;margin-bottom:12px">`
    : `<div class="auth-logo-icon">${name.charAt(0)}</div>`;
  return `
  <div class="auth-wrapper">
    <div class="auth-box">
      <div class="auth-head">${logo}<h1>${name}</h1></div>
      <div id="a-alert"></div>
      <div id="auth-body"></div>
    </div>
  </div>`;
}

function authStep(step) {
  const box = document.getElementById('auth-body');
  if (!box) return;
  setAlert('');

  if (step === 'phone') {
    box.innerHTML = `
      <label class="a-label">رقم الهاتف</label>
      <div class="a-field">
        <svg class="a-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.36 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.11 1h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L7.09 8.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 21 16.92z"/></svg>
        <input id="f-phone" type="tel" placeholder="09xxxxxxxx" autocomplete="tel"
               onkeydown="if(event.key==='Enter')doStep1()">
      </div>
      <p class="a-hint">سيصلك رمز تحقق عبر واتساب</p>
      <button class="a-btn" id="a-btn1" onclick="doStep1()">متابعة</button>`;
    setTimeout(() => document.getElementById('f-phone')?.focus(), 50);

  } else if (step === 'otp') {
    box.innerHTML = `
      <div class="a-badge">
        <span style="color:#25d366">✓</span>
        <span dir="ltr">${esc(OTP.phone)}</span>
        <button class="a-link" onclick="authStep('phone')">تغيير</button>
      </div>
      <label class="a-label">رمز التحقق</label>
      <input class="a-otp" id="f-otp" type="text" inputmode="numeric" maxlength="6"
             placeholder="• • • • • •" autocomplete="one-time-code"
             oninput="this.value=this.value.replace(/\D/g,'')"
             onkeydown="if(event.key==='Enter')doStep2()">
      <button class="a-btn" id="a-btn2" onclick="doStep2()" style="margin-top:14px">تحقق</button>
      <p class="a-resend">لم يصلك الرمز؟
        <button id="a-resend-btn" class="a-link bold" onclick="doResend()">إعادة الإرسال</button>
        <span id="a-timer"></span>
      </p>`;
    setTimeout(() => document.getElementById('f-otp')?.focus(), 50);
    startTimer();

  } else if (step === 'admin-otp') {
    box.innerHTML = `
      <div class="a-badge">
        <span style="color:#25d366">✓</span>
        <span dir="ltr">${esc(OTP.phone)}</span>
        <button class="a-link" onclick="authStep('phone')">تغيير</button>
      </div>
      <label class="a-label">رمز التحقق (واتساب)</label>
      <input class="a-otp" id="f-otp" type="text" inputmode="numeric" maxlength="6"
             placeholder="• • • • • •" autocomplete="one-time-code"
             oninput="this.value=this.value.replace(/\D/g,'')"
             onkeydown="if(event.key==='Enter')doAdminOtp()">
      <button class="a-btn" id="a-btn2" onclick="doAdminOtp()" style="margin-top:14px">متابعة</button>
      <p class="a-resend">لم يصلك الرمز؟
        <button id="a-resend-btn" class="a-link bold" onclick="doResend()">إعادة الإرسال</button>
        <span id="a-timer"></span>
      </p>`;
    setTimeout(() => document.getElementById('f-otp')?.focus(), 50);
    startTimer();

  } else if (step === 'admin-pass') {
    box.innerHTML = `
      <label class="a-label">كلمة المرور</label>
      <div class="a-field">
        <svg class="a-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        <input id="f-pass" type="password" placeholder="••••••••" autocomplete="current-password"
               onkeydown="if(event.key==='Enter')doAdminPass()">
      </div>
      <button class="a-btn" id="a-btn3" onclick="doAdminPass()" style="margin-top:4px">دخول</button>
      <p style="text-align:center;margin-top:12px">
        <button class="a-link" onclick="authStep('phone')">← رجوع</button>
      </p>`;
    setTimeout(() => document.getElementById('f-pass')?.focus(), 50);

  } else if (step === 'name') {
    box.innerHTML = `
      <label class="a-label">اسمك الكامل</label>
      <div class="a-field">
        <svg class="a-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        <input id="f-name" type="text" placeholder="مثال: محمد أحمد" autocomplete="name"
               onkeydown="if(event.key==='Enter')doName()">
      </div>
      <button class="a-btn" id="a-btn4" onclick="doName()">إنشاء الحساب</button>`;
    setTimeout(() => document.getElementById('f-name')?.focus(), 50);
  }
}

function startTimer() {
  clearInterval(OTP.resendInterval);
  OTP.resendTimer = 60;
  const tick = () => {
    const t = document.getElementById('a-timer');
    const b = document.getElementById('a-resend-btn');
    if (OTP.resendTimer <= 0) {
      clearInterval(OTP.resendInterval);
      if (t) t.textContent = '';
      if (b) { b.style.opacity='1'; b.style.pointerEvents='auto'; }
    } else {
      if (t) t.textContent = ` (${OTP.resendTimer}ث)`;
      if (b) { b.style.opacity='0.35'; b.style.pointerEvents='none'; }
      OTP.resendTimer--;
    }
  };
  tick();
  OTP.resendInterval = setInterval(tick, 1000);
}

// ── Step 1: إرسال OTP ────────────────────────────────────────────────────────
async function doStep1() {
  const raw = document.getElementById('f-phone')?.value?.trim() || '';
  const btn = document.getElementById('a-btn1');
  setAlert('');
  if (!raw) return setAlert('يرجى إدخال رقم الهاتف', 'error');
  setBtn(btn, true, 'جاري الإرسال...');
  const res = await API.post('auth/send-otp', { phone: raw });
  setBtn(btn, false, 'متابعة');
  if (res.success) {
    OTP.phone   = res.phone || raw;
    OTP.isNew   = !!res.is_new_user;
    OTP.isAdmin = !!res.is_admin;
    authStep(OTP.isAdmin ? 'admin-otp' : 'otp');
  } else {
    setAlert(res.message, 'error');
  }
}

// ── Step 2: تحقق OTP عادي ───────────────────────────────────────────────────
async function doStep2() {
  const otp = document.getElementById('f-otp')?.value || '';
  const btn = document.getElementById('a-btn2');
  setAlert('');
  if (otp.length !== 6) return setAlert('يرجى إدخال رمز مكوّن من 6 أرقام', 'error');
  setBtn(btn, true, 'جاري التحقق...');
  const res = await API.post('auth/verify-otp', { phone: OTP.phone, otp });
  setBtn(btn, false, 'تحقق');
  if (res.success) {
    if (res.needs_name) { authStep('name'); return; }
    clearInterval(OTP.resendInterval);
    S.user = res.user;
    goto(res.user.role === 'admin' ? '/admin' : '/dashboard');
  } else {
    setAlert(res.message, 'error');
  }
}

// ── Step 2 للأدمن: تحقق OTP ثم كلمة المرور ─────────────────────────────────
async function doAdminOtp() {
  const otp = document.getElementById('f-otp')?.value || '';
  const btn = document.getElementById('a-btn2');
  setAlert('');
  if (otp.length !== 6) return setAlert('يرجى إدخال رمز مكوّن من 6 أرقام', 'error');
  setBtn(btn, true, 'جاري التحقق...');
  const res = await API.post('auth/verify-admin-otp', { phone: OTP.phone, otp });
  setBtn(btn, false, 'متابعة');
  if (res.success) {
    clearInterval(OTP.resendInterval);
    authStep('admin-pass');
  } else {
    setAlert(res.message, 'error');
  }
}

// ── Step 3 للأدمن: كلمة المرور ──────────────────────────────────────────────
async function doAdminPass() {
  const pass = document.getElementById('f-pass')?.value || '';
  const btn  = document.getElementById('a-btn3');
  setAlert('');
  if (!pass) return setAlert('يرجى إدخال كلمة المرور', 'error');
  setBtn(btn, true, 'جاري التحقق...');
  const res = await API.post('auth/login', { phone: OTP.phone, password: pass });
  setBtn(btn, false, 'دخول');
  if (res.success) { S.user = res.user; goto('/admin'); }
  else setAlert(res.message, 'error');
}

// ── تسجيل اسم مستخدم جديد ───────────────────────────────────────────────────
async function doName() {
  const name = document.getElementById('f-name')?.value?.trim() || '';
  const btn  = document.getElementById('a-btn4');
  setAlert('');
  if (name.length < 2) return setAlert('يرجى إدخال اسم صحيح', 'error');
  setBtn(btn, true, 'جاري التسجيل...');
  const res = await API.post('auth/complete-name', { phone: OTP.phone, name });
  setBtn(btn, false, 'إنشاء الحساب');
  if (res.success) { S.user = res.user; goto('/dashboard'); }
  else setAlert(res.message, 'error');
}

async function doResend() {
  const btn = document.getElementById('a-resend-btn');
  if (btn) { btn.style.opacity='0.35'; btn.style.pointerEvents='none'; }
  const res = await API.post('auth/send-otp', { phone: OTP.phone });
  if (res.success) { setAlert('تم إعادة الإرسال ✓', 'success'); startTimer(); }
  else setAlert(res.message, 'error');
}

async function doLogout() {
  await API.post('auth/logout');
  S.user = null;
  OTP = { phone: '', isNew: false, isAdmin: false, resendTimer: 0, resendInterval: null };
  goto('/login');
}

//  App boot 
function bootApp() {
  document.body.innerHTML = S.user.role === 'admin' ? tplAdminLayout() : tplUserLayout();
  navigate(S.user.role === 'admin' ? 'dashboard' : 'my-pages');
  loadPendingBadges();
}

//  Admin Layout 
function tplAdminLayout() {
  const logoHtml = S.siteSettings.site_logo
    ? `<img src="${esc(S.siteSettings.site_logo)}" style="width:36px;height:36px;border-radius:9px;object-fit:cover;flex-shrink:0">`
    : `<div class="logo-mark">F</div>`;
  return `
<div class="sidebar-overlay" id="sidebar-overlay" onclick="closeSidebar()"></div>
  <div class="mobile-topbar">
    <button class="mobile-menu-btn" onclick="openSidebar()">☰</button>
    <span style="font-weight:900;font-size:16px;color:var(--text)">${esc(S.siteSettings.site_name || 'FB Manager')}</span>
    <button class="theme-toggle" onclick="toggleTheme()">☀️</button>
  </div>
  <div class="app-layout" dir="rtl">
    <aside class="sidebar" id="sidebar">      <div class="sidebar-header">
        ${logoHtml}
        <div>
          <div class="sidebar-brand">${esc(S.siteSettings.site_name || 'FB Manager')}</div>
          <div class="sidebar-sub">لوحة الإدارة</div>
        </div>
      </div>
      <nav class="sidebar-nav">
        <div class="nav-section">الرئيسية</div>
        ${navItem('dashboard', 'dashboard', 'لوحة التحكم')}
        <div class="nav-section">الربط والصفحات</div>
        ${navItem('token',  'key',      'التوكنات')}
        ${navItem('pages',  'pages',    'الصفحات')}
        <div class="nav-section">المستخدمون</div>
        ${navItem('users',  'users',   'المستخدمون')}
        ${navItem('assign', 'link',    'الصلاحيات')}
        ${navItem('link-requests', 'bell', 'طلبات الربط', 'link-requests')}
        <div class="nav-section">الإعلانات والمدفوعات</div>
        ${navItem('campaigns',   'rocket',  'الحملات الإعلانية', 'campaigns')}
        ${navItem('deposits',    'card',    'طلبات الشحن',        'deposits')}
        ${navItem('pay-methods', 'settings','طرق الدفع')}
        <div class="nav-section">الإعدادات</div>
        ${navItem('site-settings', 'palette', 'إعدادات الموقع')}
        <div class="nav-section">التواصل</div>
        ${navItem('whatsapp-send', 'chat', 'إرسال واتساب')}
      </nav>
      ${sidebarFooter()}
    </aside>
    <main class="main" id="main"></main>
  </div>`;
}

function tplUserLayout() {
  const logoHtml = S.siteSettings.site_logo
    ? `<img src="${esc(S.siteSettings.site_logo)}" style="width:36px;height:36px;border-radius:9px;object-fit:cover;flex-shrink:0">`
    : `<div class="logo-mark">F</div>`;
  return `
<div class="sidebar-overlay" id="sidebar-overlay" onclick="closeSidebar()"></div>
  <div class="mobile-topbar">
    <button class="mobile-menu-btn" onclick="openSidebar()">☰</button>
    <span style="font-weight:900;font-size:16px;color:var(--text)">${esc(S.siteSettings.site_name || 'FB Manager')}</span>
    <button class="theme-toggle" onclick="toggleTheme()">☀️</button>
  </div>
  <div class="app-layout" dir="rtl">
    <aside class="sidebar" id="sidebar">      <div class="sidebar-header">
        ${logoHtml}
        <div>
          <div class="sidebar-brand">${esc(S.siteSettings.site_name || 'FB Manager')}</div>
          <div class="sidebar-sub">لوحتي</div>
        </div>
      </div>
      <nav class="sidebar-nav">
        <div class="nav-section">الصفحات</div>
        ${navItem('my-pages', 'pages', 'صفحاتي')}

        <div class="nav-section" style="display:flex;align-items:center;gap:6px">
          <span style="width:16px;height:16px;border-radius:4px;background:#1877F2;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-weight:900;font-size:9px">f</span>
          ترويج فيسبوك
        </div>
        ${navItem('posts-fb',    'posts',  'منشورات فيسبوك')}
        ${navItem('my-campaigns-fb', 'rocket', 'حملات فيسبوك')}

        <div class="nav-section" style="display:flex;align-items:center;gap:6px">
          <span style="width:16px;height:16px;border-radius:4px;background:linear-gradient(135deg,#f09433,#dc2743);display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:9px">📷</span>
          ترويج إنستاغرام
        </div>
        ${navItem('posts-ig',    'posts',  'منشورات إنستاغرام')}
        ${navItem('my-campaigns-ig', 'rocket', 'حملات إنستاغرام')}

        <div class="nav-section">المحفظة</div>
        ${navItem('wallet',          'wallet',  'محفظتي')}
        ${navItem('payment-history', 'history', 'سجل المدفوعات')}
      </nav>
      ${sidebarFooter()}
    </aside>
    <main class="main" id="main"></main>
  </div>`;
}

function navItem(id, icon, label, badge = '') {
  return `<button class="nav-item" id="nav-${id}" onclick="navigate('${id}')">
    <span class="nav-icon">${IC[icon] || icon}</span>
    <span style="flex:1">${label}</span>
    ${badge ? `<span class="nav-badge" id="badge-${badge}" style="display:none">0</span>` : ''}
  </button>`;
}

function sidebarFooter() {
  const initials = (S.user?.name || 'U').charAt(0).toUpperCase();
  return `
  <div class="sidebar-footer">
    <div class="user-chip">
      <div class="user-avatar">${initials}</div>
      <div>
        <div class="user-chip-name">${esc(S.user?.name || '')}</div>
        <div class="user-chip-role">${S.user?.role === 'admin' ? 'مدير النظام' : 'مستخدم'}</div>
      </div>
      <button class="theme-toggle" onclick="toggleTheme()" title="تبديل الثيم" style="margin-right:auto">☀️</button>
      <button class="logout-btn" onclick="doLogout()" title="تسجيل الخروج">${IC.logout}</button>
    </div>
  </div>`;
}

async function loadPendingBadges() {
  if (S.user?.role !== 'admin') return;
  const [statsRes, linkRes] = await Promise.all([
    API.get('admin/stats'),
    API.get('admin/link-requests/count'),
  ]);
  if (statsRes.success) {
    const { campaigns_pending, deposits_pending } = statsRes.stats;
    showBadge('campaigns', campaigns_pending);
    showBadge('deposits',  deposits_pending);
  }
  if (linkRes.success) {
    showBadge('link-requests', linkRes.count);
  }
}

function showBadge(id, count) {
  const el = document.getElementById('badge-' + id);
  if (!el) return;
  if (count > 0) { el.textContent = count; el.style.display = 'flex'; }
  else el.style.display = 'none';
}

//  Navigation 
const sections = {
  // Admin
  'dashboard':     renderDashboard,
  'token':         renderToken,
  'pages':         renderPages,
  'users':         renderUsers,
  'assign':        renderAssign,
  'link-requests': renderLinkRequests,
  'campaigns':     renderAdminCampaigns,
  'deposits':      renderDeposits,
  'pay-methods':   renderPayMethods,
  'site-settings': renderSiteSettings,
  'whatsapp-send': renderWhatsappSend,
  // User
  'my-pages':         renderMyPages,
  'posts':            renderPosts,
  'posts-fb':         () => { S.fbPage = S.lastFbPage || null; S.filterPlatform = 'facebook'; renderPostsByPlatform('facebook'); },
  'posts-ig':         () => { S.fbPage = S.lastIgPage || null; S.filterPlatform = 'instagram'; renderPostsByPlatform('instagram'); },
  'my-campaigns':     renderMyCampaigns,
  'my-campaigns-fb':  () => renderMyCampaignsByPlatform('facebook'),
  'my-campaigns-ig':  () => renderMyCampaignsByPlatform('instagram'),
  'wallet':           renderWallet,
  'payment-history':  renderPaymentHistory,
};

function navigate(id) {
  document.querySelectorAll('.nav-item').forEach(el => el.classList.remove('active'));
  const nav = document.getElementById('nav-' + id);
  if (nav) nav.classList.add('active');
  const fn = sections[id];
  if (fn) fn();
}

// 
//  ADMIN SECTIONS
// 

async function renderDashboard() {
  setMain(`
    <div class="page-header animate-fade-up">
      <div class="page-title">مرحباً ${esc(S.user.name)}</div>
      <div class="page-sub">نظرة عامة على النظام</div>
    </div>
    <div class="stats-grid" id="stats-grid">
      ${[1,2,3,4,5,6].map(i => `<div class="stat-card"><div class="skeleton" style="height:70px;border-radius:8px"></div></div>`).join('')}
    </div>
    <div class="grid-2">
      <div class="card animate-fade-up" style="animation-delay:.1s">
        <div class="card-header"><div class="card-title">آخر طلبات الشحن</div></div>
        <div id="recent-deposits"><div class="loading-center"><div class="spinner spinner-blue"></div></div></div>
      </div>
      <div class="card animate-fade-up" style="animation-delay:.15s">
        <div class="card-header"><div class="card-title">آخر الحملات</div></div>
        <div id="recent-campaigns"><div class="loading-center"><div class="spinner spinner-blue"></div></div></div>
      </div>
    </div>`);

  const [statsRes, depositsRes, campsRes] = await Promise.all([
    API.get('admin/stats'),
    API.get('admin/deposits'),
    API.get('admin/campaigns'),
  ]);

  if (statsRes.success) {
    const s = statsRes.stats;
    document.getElementById('stats-grid').innerHTML = `
      ${statCard('users',   s.users,              'مستخدمون',               'blue')}
      ${statCard('pages',   s.pages,              'صفحات فيسبوك',           'indigo')}
      ${statCard('rocket',  s.campaigns_total,    'إجمالي الحملات',         'purple')}
      ${statCard('clock',   s.campaigns_pending,  'حملات بانتظار المراجعة', 'amber')}
      ${statCard('card',    s.deposits_pending,   'طلبات شحن معلقة',        'red')}
      ${statCard('dollar', '$' + Number(s.total_revenue).toFixed(2), 'إجمالي الإيرادات', 'green')}
    `;
  }

  if (depositsRes.success) {
    const deps = (depositsRes.deposits || []).slice(0, 5);
    document.getElementById('recent-deposits').innerHTML = deps.length
      ? `<div class="table-wrap"><table>
          <tr><th>المستخدم</th><th>المبلغ</th><th>الحالة</th></tr>
          ${deps.map(d => `<tr>
            <td><strong>${esc(d.user_name)}</strong></td>
            <td><strong style="color:var(--green)">$${Number(d.amount).toFixed(2)}</strong></td>
            <td>${statusBadge(d.status)}</td>
          </tr>`).join('')}
        </table></div>`
      : emptyState('inbox', 'لا توجد طلبات بعد');
  }

  if (campsRes.success) {
    const camps = (campsRes.campaigns || []).slice(0, 5);
    document.getElementById('recent-campaigns').innerHTML = camps.length
      ? `<div class="table-wrap"><table>
          <tr><th>الحملة</th><th>الميزانية</th><th>الحالة</th></tr>
          ${camps.map(c => `<tr>
            <td><strong>${esc(c.campaign_name)}</strong></td>
            <td><strong style="color:var(--blue)">$${Number(c.budget).toFixed(2)}</strong></td>
            <td>${statusBadge(c.status)}</td>
          </tr>`).join('')}
        </table></div>`
      : emptyState('rocket', 'لا توجد حملات بعد');
  }
}

async function renderToken() {
  setMain(`
    <div class="page-header animate-fade-up">
      <div class="page-title">توكنات الربط</div>
      <div class="page-sub">ربط حسابات فيسبوك وإنستاغرام</div>
    </div>
    <div id="tok-status-wrap" class="mb-4 animate-fade-up"></div>
    <div class="grid-2 animate-fade-up">

      <!-- Facebook Token -->
      <div class="card">
        <div class="card-header">
          <div class="card-title" style="display:flex;align-items:center;gap:8px">
            <span style="display:inline-flex;width:32px;height:32px;border-radius:8px;background:#1877F2;align-items:center;justify-content:center;color:#fff;font-weight:900;font-size:16px">f</span>
            توكن فيسبوك
          </div>
        </div>
        <div class="card-body">
          <div class="alert alert-info" style="font-size:12px">احصل على Long-lived Token من <a href="https://developers.facebook.com/tools/explorer/" target="_blank" style="color:var(--blue)">Graph API Explorer</a></div>
          <div id="tok-fb-alert"></div>
          <div class="form-group">
            <label class="form-label">Facebook User Access Token</label>
            <textarea class="form-control" id="tok-fb-val" rows="4" placeholder="EAAxxxxxx..." dir="ltr" style="font-family:monospace;font-size:12px"></textarea>
          </div>
          <button class="btn btn-primary" id="tok-fb-btn" onclick="saveToken('facebook')">${IC.save} حفظ توكن فيسبوك</button>
        </div>
      </div>

      <!-- Instagram Token -->
      <div class="card">
        <div class="card-header">
          <div class="card-title" style="display:flex;align-items:center;gap:8px">
            <span style="display:inline-flex;width:32px;height:32px;border-radius:8px;background:linear-gradient(135deg,#f09433,#e6683c,#dc2743,#cc2366,#bc1888);align-items:center;justify-content:center;color:#fff;font-size:16px">${IC.instagram||'📷'}</span>
            توكن إنستاغرام
          </div>
        </div>
        <div class="card-body">
          <div class="alert alert-info" style="font-size:12px">يجب أن يكون لديك صفحة فيسبوك مرتبطة بحساب إنستاغرام Business/Creator</div>
          <div id="tok-ig-alert"></div>
          <div class="form-group">
            <label class="form-label">Facebook User Access Token (مع صلاحية instagram_basic)</label>
            <textarea class="form-control" id="tok-ig-val" rows="4" placeholder="EAAxxxxxx..." dir="ltr" style="font-family:monospace;font-size:12px"></textarea>
          </div>
          <button class="btn btn-primary" id="tok-ig-btn" onclick="saveToken('instagram')" style="background:linear-gradient(135deg,#f09433,#dc2743,#bc1888);border:none">${IC.save} حفظ توكن إنستاغرام</button>
        </div>
      </div>

    </div>`);

  // Load current token status
  const res = await API.get('admin/token-status');
  if (res.success) {
    const t = res.tokens;
    const fbOk = !!t.facebook;
    const igOk = !!t.instagram;
    q('#tok-status-wrap').innerHTML = `
      <div style="display:flex;gap:12px;flex-wrap:wrap">
        <div class="flex items-center gap-2" style="background:var(--bg2);padding:10px 16px;border-radius:10px;border:1px solid var(--border)">
          <span style="width:10px;height:10px;border-radius:50%;background:${fbOk?'var(--green)':'var(--red)'}"></span>
          <span class="text-sm">فيسبوك: <strong>${fbOk ? 'مفعّل' : 'غير مفعّل'}</strong>${fbOk ? ` <span class="text-muted" style="font-size:11px">(${fmtDate(t.facebook)})</span>` : ''}</span>
        </div>
        <div class="flex items-center gap-2" style="background:var(--bg2);padding:10px 16px;border-radius:10px;border:1px solid var(--border)">
          <span style="width:10px;height:10px;border-radius:50%;background:${igOk?'var(--green)':'var(--red)'}"></span>
          <span class="text-sm">إنستاغرام: <strong>${igOk ? 'مفعّل' : 'غير مفعّل'}</strong>${igOk ? ` <span class="text-muted" style="font-size:11px">(${fmtDate(t.instagram)})</span>` : ''}</span>
        </div>
      </div>`;
  }
}

async function saveToken(platform = 'facebook') {
  const valId  = platform === 'instagram' ? '#tok-ig-val'   : '#tok-fb-val';
  const alertId= platform === 'instagram' ? '#tok-ig-alert' : '#tok-fb-alert';
  const btnId  = platform === 'instagram' ? '#tok-ig-btn'   : '#tok-fb-btn';
  const v   = q(valId)?.value.trim();
  const btn = q(btnId);
  qInner(alertId, '');
  if (!v) return qInner(alertId, alert_('الرجاء إدخال التوكن', 'error'));
  setBtn(btn, true);
  const res = await API.post('admin/token', { token: v, platform });
  qInner(alertId, alert_(res.message, res.success ? 'success' : 'error'));
  setBtn(btn, false, platform === 'instagram' ? 'حفظ توكن إنستاغرام' : 'حفظ توكن فيسبوك');
  if (res.success) renderToken(); // refresh status
}

async function renderPages() {
  setMain(`
    <div class="page-header animate-fade-up">
      <div class="page-title">إدارة الصفحات</div>
      <div class="page-sub">صفحات فيسبوك وحسابات إنستاغرام المرتبطة</div>
    </div>
    <div class="flex gap-3 mb-4 animate-fade-up" style="flex-wrap:wrap">
      <button class="btn btn-primary" id="fetch-all-btn" onclick="fetchPages('all')">${IC.refresh} جلب الكل</button>
      <button class="btn btn-ghost" id="fetch-fb-btn" onclick="fetchPages('facebook')" style="display:inline-flex;align-items:center;gap:8px">
        <span style="width:20px;height:20px;border-radius:5px;background:#1877F2;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-weight:900;font-size:12px;flex-shrink:0">f</span>
        جلب صفحات فيسبوك
      </button>
      <button class="btn btn-ghost" id="fetch-ig-btn" onclick="fetchPages('instagram')" style="display:inline-flex;align-items:center;gap:8px">
        <span style="width:20px;height:20px;border-radius:5px;background:linear-gradient(135deg,#f09433,#dc2743,#bc1888);display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:11px;flex-shrink:0">📷</span>
        جلب حسابات إنستاغرام
      </button>
    </div>
    <div id="fetch-alert"></div>

    <!-- Facebook pages -->
    <div class="card animate-fade-up mb-4">
      <div class="card-header">
        <div class="card-title" style="display:flex;align-items:center;gap:8px">
          <span style="width:26px;height:26px;border-radius:6px;background:#1877F2;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-weight:900;font-size:14px">f</span>
          صفحات فيسبوك
        </div>
      </div>
      <div id="fb-pages-tbl" class="table-wrap"><div class="loading-center"><div class="spinner spinner-blue"></div></div></div>
    </div>

    <!-- Instagram accounts -->
    <div class="card animate-fade-up">
      <div class="card-header">
        <div class="card-title" style="display:flex;align-items:center;gap:8px">
          <span style="width:26px;height:26px;border-radius:6px;background:linear-gradient(135deg,#f09433,#dc2743,#bc1888);display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:14px">📷</span>
          حسابات إنستاغرام
        </div>
      </div>
      <div id="ig-pages-tbl" class="table-wrap"><div class="loading-center"><div class="spinner spinner-blue"></div></div></div>
    </div>`);
  loadPagesTable();
}

async function fetchPages(platform = 'all') {
  const btnMap = { all: '#fetch-all-btn', facebook: '#fetch-fb-btn', instagram: '#fetch-ig-btn' };
  const btn = q(btnMap[platform]);
  qInner('#fetch-alert', '');
  if (btn) setBtn(btn, true, 'جاري الجلب...');
  const res = await API.get('admin/pages/fetch?platform=' + platform);
  qInner('#fetch-alert', alert_(res.message, res.success ? 'success' : 'error'));
  if (res.success) renderPagesTableSplit(res.pages || []);
  if (btn) setBtn(btn, false, btn.textContent);
}

async function loadPagesTable() {
  const res = await API.get('admin/pages');
  renderPagesTableSplit(res.pages || []);
}

function renderPagesTableSplit(pages) {
  const fbPages = pages.filter(p => p.platform === 'facebook' || !p.platform);
  const igPages = pages.filter(p => p.platform === 'instagram');
  renderPlatformTable('#fb-pages-tbl', fbPages, 'facebook');
  renderPlatformTable('#ig-pages-tbl', igPages, 'instagram');
}

function renderPlatformTable(sel, pages, platform) {
  const el = q(sel);
  if (!el) return;
  if (!pages.length) {
    const label = platform === 'instagram' ? 'إنستاغرام' : 'فيسبوك';
    el.innerHTML = emptyState(platform === 'instagram' ? 'posts' : 'pages', `لا توجد ${label === 'إنستاغرام' ? 'حسابات' : 'صفحات'} ${label}`, 'اضغط زر الجلب أعلاه');
    return;
  }
  el.innerHTML = `<table>
    <thead><tr><th>#</th><th>الاسم</th><th>المعرّف</th><th>تاريخ الإضافة</th></tr></thead>
    <tbody>${pages.map((p, i) => `<tr>
      <td>${i+1}</td>
      <td><strong>${esc(p.page_name)}</strong></td>
      <td><code style="color:var(--muted);font-size:11px">${esc(p.page_id)}</code></td>
      <td class="text-sm text-muted">${fmtDate(p.created_at)}</td>
    </tr>`).join('')}</tbody>
  </table>`;
}

async function renderUsers() {
  setMain(`
    <div class="page-header animate-fade-up">
      <div class="page-title">إدارة المستخدمين</div>
      <div class="page-sub">جميع المستخدمين المسجلين</div>
    </div>
    <div class="card animate-fade-up">
      <div class="card-header"><div class="card-title">المستخدمون</div></div>
      <div id="users-tbl" class="table-wrap"><div class="loading-center"><div class="spinner spinner-blue"></div></div></div>
    </div>`);

  const res = await API.get('admin/users');
  const users = res.users || [];
  const el = q('#users-tbl');

  if (!users.length) { el.innerHTML = emptyState('users', 'لا يوجد مستخدمون'); return; }
  el.innerHTML = `<table>
    <thead><tr><th>#</th><th>الاسم</th><th>الهاتف</th><th>الدور</th><th>الرصيد</th><th>تاريخ التسجيل</th><th>إجراء</th></tr></thead>
    <tbody>${users.map((u, i) => `<tr>
      <td>${i+1}</td>
      <td><strong>${esc(u.name)}</strong></td>
      <td dir="ltr">${esc(u.phone)}</td>
      <td>${u.role === 'admin' ? '<span class="badge badge-admin">مدير</span>' : '<span class="badge badge-cyan">مستخدم</span>'}</td>
      <td><strong style="color:var(--green)">$${Number(u.balance).toFixed(2)}</strong></td>
      <td class="text-sm text-muted">${fmtDate(u.created_at)}</td>
      <td>
        ${u.role !== 'admin' ? `<button class="btn btn-ghost btn-sm" onclick="editBalance(${u.id},'${esc(u.name)}',${u.balance})">${IC.edit} تعديل الرصيد</button>` : ''}
      </td>
    </tr>`).join('')}</tbody>
  </table>`;
}

async function editBalance(userId, name, current) {
  const amount = prompt(`رصيد ${name} الحالي: $${current}\nأدخل الرصيد الجديد:`, current);
  if (amount === null || isNaN(parseFloat(amount))) return;
  const res = await API.post('admin/user-balance', { user_id: userId, amount: parseFloat(amount) });
  if (res.success) { showToast('تم تحديث الرصيد'); renderUsers(); }
  else showToast('' + res.message, 'error');
}

let assignState = { users: [], pages: [], selectedUser: null };

async function renderAssign() {
  setMain(`
    <div class="page-header animate-fade-up">
      <div class="page-title">تعيين الصلاحيات</div>
      <div class="page-sub">امنح المستخدمين صلاحية عرض الصفحات</div>
    </div>
    <div id="assign-alert"></div>
    <div class="grid-2 animate-fade-up">
      <div class="card">
        <div class="card-header"><div class="card-title">اختر مستخدماً</div></div>
        <div class="card-body" id="assign-users-list"><div class="loading-center"><div class="spinner spinner-blue"></div></div></div>
      </div>
      <div class="card" id="assign-pages-card" style="display:none">
        <div class="card-header">
          <div class="card-title" id="assign-pages-title">صفحات المستخدم</div>
        </div>
        <div class="card-body">
          <div id="assign-pages-list"></div>
          <hr class="divider">

          <!-- Facebook assign -->
          <div class="form-group">
            <label class="form-label" style="display:flex;align-items:center;gap:6px">
              <span style="width:18px;height:18px;border-radius:4px;background:#1877F2;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-weight:900;font-size:10px">f</span>
              إضافة صفحة فيسبوك
            </label>
            <div class="flex gap-2">
              <select class="form-control" id="add-fb-page-sel"><option value="">-- اختر صفحة فيسبوك --</option></select>
              <button class="btn btn-primary btn-sm" onclick="doAssignPage('facebook')" style="flex-shrink:0">${IC.check} منح</button>
            </div>
          </div>

          <!-- Instagram assign -->
          <div class="form-group">
            <label class="form-label" style="display:flex;align-items:center;gap:6px">
              <span style="width:18px;height:18px;border-radius:4px;background:linear-gradient(135deg,#f09433,#dc2743,#bc1888);display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:10px">📷</span>
              إضافة حساب إنستاغرام
            </label>
            <div class="flex gap-2">
              <select class="form-control" id="add-ig-page-sel"><option value="">-- اختر حساب إنستاغرام --</option></select>
              <button class="btn btn-sm btn-ig" onclick="doAssignPage('instagram')" style="flex-shrink:0;background:linear-gradient(135deg,#f09433,#dc2743,#bc1888);color:#fff;border:none;padding:8px 14px;border-radius:8px;font-weight:700;cursor:pointer">${IC.check} منح</button>
            </div>
          </div>

        </div>
      </div>
    </div>`);

  const [usersRes, pagesRes] = await Promise.all([API.get('admin/users'), API.get('admin/pages')]);
  assignState.users = (usersRes.users || []).filter(u => u.role === 'user');
  assignState.pages = pagesRes.pages || [];

  // Populate selects
  const fbSel = q('#add-fb-page-sel');
  const igSel = q('#add-ig-page-sel');
  assignState.pages.forEach(p => {
    const o = document.createElement('option');
    o.value = p.id;
    o.textContent = p.page_name;
    if (p.platform === 'instagram') igSel.appendChild(o);
    else fbSel.appendChild(o);
  });

  const ul = q('#assign-users-list');
  if (!assignState.users.length) { ul.innerHTML = emptyState('users', 'لا يوجد مستخدمون'); return; }
  ul.innerHTML = assignState.users.map(u => `
    <div class="flex items-center gap-3 mb-2" style="padding:12px;border-radius:10px;border:1px solid var(--border);cursor:pointer;transition:all .2s"
         id="au-${u.id}" onclick="selectAssignUser(${u.id},'${esc(u.name)}')"
         onmouseover="this.style.borderColor='var(--blue)'" onmouseout="if(!this.classList.contains('selected'))this.style.borderColor='var(--border)'">
      <div class="user-avatar" style="width:38px;height:38px;border-radius:10px">${u.name.charAt(0).toUpperCase()}</div>
      <div>
        <div class="font-bold">${esc(u.name)}</div>
        <div class="text-sm text-muted">${esc(u.phone)}</div>
      </div>
    </div>`).join('');
}

async function selectAssignUser(id, name) {
  document.querySelectorAll('[id^="au-"]').forEach(el => { el.classList.remove('selected'); el.style.borderColor = 'var(--border)'; el.style.background = ''; });
  const el = document.getElementById('au-' + id);
  if (el) { el.classList.add('selected'); el.style.borderColor = 'var(--blue)'; el.style.background = 'rgba(59,130,246,.05)'; }

  assignState.selectedUser = id;
  const card = q('#assign-pages-card');
  card.style.display = 'block';
  q('#assign-pages-title').textContent = `صفحات: ${name}`;
  loadAssignedPages(id);
}

async function loadAssignedPages(userId) {
  const el = q('#assign-pages-list');
  el.innerHTML = '<div class="loading-center" style="padding:20px"><div class="spinner spinner-blue"></div></div>';
  const res = await API.get('admin/user-pages?user_id=' + userId);
  const pages = res.pages || [];
  if (!pages.length) { el.innerHTML = '<p class="text-sm text-muted">لا توجد صفحات مُعيّنة بعد.</p>'; return; }

  const fbPages = pages.filter(p => p.platform === 'facebook' || !p.platform);
  const igPages = pages.filter(p => p.platform === 'instagram');

  const renderGroup = (list, label, color) => list.length ? `
    <div style="margin-bottom:10px">
      <div class="text-sm text-muted font-bold mb-2" style="color:${color}">${label}</div>
      ${list.map(p => `
        <div class="flex items-center gap-2 mb-2" style="background:var(--bg2);padding:9px 12px;border-radius:8px">
          <span style="flex:1;font-size:13px;font-weight:700">${esc(p.page_name)}</span>
          <button class="btn btn-danger btn-xs" onclick="doRevokePage(${p.id})">${IC.trash} حذف</button>
        </div>`).join('')}
    </div>` : '';

  el.innerHTML =
    renderGroup(fbPages, 'فيسبوك', '#1877F2') +
    renderGroup(igPages, 'إنستاغرام', '#dc2743') ||
    '<p class="text-sm text-muted">لا توجد صفحات مُعيّنة بعد.</p>';
}

async function doAssignPage(platform = 'facebook') {
  if (!assignState.selectedUser) return showToast('اختر مستخدماً أولاً', 'warning');
  const selId = platform === 'instagram' ? '#add-ig-page-sel' : '#add-fb-page-sel';
  const pageId = q(selId)?.value;
  if (!pageId) return showToast('اختر ' + (platform === 'instagram' ? 'حساب إنستاغرام' : 'صفحة فيسبوك'), 'warning');
  const res = await API.post('admin/assign-page', { user_id: assignState.selectedUser, page_id: parseInt(pageId) });
  if (res.success) { showToast('تم التعيين'); loadAssignedPages(assignState.selectedUser); }
  else showToast('' + res.message, 'error');
}

async function doRevokePage(pageId) {
  if (!assignState.selectedUser) return;
  await API.post('admin/revoke-page', { user_id: assignState.selectedUser, page_id: pageId });
  loadAssignedPages(assignState.selectedUser);
}

//  Admin: Campaigns 
async function renderAdminCampaigns() {
  setMain(`
    <div class="page-header animate-fade-up">
      <div class="page-title">الحملات الإعلانية</div>
      <div class="page-sub">مراجعة وإدارة حملات المستخدمين</div>
    </div>
    <div class="tabs animate-fade-up">
      <button class="tab active" onclick="loadAdminCamps('pending',this)">⏳ بانتظار المراجعة</button>
      <button class="tab" onclick="loadAdminCamps('approved',this)">موافق عليها</button>
      <button class="tab" onclick="loadAdminCamps('rejected',this)">مرفوضة</button>
      <button class="tab" onclick="loadAdminCamps('',this)">الكل</button>
    </div>
    <div id="camps-list"><div class="loading-center"><div class="spinner spinner-blue"></div></div></div>`);
  loadAdminCamps('pending');
  showBadge('campaigns', 0);
}

async function loadAdminCamps(status, tabEl) {
  if (tabEl) { document.querySelectorAll('.tab').forEach(t => t.classList.remove('active')); tabEl.classList.add('active'); }
  const el = q('#camps-list');
  el.innerHTML = '<div class="loading-center"><div class="spinner spinner-blue"></div></div>';
  const res = await API.get('admin/campaigns' + (status ? '?status=' + status : ''));
  const camps = res.campaigns || [];
  if (!camps.length) { el.innerHTML = emptyState('rocket', 'لا توجد حملات', ''); return; }
  el.innerHTML = camps.map(c => campaignCardAdmin(c)).join('');
}

function campaignCardAdmin(c) {
  const locs = (c.locations || []).map(l => l.name || l).join(' • ');
  return `
  <div class="campaign-card mb-4">
    <div class="campaign-header">
      ${c.post_picture ? `<img src="${esc(c.post_picture)}" class="campaign-thumb">` : `<div class="campaign-thumb flex items-center justify-center" style="font-size:22px"></div>`}
      <div style="flex:1">
        <div class="font-bold" style="font-size:15px">${esc(c.campaign_name)}</div>
        <div class="text-sm text-muted mt-1">${esc(c.user_name)} · ${esc(c.page_name)}</div>
        <div class="campaign-meta">
          ${statusBadge(c.status)}
          <span class="badge badge-blue">$${Number(c.budget).toFixed(2)}</span>
          <span class="badge badge-gray">${objLabel(c.objective)}</span>
        </div>
      </div>
      <div class="text-sm text-muted">${fmtDate(c.created_at)}</div>
    </div>
    <div class="campaign-body">
      <div class="grid-2 text-sm" style="gap:8px;margin-bottom:12px">
        <div><span class="text-muted">الجنس: </span><strong>${genderLabel(c.gender)}</strong></div>
        <div><span class="text-muted">العمر: </span><strong>${c.age_min}–${c.age_max}</strong></div>
        <div style="grid-column:1/-1"><span class="text-muted">المناطق: </span><strong>${locs || 'لم تحدد'}</strong></div>
        ${c.post_message ? `<div style="grid-column:1/-1"><span class="text-muted">المنشور: </span>${esc(c.post_message.slice(0,80))}${c.post_message.length>80?'...':''}</div>` : ''}
        ${c.admin_note ? `<div style="grid-column:1/-1"><span class="text-muted">ملاحظة: </span><em>${esc(c.admin_note)}</em></div>` : ''}
      </div>
      ${c.status === 'pending' ? `
      <div class="flex gap-2">
        <button class="btn btn-success btn-sm" onclick="updateCamp(${c.id},'approved')">${IC.check} موافقة</button>
        <button class="btn btn-danger btn-sm" onclick="updateCamp(${c.id},'rejected')">${IC.x} رفض</button>
        <button class="btn btn-ghost btn-sm" onclick="updateCamp(${c.id},'running')">${IC.play} تشغيل</button>
      </div>` : `
      <div class="flex gap-2">
        ${c.status !== 'completed' && c.status !== 'rejected' ? `<select class="form-control" style="width:auto;padding:6px 10px;font-size:12px" onchange="updateCamp(${c.id},this.value)">
          <option value="">-- تغيير الحالة --</option>
          <option value="approved">موافق عليه</option>
          <option value="running">يعمل</option>
          <option value="paused">متوقف</option>
          <option value="completed">مكتمل</option>
          <option value="rejected">مرفوض</option>
        </select>` : ''}
      </div>`}
    </div>
  </div>`;
}

async function updateCamp(id, status) {
  let note = '';
  if (status === 'rejected') note = prompt('سبب الرفض (اختياري):') || '';
  const res = await API.post('admin/campaigns/update', { id, status, note });
  if (res.success) { showToast('تم التحديث'); loadAdminCamps(''); loadPendingBadges(); }
  else showToast('' + res.message, 'error');
}

//  Admin: Deposits 
async function renderDeposits() {
  setMain(`
    <div class="page-header animate-fade-up">
      <div class="page-title">طلبات الشحن</div>
      <div class="page-sub">مراجعة طلبات إيداع الرصيد</div>
    </div>
    <div class="tabs animate-fade-up">
      <button class="tab active" onclick="loadDeposits('pending',this)">معلقة</button>
      <button class="tab" onclick="loadDeposits('approved',this)">موافق عليها</button>
      <button class="tab" onclick="loadDeposits('rejected',this)">مرفوضة</button>
      <button class="tab" onclick="loadDeposits('',this)">الكل</button>
    </div>
    <div id="deposits-list"><div class="loading-center"><div class="spinner spinner-blue"></div></div></div>`);
  loadDeposits('pending');
  showBadge('deposits', 0);
}

async function loadDeposits(status, tabEl) {
  if (tabEl) { document.querySelectorAll('.tab').forEach(t => t.classList.remove('active')); tabEl.classList.add('active'); }
  const el = q('#deposits-list');
  el.innerHTML = '<div class="loading-center"><div class="spinner spinner-blue"></div></div>';
  const res = await API.get('admin/deposits' + (status ? '?status=' + status : ''));
  const deps = res.deposits || [];
  if (!deps.length) { el.innerHTML = emptyState('inbox', 'لا توجد طلبات'); return; }
  el.innerHTML = `<div class="card animate-fade-up"><div class="table-wrap"><table>
    <thead><tr><th>#</th><th>المستخدم</th><th>طريقة الدفع</th><th>المبلغ</th><th>إثبات</th><th>الحالة</th><th>التاريخ</th><th>إجراء</th></tr></thead>
    <tbody>${deps.map((d, i) => `<tr>
      <td>${i+1}</td>
      <td><strong>${esc(d.user_name)}</strong><br><span class="text-sm text-muted">${esc(d.phone)}</span></td>
      <td>${esc(d.method_name)}</td>
      <td><strong style="color:var(--green)">$${Number(d.amount).toFixed(2)}</strong></td>
      <td>${d.receipt_image ? `<a href="${esc(d.receipt_image)}" target="_blank" class="btn btn-ghost btn-xs">${IC.externalLink} عرض</a>` : '<span class="text-muted">—</span>'}</td>
      <td>${statusBadge(d.status)}</td>
      <td class="text-sm text-muted">${fmtDate(d.created_at)}</td>
      <td>
        ${d.status === 'pending' ? `
          <button class="btn btn-success btn-xs" onclick="updateDeposit(${d.id},'approved')">${IC.check}</button>
          <button class="btn btn-danger btn-xs" onclick="updateDeposit(${d.id},'rejected')">${IC.x}</button>
        ` : '—'}
      </td>
    </tr>`).join('')}</tbody>
  </table></div></div>`;
}

async function updateDeposit(id, status) {
  let note = '';
  if (status === 'rejected') note = prompt('سبب الرفض (اختياري):') || '';
  const res = await API.post('admin/deposits/update', { id, status, note });
  if (res.success) { showToast('تم التحديث'); loadDeposits(''); loadPendingBadges(); }
  else showToast('' + res.message, 'error');
}

//  Admin: Payment Methods 
async function renderPayMethods() {
  setMain(`
    <div class="page-header animate-fade-up">
      <div class="page-title">طرق الدفع</div>
      <div class="page-sub">إدارة طرق الدفع اليدوي</div>
    </div>
    <div class="flex gap-3 mb-4 animate-fade-up">
      <button class="btn btn-primary" onclick="showPMForm()">${IC.plus} إضافة طريقة دفع</button>
    </div>
    <div id="pm-form-wrap" style="display:none" class="mb-4 animate-fade-up">
      <div class="card" style="max-width:560px">
        <div class="card-header"><div class="card-title" id="pm-form-title">${IC.plus} إضافة طريقة دفع</div></div>
        <div class="card-body">
          <input type="hidden" id="pm-id">
          <div id="pm-alert"></div>
          <div class="form-group"><label class="form-label">اسم الطريقة</label><input class="form-control" id="pm-name" placeholder="تحويل بنكي..."></div>
          <div class="form-group"><label class="form-label">وصف (اختياري)</label><textarea class="form-control" id="pm-desc" rows="2"></textarea></div>
          <div class="form-group"><label class="form-label">عنوان الدفع / التعليمات</label><textarea class="form-control" id="pm-addr" rows="4" placeholder="IBAN / رقم المحفظة..."></textarea></div>
          <div class="flex gap-2">
            <button class="btn btn-primary" onclick="savePM()">${IC.save} حفظ</button>
            <button class="btn btn-ghost" onclick="hidePMForm()">إلغاء</button>
          </div>
        </div>
      </div>
    </div>
    <div id="pm-list"><div class="loading-center"><div class="spinner spinner-blue"></div></div></div>`);
  loadPMs();
}

async function loadPMs() {
  const res = await API.get('admin/payment-methods');
  const methods = res.methods || [];
  const el = q('#pm-list');
  if (!methods.length) { el.innerHTML = emptyState('card', 'لا توجد طرق دفع', 'أضف طريقة دفع أعلاه'); return; }
  el.innerHTML = `<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px">
    ${methods.map(m => `
      <div class="pm-card">
        <div class="flex items-center gap-2 mb-2">
          <strong style="flex:1">${esc(m.name)}</strong>
          <span class="badge ${m.is_active ? 'badge-green' : 'badge-gray'}">${m.is_active ? 'نشط' : 'معطل'}</span>
        </div>
        ${m.description ? `<p class="text-sm text-muted mb-2">${esc(m.description)}</p>` : ''}
        <div class="pm-address">${esc(m.address)}</div>
        <div class="flex gap-2 mt-3">
          <button class="btn btn-ghost btn-xs" onclick="editPM(${m.id},'${esc(m.name)}','${esc(m.description||'')}','${esc(m.address)}',${m.is_active})">${IC.edit} تعديل</button>
          <button class="btn btn-danger btn-xs" onclick="deletePM(${m.id})">${IC.trash} حذف</button>
        </div>
      </div>`).join('')}
  </div>`;
}

function showPMForm(title = 'إضافة طريقة دفع') {
  q('#pm-form-wrap').style.display = 'block';
  q('#pm-form-title').textContent = ' ' + title;
}

function hidePMForm() {
  q('#pm-form-wrap').style.display = 'none';
  ['pm-id','pm-name','pm-desc','pm-addr'].forEach(id => q('#'+id).value = '');
}

function editPM(id, name, desc, addr, active) {
  q('#pm-id').value = id; q('#pm-name').value = name;
  q('#pm-desc').value = desc; q('#pm-addr').value = addr;
  showPMForm('تعديل طريقة الدفع');
}

async function savePM() {
  const data = {
    id: q('#pm-id').value, name: q('#pm-name').value.trim(),
    description: q('#pm-desc').value.trim(), address: q('#pm-addr').value.trim(), is_active: 1,
  };
  if (!data.name || !data.address) return qInner('#pm-alert', alert_('الاسم والعنوان مطلوبان', 'error'));
  const res = await API.post('admin/payment-methods', data);
  if (res.success) { showToast('تم الحفظ'); hidePMForm(); loadPMs(); }
  else qInner('#pm-alert', alert_(res.message, 'error'));
}

async function deletePM(id) {
  if (!confirm('حذف طريقة الدفع هذه؟')) return;
  await API.post('admin/payment-methods/delete', { id });
  showToast('تم الحذف'); loadPMs();
}

// 
//  USER SECTIONS
// 

async function renderMyPages() {
  setMain(`
    <div class="page-header animate-fade-up">
      <div class="page-title">صفحاتي</div>
      <div class="page-sub">الصفحات المتاحة لك</div>
    </div>
    <div id="pages-grid"><div class="loading-center"><div class="spinner spinner-blue"></div></div></div>`);

  const res = await API.get('user/pages');
  const pages = res.pages || [];
  const el = q('#pages-grid');

  if (!pages.length) {
    el.innerHTML = `
      <div class="empty-state">
        <span class="empty-icon"></span>
        <h3>لا توجد صفحات مربوطة بحسابك</h3>
        <p>اربط صفحتك الآن لإنشاء أول إعلان لك</p>
        <button class="btn btn-primary mt-4" onclick="showLinkRequestForm()" style="margin:0 auto">${IC.link} قم بربط صفحتك</button>
      </div>
      <div id="link-form-wrap" style="display:none;max-width:480px;margin:24px auto 0"></div>`;
    return;
  }

  const fbPages = pages.filter(p => p.platform === 'facebook' || !p.platform);
  const igPages = pages.filter(p => p.platform === 'instagram');

  // Build cards for a list of pages
  async function buildCards(list) {
    return await Promise.all(list.map(async (p, i) => {
      let logoHtml = `<div class="page-card-icon">${p.page_name.charAt(0).toUpperCase()}</div>`;
      try {
        const picRes = await API.get(`user/page-pic?page_id=${encodeURIComponent(p.page_id)}`);
        if (picRes.success && picRes.picture) {
          logoHtml = `<img src="${esc(picRes.picture)}" style="width:64px;height:64px;border-radius:14px;object-fit:cover;margin-bottom:8px;box-shadow:0 4px 12px rgba(0,0,0,.15)">`;
        }
      } catch(e) {}
      const isIg = p.platform === 'instagram';
      return `
      <div class="page-card animate-fade-up" style="animation-delay:${i*0.05}s" onclick="openPage('${esc(p.page_id)}','${esc(p.page_name)}','${p.platform||'facebook'}')">
        ${logoHtml}
        <div class="page-card-name">${esc(p.page_name)}</div>
        <div class="page-card-id">${esc(p.page_id)}</div>
        <div class="flex items-center gap-2 mt-3">
          <button class="btn btn-ghost btn-sm" style="flex:1" onclick="event.stopPropagation();openPage('${esc(p.page_id)}','${esc(p.page_name)}','${p.platform||'facebook'}')">${IC.posts} المنشورات</button>
        </div>
      </div>`;
    }));
  }

  let html = '';

  if (fbPages.length) {
    const fbCards = await buildCards(fbPages);
    html += `
      <div class="platform-section mb-6">
        <div class="platform-section-header" style="display:flex;align-items:center;gap:10px;margin-bottom:16px">
          <span style="width:30px;height:30px;border-radius:8px;background:#1877F2;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-weight:900;font-size:16px">f</span>
          <h3 style="margin:0;font-size:16px;font-weight:700">صفحات فيسبوك</h3>
          <span class="badge badge-blue">${fbPages.length}</span>
        </div>
        <div class="pages-grid">${fbCards.join('')}</div>
      </div>`;
  }

  if (igPages.length) {
    const igCards = await buildCards(igPages);
    html += `
      <div class="platform-section">
        <div class="platform-section-header" style="display:flex;align-items:center;gap:10px;margin-bottom:16px">
          <span style="width:30px;height:30px;border-radius:8px;background:linear-gradient(135deg,#f09433,#dc2743,#bc1888);display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:16px">📷</span>
          <h3 style="margin:0;font-size:16px;font-weight:700">حسابات إنستاغرام</h3>
          <span class="badge" style="background:linear-gradient(135deg,#f09433,#dc2743);color:#fff">${igPages.length}</span>
        </div>
        <div class="pages-grid">${igCards.join('')}</div>
      </div>`;
  }

  el.innerHTML = html;
}

function showLinkRequestForm() {
  const wrap = q('#link-form-wrap');
  if (!wrap) return;
  wrap.style.display = 'block';
  wrap.innerHTML = `
    <div class="card animate-fade-up">
      <div class="card-header"><div class="card-title">ربط صفحة أو حساب</div></div>
      <div class="card-body">
        <div id="link-alert"></div>
        <div class="form-group">
          <label class="form-label">المنصة</label>
          <div class="flex gap-2">
            <button class="gender-pill selected" id="link-plat-fb" onclick="q('#link-plat-fb').classList.add('selected');q('#link-plat-ig').classList.remove('selected')">
              <span style="font-weight:900;margin-left:4px">f</span> فيسبوك
            </button>
            <button class="gender-pill" id="link-plat-ig" onclick="q('#link-plat-ig').classList.add('selected');q('#link-plat-fb').classList.remove('selected')" style="background:linear-gradient(135deg,#f09433,#dc2743) !important">
              📷 إنستاغرام
            </button>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">رابط الصفحة / الحساب</label>
          <input class="form-control" id="link-url" type="url" placeholder="https://facebook.com/yourpage أو https://instagram.com/youraccount" dir="ltr">
        </div>
        <div class="form-group">
          <label class="form-label">رقم واتساب للتواصل</label>
          <input class="form-control" id="link-wa" type="tel" placeholder="+966xxxxxxxxx" dir="ltr">
        </div>
        <div class="flex gap-2">
          <button class="btn btn-primary" id="link-submit-btn" onclick="submitLinkRequest()">${IC.upload} إرسال الطلب</button>
          <button class="btn btn-ghost" onclick="q('#link-form-wrap').style.display='none'">إلغاء</button>
        </div>
      </div>
    </div>`;
}

async function submitLinkRequest() {
  const pageUrl  = (q('#link-url')?.value || '').trim();
  const whatsapp = (q('#link-wa')?.value  || '').trim();
  const platform = q('#link-plat-ig')?.classList.contains('selected') ? 'instagram' : 'facebook';
  const btn      = q('#link-submit-btn');
  qInner('#link-alert', '');
  if (!pageUrl)  return qInner('#link-alert', alert_('الرجاء إدخال رابط الصفحة', 'error'));
  if (!whatsapp) return qInner('#link-alert', alert_('الرجاء إدخال رقم الواتساب', 'error'));
  setBtn(btn, true, 'جاري الإرسال...');
  const res = await API.post('user/link-request', { page_url: pageUrl, whatsapp, platform });
  if (res.success) {
    qInner('#link-alert', alert_(res.message, 'success'));
    setBtn(btn, false, 'إرسال الطلب');
    q('#link-url').value = '';
    q('#link-wa').value  = '';
  } else {
    qInner('#link-alert', alert_(res.message, 'error'));
    setBtn(btn, false, 'إرسال الطلب');
  }
}

function openPage(pageId, pageName, platform = 'facebook') {
  S.fbPage = { id: pageId, name: pageName, platform };
  navigate('posts');
}

// Platform-specific posts picker
async function renderPostsByPlatform(platform) {
  const isIg = platform === 'instagram';
  const label = isIg ? 'إنستاغرام' : 'فيسبوك';
  const color = isIg ? 'linear-gradient(135deg,#f09433,#dc2743,#bc1888)' : '#1877F2';
  const icon  = isIg ? '📷' : 'f';

  setMain(`
    <div class="page-header animate-fade-up">
      <div class="page-title" style="display:flex;align-items:center;gap:10px">
        <span style="width:32px;height:32px;border-radius:8px;background:${color};display:inline-flex;align-items:center;justify-content:center;color:#fff;font-weight:900;font-size:16px">${icon}</span>
        منشورات ${label}
      </div>
      <div class="page-sub">اختر ${isIg ? 'حساب إنستاغرام' : 'صفحة فيسبوك'} لعرض منشوراتها</div>
    </div>
    <div id="platform-pages-pick"><div class="loading-center"><div class="spinner spinner-blue"></div></div></div>`);

  const res = await API.get('user/pages');
  const pages = (res.pages || []).filter(p => isIg ? p.platform === 'instagram' : (p.platform === 'facebook' || !p.platform));
  const el = q('#platform-pages-pick');

  if (!pages.length) {
    el.innerHTML = emptyState('pages', `لا توجد ${isIg ? 'حسابات إنستاغرام' : 'صفحات فيسبوك'} مرتبطة بحسابك`, 'تواصل مع الأدمن للحصول على صلاحية');
    return;
  }

  const cards = await Promise.all(pages.map(async (p, i) => {
    let logoHtml = `<div class="page-card-icon">${p.page_name.charAt(0).toUpperCase()}</div>`;
    try {
      const picRes = await API.get(`user/page-pic?page_id=${encodeURIComponent(p.page_id)}`);
      if (picRes.success && picRes.picture) {
        logoHtml = `<img src="${esc(picRes.picture)}" style="width:64px;height:64px;border-radius:14px;object-fit:cover;margin-bottom:8px;box-shadow:0 4px 12px rgba(0,0,0,.15)">`;
      }
    } catch(e) {}
    return `
    <div class="page-card animate-fade-up" style="animation-delay:${i*0.05}s"
         onclick="openPage('${esc(p.page_id)}','${esc(p.page_name)}','${platform}')">
      ${logoHtml}
      <div class="page-card-name">${esc(p.page_name)}</div>
      <div class="page-card-id">${esc(p.page_id)}</div>
      <div class="flex items-center gap-2 mt-3">
        <button class="btn btn-ghost btn-sm" style="flex:1">${IC.posts} عرض المنشورات</button>
      </div>
    </div>`;
  }));

  el.innerHTML = `<div class="pages-grid">${cards.join('')}</div>`;
}

// Platform-specific campaigns
async function renderMyCampaignsByPlatform(platform) {
  const isIg = platform === 'instagram';
  const label = isIg ? 'إنستاغرام' : 'فيسبوك';
  const color = isIg ? 'linear-gradient(135deg,#f09433,#dc2743,#bc1888)' : '#1877F2';
  const icon  = isIg ? '📷' : 'f';

  setMain(`
    <div class="page-header animate-fade-up">
      <div class="page-title" style="display:flex;align-items:center;gap:10px">
        <span style="width:32px;height:32px;border-radius:8px;background:${color};display:inline-flex;align-items:center;justify-content:center;color:#fff;font-weight:900;font-size:16px">${icon}</span>
        حملات ${label}
      </div>
      <div class="page-sub">حملاتك الإعلانية على ${label}</div>
    </div>
    <div id="my-camps-list"><div class="loading-center"><div class="spinner spinner-blue"></div></div></div>`);

  const res = await API.get('user/campaigns');
  const camps = (res.campaigns || []).filter(c => {
    const pageId = c.page_id || '';
    return isIg ? pageId.startsWith('ig_') : !pageId.startsWith('ig_');
  });
  const el = q('#my-camps-list');

  if (!camps.length) { el.innerHTML = emptyState('rocket', `لا توجد حملات ${label}`, `اضغط "ترويج" على أي منشور ${label} لإنشاء حملة`); return; }

  el.innerHTML = camps.map((c, i) => `
  <div class="campaign-card mb-4 animate-fade-up" style="animation-delay:${i*.05}s">
    <div class="campaign-header">
      ${c.post_picture ? `<img src="${esc(c.post_picture)}" class="campaign-thumb">` : `<div class="campaign-thumb" style="background:var(--bg3);display:flex;align-items:center;justify-content:center;font-size:22px"></div>`}
      <div style="flex:1">
        <div class="font-bold" style="font-size:15px">${esc(c.campaign_name)}</div>
        <div class="text-sm text-muted mt-1">${esc(c.page_name)}</div>
        <div class="campaign-meta">
          ${statusBadge(c.status)}
          <span class="badge badge-blue">$${Number(c.budget).toFixed(2)}</span>
          <span class="badge badge-gray">${objLabel(c.objective)}</span>
        </div>
      </div>
      <div class="text-sm text-muted">${fmtDate(c.created_at)}</div>
    </div>
    ${c.admin_note ? `<div class="campaign-body"><div class="alert alert-info" style="margin:0">ملاحظة الأدمن: ${esc(c.admin_note)}</div></div>` : ''}
  </div>`).join('');
}

async function renderPosts() {
  if (!S.fbPage) {
    setMain(`<div class="page-header"><div class="page-title">${IC.posts} المنشورات</div></div>
      ${emptyState('hand', 'اختر صفحة', 'انتقل إلى "صفحاتي" واختر صفحة لعرض منشوراتها')}`);
    return;
  }

  const { id, name, platform } = S.fbPage;
  const isIg = platform === 'instagram';
  const platformBadge = isIg
    ? `<span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;padding:3px 8px;border-radius:20px;background:linear-gradient(135deg,#f09433,#dc2743);color:#fff;font-weight:700">📷 إنستاغرام</span>`
    : `<span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;padding:3px 8px;border-radius:20px;background:#1877F2;color:#fff;font-weight:700">f فيسبوك</span>`;

  setMain(`
    <div class="flex items-center gap-3 mb-4 animate-fade-up">
      <button class="btn btn-ghost btn-sm" onclick="navigate('my-pages')">← رجوع</button>
      <div id="page-header-info" style="display:flex;align-items:center;gap:12px">
        <div id="page-logo-wrap"></div>
        <div>
          <div style="display:flex;align-items:center;gap:8px">
            <div class="page-title" style="margin:0">${esc(name)}</div>
            ${platformBadge}
          </div>
          <div class="page-sub" style="margin:0">المنشورات المنشورة (٢٠ في كل صفحة)</div>
        </div>
      </div>
    </div>
    <div id="posts-wrap"><div class="loading-center"><div class="spinner spinner-blue"></div><p class="mt-3 text-muted">جاري تحميل المنشورات...</p></div></div>
    <div class="flex items-center justify-between mt-6" id="pagination-bar" style="display:none!important">
      <button class="btn btn-ghost" id="prev-page-btn" onclick="goPagePosts('before')" style="display:none">← السابق</button>
      <span id="page-num-label" class="text-sm text-muted"></span>
      <button class="btn btn-primary" id="next-page-btn" onclick="goPagePosts('after')" style="display:none">التالي ←</button>
    </div>`);

  S.postsCursors = { after: null, before: null };
  S.postsCurrentPage = 1;
  await loadPosts(id, null, null);
}

async function goPagePosts(direction) {
  const cursor = direction === 'after' ? S.postsCursors.after : S.postsCursors.before;
  if (!cursor) return;
  q('#posts-wrap').innerHTML = '<div class="loading-center"><div class="spinner spinner-blue"></div></div>';
  if (direction === 'after') S.postsCurrentPage++;
  else S.postsCurrentPage = Math.max(1, S.postsCurrentPage - 1);
  await loadPosts(S.fbPage.id, direction === 'after' ? cursor : null, direction === 'before' ? cursor : null);
}

async function loadPosts(pageId, after = null, before = null) {
  let url = `user/page-posts?page_id=${encodeURIComponent(pageId)}`;
  if (after)  url += `&after=${after}`;
  if (before) url += `&before=${before}`;

  const res = await API.get(url);

  if (!res.success) {
    q('#posts-wrap').innerHTML = `<div class="alert alert-error">${esc(res.message)}</div>`;
    return;
  }

  // Show page logo if returned
  const logoWrap = q('#page-logo-wrap');
  if (logoWrap && res.page_picture) {
    logoWrap.innerHTML = `<img src="${esc(res.page_picture)}" style="width:52px;height:52px;border-radius:12px;object-fit:cover;box-shadow:0 3px 10px rgba(0,0,0,.2)">`;
  }

  const posts = res.posts || [];
  const paging = res.paging || {};

  // Store cursors
  S.postsCursors = {
    after:  paging.cursors?.after  || null,
    before: paging.cursors?.before || null,
  };

  // Update pagination bar
  const bar     = q('#pagination-bar');
  const nextBtn = q('#next-page-btn');
  const prevBtn = q('#prev-page-btn');
  const numLbl  = q('#page-num-label');

  if (bar) bar.style.display = 'flex';
  if (nextBtn) nextBtn.style.display = S.postsCursors.after  ? 'inline-flex' : 'none';
  if (prevBtn) prevBtn.style.display = S.postsCursors.before ? 'inline-flex' : 'none';
  if (numLbl)  numLbl.textContent = `صفحة ${S.postsCurrentPage}`;

  if (!posts.length) {
    q('#posts-wrap').innerHTML = emptyState('inbox', 'لا توجد منشورات', 'لا يوجد محتوى في هذه الصفحة');
    return;
  }

  q('#posts-wrap').innerHTML = `<div class="posts-grid" id="posts-grid">${posts.map((p, i) => postCard(p, i)).join('')}</div>`;
}

function postCard(p, i) {
  const typeLabel = p.full_picture ? 'صورة' : 'نص';

  return `
  <div class="post-card animate-fade-up" style="animation-delay:${i * 0.04}s">
    ${p.full_picture ? `
      <div class="post-img">
        <img src="${esc(p.full_picture)}" alt="صورة المنشور" loading="lazy">
        <span class="post-type-badge">${IC.image} ${typeLabel}</span>
      </div>` : `<div style="padding:14px 16px 0"><span class="badge badge-gray">${IC.posts} ${typeLabel}</span></div>`}
    <div class="post-body">
      <div class="post-meta">
        <span style="display:inline-flex;align-items:center;gap:4px">${IC.clock} ${fmtDate(p.created_time)}</span>
      </div>
      <div class="post-msg">${esc(p.message || 'منشور بدون نص')}</div>
      <div class="post-stats">
        ${p.permalink_url ? `<a href="${esc(p.permalink_url)}" target="_blank" class="post-stat" style="color:var(--blue);text-decoration:none;display:inline-flex;align-items:center;gap:4px">${IC.externalLink} فتح الرابط</a>` : ''}
      </div>
      <div class="post-actions">
        <button class="btn btn-primary btn-sm" style="flex:1;display:inline-flex;align-items:center;justify-content:center;gap:6px"
          data-post='${esc(JSON.stringify({id:p.id,message:p.message,picture:p.full_picture,pageId:S.fbPage.id,pageName:S.fbPage.name}))}'
          onclick="openPromoModal(this.dataset.post)">
          ${IC.rocket} ترويج المنشور
        </button>
      </div>
    </div>
  </div>`;
}

//  Promote Modal 
let promoMap, promoMarkers = [], promoLocations = [], promoBudget = 50;
let promoGender = 'all', promoObjective = 'engagement';

function openPromoModal(jsonStr) {
  const post = JSON.parse(jsonStr);
  S.promotePost = post;
  promoLocations = [];
  promoGender    = 'all';
  promoObjective = 'engagement';
  promoBudget    = 50;

  document.body.insertAdjacentHTML('beforeend', `
  <div class="modal-overlay" id="promo-overlay" onclick="if(event.target===this)closePromo()">
    <div class="modal" onclick="event.stopPropagation()">
      <div class="modal-header">
        <div class="modal-title">ترويج المنشور</div>
        <button class="modal-close" onclick="closePromo()"></button>
      </div>
      <div class="modal-body">
        <div id="promo-alert"></div>

        <!-- Post preview -->
        <div class="flex gap-3 mb-6" style="background:var(--bg2);border-radius:12px;padding:14px;border:1px solid var(--border)">
          ${post.picture ? `<img src="${esc(post.picture)}" style="width:64px;height:64px;border-radius:8px;object-fit:cover;flex-shrink:0">` : '<div style="width:64px;height:64px;border-radius:8px;background:var(--bg3);flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:24px"></div>'}
          <div>
            <div class="text-sm font-bold text-muted mb-1">${esc(post.pageName)}</div>
            <div class="text-sm" style="color:var(--muted2)">${esc((post.message || 'منشور بدون نص').slice(0, 100))}${(post.message||'').length > 100 ? '...' : ''}</div>
          </div>
        </div>

        <div class="grid-2">
          <!-- LEFT -->
          <div>
            <div class="form-group">
              <label class="form-label">اسم الحملة</label>
              <input class="form-control" id="promo-name" placeholder="حملة ترويجية...">
            </div>

            <div class="form-group">
              <label class="form-label">الهدف من الحملة</label>
              <div class="obj-pills">
                <button class="obj-pill selected" data-obj="engagement" onclick="setObj('engagement',this)">
                  <span class="obj-icon"></span>زيادة التفاعل
                </button>
                <button class="obj-pill" data-obj="followers" onclick="setObj('followers',this)">
                  <span class="obj-icon"></span>متابعون
                </button>
                <button class="obj-pill" data-obj="messages" onclick="setObj('messages',this)">
                  <span class="obj-icon"></span>رسائل
                </button>
              </div>
            </div>

            <div class="form-group">
              <label class="form-label">الجنس</label>
              <div class="gender-pills">
                <button class="gender-pill selected" onclick="setGender('all',this)">الكل</button>
                <button class="gender-pill" onclick="setGender('male',this)">ذكر</button>
                <button class="gender-pill" onclick="setGender('female',this)">أنثى</button>
              </div>
            </div>

            <div class="form-group">
              <label class="form-label">الفئة العمرية</label>
              <div class="range-wrap">
                <span class="text-sm text-muted">18</span>
                <input type="range" id="age-min" min="18" max="65" value="18" oninput="q('#age-min-val').textContent=this.value">
                <span class="range-val" id="age-min-val">18</span>
                <span class="text-sm text-muted">—</span>
                <input type="range" id="age-max" min="18" max="65" value="65" oninput="q('#age-max-val').textContent=this.value">
                <span class="range-val" id="age-max-val">65</span>
                <span class="text-sm text-muted">65</span>
              </div>
            </div>

            <div class="form-group">
              <label class="form-label">الميزانية</label>
              <div class="budget-display"><span>$</span><span id="budget-display">50</span></div>
              <div class="range-wrap">
                <span class="text-sm text-muted">$10</span>
                <input type="range" id="promo-budget" min="10" max="1000" step="10" value="50"
                  oninput="promoBudget=parseInt(this.value);q('#budget-display').textContent=this.value">
                <span class="text-sm text-muted">$1000</span>
              </div>
            </div>
          </div>

          <!-- RIGHT -->
          <div>
            <div class="form-group">
              <label class="form-label">الجمهور المستهدف (المواقع)</label>
              <div class="search-wrap">
                <input class="form-control" id="loc-search" placeholder="ابحث عن منطقة أو دولة..." oninput="searchLocations(this.value)">
                <span class="search-icon"></span>
              </div>
              <div id="loc-results" style="margin-top:6px;max-height:120px;overflow-y:auto"></div>
              <div class="location-tags" id="loc-tags"></div>
            </div>
            <div id="promo-map"></div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost" onclick="closePromo()">إلغاء</button>
        <button class="btn btn-primary" id="promo-submit-btn" onclick="submitCampaign()">${IC.rocket} إرسال للمراجعة</button>
      </div>
    </div>
  </div>`);

  // Load Leaflet map
  setTimeout(() => initPromoMap(), 200);
}

function closePromo() {
  const overlay = q('#promo-overlay');
  if (overlay) overlay.remove();
  promoMap = null; promoMarkers = []; promoLocations = [];
}

function initPromoMap() {
  if (typeof L === 'undefined') return;
  promoMap = L.map('promo-map', { center: [24, 45], zoom: 4 });
  L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
    attribution: '©OpenStreetMap ©CartoDB'
  }).addTo(promoMap);
}

async function searchLocations(query) {
  const el = q('#loc-results');
  if (!query || query.length < 2) { el.innerHTML = ''; return; }

  const res = await fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}&limit=6`);
  const data = await res.json();

  el.innerHTML = data.map(item => `
    <div onclick="addLocation('${esc(item.display_name.split(',')[0])}',${item.lat},${item.lon})"
         style="padding:8px 12px;border-radius:8px;cursor:pointer;font-size:13px;transition:background .15s;border-bottom:1px solid var(--border)"
         onmouseover="this.style.background='rgba(59,130,246,.08)'" onmouseout="this.style.background=''">
      ${esc(item.display_name.slice(0, 60))}
    </div>`).join('') || '<div class="text-sm text-muted" style="padding:8px">لا نتائج</div>';
}

function addLocation(name, lat, lon) {
  if (promoLocations.find(l => l.name === name)) return;
  promoLocations.push({ name, lat: parseFloat(lat), lon: parseFloat(lon) });
  q('#loc-search').value = '';
  q('#loc-results').innerHTML = '';
  renderLocTags();

  if (promoMap) {
    const marker = L.circleMarker([lat, lon], {
      radius: 18, color: '#3b82f6', fillColor: '#3b82f6', fillOpacity: 0.35, weight: 2
    }).addTo(promoMap).bindPopup(name);
    promoMarkers.push({ name, marker });

    const bounds = L.latLngBounds(promoLocations.map(l => [l.lat, l.lon]));
    promoMap.fitBounds(bounds, { padding: [40, 40] });
  }
}

function removeLocation(name) {
  promoLocations = promoLocations.filter(l => l.name !== name);
  const m = promoMarkers.find(m => m.name === name);
  if (m && promoMap) promoMap.removeLayer(m.marker);
  promoMarkers = promoMarkers.filter(m => m.name !== name);
  renderLocTags();
}

function renderLocTags() {
  q('#loc-tags').innerHTML = promoLocations.map(l => `
    <span class="location-tag">
       ${esc(l.name)}
      <span class="location-tag-remove" onclick="removeLocation('${esc(l.name)}')"></span>
    </span>`).join('');
}

function setGender(val, el) {
  promoGender = val;
  document.querySelectorAll('.gender-pill').forEach(p => p.classList.remove('selected'));
  el.classList.add('selected');
}

function setObj(val, el) {
  promoObjective = val;
  document.querySelectorAll('.obj-pill').forEach(p => p.classList.remove('selected'));
  el.classList.add('selected');
}

async function submitCampaign() {
  const name    = (q('#promo-name')?.value || '').trim();
  const ageMin  = parseInt(q('#age-min')?.value || 18);
  const ageMax  = parseInt(q('#age-max')?.value || 65);
  const btn     = q('#promo-submit-btn');
  qInner('#promo-alert', '');

  if (!name) return qInner('#promo-alert', alert_('الرجاء إدخال اسم الحملة', 'error'));
  if (promoLocations.length === 0) return qInner('#promo-alert', alert_('الرجاء إضافة موقع واحد على الأقل', 'error'));

  setBtn(btn, true, 'جاري الإرسال...');

  const res = await API.post('user/campaigns', {
    page_id:       S.promotePost.pageId,
    post_id:       S.promotePost.id,
    post_message:  S.promotePost.message || '',
    post_picture:  S.promotePost.picture || '',
    campaign_name: name,
    objective:     promoObjective,
    gender:        promoGender,
    age_min:       ageMin,
    age_max:       ageMax,
    locations:     promoLocations,
    budget:        promoBudget,
  });

  if (res.success) {
    closePromo();
    showToast('تم إرسال الحملة للمراجعة!');
    navigate('my-campaigns');
  } else {
    qInner('#promo-alert', alert_(res.message, 'error'));
    setBtn(btn, false, 'إرسال للمراجعة');
  }
}

//  User: My Campaigns 
async function renderMyCampaigns() {
  setMain(`
    <div class="page-header animate-fade-up">
      <div class="page-title">حملاتي الإعلانية</div>
      <div class="page-sub">تتبع حالة جميع حملاتك</div>
    </div>
    <div id="my-camps-list"><div class="loading-center"><div class="spinner spinner-blue"></div></div></div>`);

  const res = await API.get('user/campaigns');
  const camps = res.campaigns || [];
  const el = q('#my-camps-list');

  if (!camps.length) { el.innerHTML = emptyState('rocket', 'لا توجد حملات', 'اضغط "ترويج" على أي منشور لإنشاء حملة'); return; }

  el.innerHTML = camps.map((c, i) => {
    const isIg = (c.page_id || '').startsWith('ig_');
    const platformBadge = isIg
      ? `<span style="font-size:10px;padding:2px 7px;border-radius:10px;background:linear-gradient(135deg,#f09433,#dc2743);color:#fff;font-weight:700">📷 إنستاغرام</span>`
      : `<span style="font-size:10px;padding:2px 7px;border-radius:10px;background:#1877F2;color:#fff;font-weight:700">f فيسبوك</span>`;
    return `
    <div class="campaign-card mb-4 animate-fade-up" style="animation-delay:${i*.05}s">
      <div class="campaign-header">
        ${c.post_picture ? `<img src="${esc(c.post_picture)}" class="campaign-thumb">` : `<div class="campaign-thumb" style="background:var(--bg3);display:flex;align-items:center;justify-content:center;font-size:22px"></div>`}
        <div style="flex:1">
          <div class="font-bold" style="font-size:15px">${esc(c.campaign_name)}</div>
          <div class="text-sm text-muted mt-1" style="display:flex;align-items:center;gap:6px">${esc(c.page_name)} ${platformBadge}</div>
          <div class="campaign-meta">
            ${statusBadge(c.status)}
            <span class="badge badge-blue">$${Number(c.budget).toFixed(2)}</span>
            <span class="badge badge-gray">${objLabel(c.objective)}</span>
          </div>
        </div>
        <div class="text-sm text-muted">${fmtDate(c.created_at)}</div>
      </div>
      ${c.admin_note ? `<div class="campaign-body"><div class="alert alert-info" style="margin:0">ملاحظة الأدمن: ${esc(c.admin_note)}</div></div>` : ''}
    </div>`;
  }).join('');
}

//  User: Wallet 
async function renderWallet() {
  setMain(`
    <div class="page-header animate-fade-up">
      <div class="page-title">محفظتي</div>
      <div class="page-sub">إدارة رصيدك وطلبات الشحن</div>
    </div>
    <div id="wallet-wrap"><div class="loading-center"><div class="spinner spinner-blue"></div></div></div>`);

  const res = await API.get('user/wallet');
  if (!res.success) { q('#wallet-wrap').innerHTML = `<div class="alert alert-error">${esc(res.message)}</div>`; return; }

  const { balance, methods, deposits } = res;

  q('#wallet-wrap').innerHTML = `
  <!-- Balance hero -->
  <div class="wallet-hero animate-fade-up">
    <div class="wallet-label">رصيدك الحالي</div>
    <div class="wallet-amount"><span class="wallet-currency">$</span>${Number(balance).toFixed(2)}</div>
    <p style="color:rgba(255,255,255,.6);font-size:13px;margin-top:8px">يمكنك استخدام هذا الرصيد لإطلاق الحملات الإعلانية</p>
  </div>

  <!-- Deposit form -->
  <div class="card mb-6 animate-fade-up" style="animation-delay:.1s">
    <div class="card-header"><div class="card-title">شحن الرصيد</div></div>
    <div class="card-body">
      <div id="dep-alert"></div>
      ${!methods.length ? '<div class="alert alert-warning"> لا توجد طرق دفع متاحة حالياً. تواصل مع الإدارة.</div>' : `
      <div class="grid-2">
        <div>
          <div class="form-group">
            <label class="form-label">طريقة الدفع</label>
            <select class="form-control" id="dep-method" onchange="showPayDetails(this.value)">
              <option value="">-- اختر طريقة --</option>
              ${methods.map(m => `<option value="${m.id}">${esc(m.name)}</option>`).join('')}
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">المبلغ (دولار)</label>
            <input class="form-control" id="dep-amount" type="number" min="10" step="1" placeholder="أدخل المبلغ...">
          </div>
          <div class="form-group">
            <label class="form-label">صورة إثبات التحويل</label>
            <label class="file-drop" id="dep-drop">
              <input type="file" id="dep-receipt" accept="image/*,.pdf">
              <div id="dep-drop-txt">
                <div style="margin-bottom:8px">${IC.upload}</div>
                <div>اسحب صورة الإيصال هنا أو اضغط للاختيار</div>
                <div class="text-sm text-muted mt-1">JPG, PNG, PDF — حتى 5MB</div>
              </div>
            </label>
          </div>
          <button class="btn btn-primary" id="dep-btn" onclick="submitDeposit()">${IC.upload} إرسال الطلب</button>
        </div>
        <div>
          <div id="pay-details-box">
            <div class="text-muted text-sm" style="padding:20px;text-align:center">اختر طريقة دفع لعرض التعليمات</div>
          </div>
        </div>
      </div>`}
    </div>
  </div>

  <!-- History -->
  <div class="card animate-fade-up" style="animation-delay:.15s">
    <div class="card-header"><div class="card-title" style="display:inline-flex;align-items:center;gap:8px">${IC.history} سجل الشحن</div></div>
    ${!deposits.length ? `<div class="card-body">${emptyState('inbox', 'لا توجد طلبات بعد', '')}</div>` :
    `<div class="table-wrap"><table>
      <thead><tr><th>#</th><th>الطريقة</th><th>المبلغ</th><th>إثبات</th><th>الحالة</th><th>التاريخ</th></tr></thead>
      <tbody>${deposits.map((d, i) => `<tr>
        <td>${i+1}</td>
        <td>${esc(d.method_name)}</td>
        <td><strong style="color:var(--green)">$${Number(d.amount).toFixed(2)}</strong></td>
        <td>${d.receipt_image ? `<a href="${esc(d.receipt_image)}" target="_blank" class="btn btn-ghost btn-xs">${IC.paperclip} إيصال</a>` : '—'}</td>
        <td>${statusBadge(d.status)}</td>
        <td class="text-sm text-muted">${fmtDate(d.created_at)}</td>
      </tr>`).join('')}</tbody>
    </table></div>`}
  </div>`;

  // Setup file drop
  const inp = q('#dep-receipt');
  if (inp) {
    inp.addEventListener('change', () => {
      const f = inp.files[0];
      if (f) q('#dep-drop-txt').innerHTML = `${esc(f.name)}`;
    });
    const drop = q('#dep-drop');
    if (drop) {
      drop.addEventListener('dragover', e => { e.preventDefault(); drop.classList.add('drag-over'); });
      drop.addEventListener('dragleave', () => drop.classList.remove('drag-over'));
      drop.addEventListener('drop', e => { e.preventDefault(); drop.classList.remove('drag-over'); inp.files = e.dataTransfer.files; q('#dep-drop-txt').innerHTML = `${IC.check} ${esc(inp.files[0]?.name || '')}`; });
    }
  }

  // Store methods for lookup
  window._payMethods = methods;
}

function showPayDetails(methodId) {
  const el = q('#pay-details-box');
  if (!el) return;
  const m = (window._payMethods || []).find(m => m.id == methodId);
  if (!m) { el.innerHTML = ''; return; }
  el.innerHTML = `
    <div class="pm-card">
      <div class="font-bold mb-2"> ${esc(m.name)}</div>
      ${m.description ? `<p class="text-sm text-muted mb-3">${esc(m.description)}</p>` : ''}
      <label class="form-label">عنوان التحويل:</label>
      <div class="pm-address">${esc(m.address)}</div>
    </div>
    <div class="alert alert-info mt-3" style="font-size:12px;display:flex;gap:8px;align-items:flex-start">
      بعد إتمام التحويل، ارفع صورة الإيصال وأرسل الطلب. سيتم مراجعته خلال 24 ساعة.
    </div>`;
}

async function submitDeposit() {
  const methodId = q('#dep-method')?.value;
  const amount   = parseFloat(q('#dep-amount')?.value || 0);
  const receipt  = q('#dep-receipt')?.files[0];
  const btn      = q('#dep-btn');
  qInner('#dep-alert', '');

  if (!methodId) return qInner('#dep-alert', alert_('اختر طريقة دفع', 'error'));
  if (!amount || amount < 10) return qInner('#dep-alert', alert_('الحد الأدنى للشحن $10', 'error'));

  const fd = new FormData();
  fd.append('method_id', methodId);
  fd.append('amount', amount);
  if (receipt) fd.append('receipt', receipt);

  setBtn(btn, true, 'جاري الإرسال...');
  const res = await API.form('user/deposit', fd);
  if (res.success) {
    showToast('تم إرسال طلب الشحن!');
    renderWallet();
  } else {
    qInner('#dep-alert', alert_(res.message, 'error'));
    setBtn(btn, false, 'إرسال الطلب');
  }
}

//  Admin: Site Settings 
async function renderSiteSettings() {
  setMain(`
    <div class="page-header animate-fade-up">
      <div class="page-title">إعدادات الموقع</div>
      <div class="page-sub">تخصيص اسم الموقع والشعار</div>
    </div>
    <div class="card animate-fade-up" style="max-width:560px">
      <div class="card-header"><div class="card-title">الهوية البصرية</div></div>
      <div class="card-body">
        <div id="ss-alert"></div>
        <div id="current-logo-wrap" class="mb-4"></div>
        <div class="form-group">
          <label class="form-label">اسم الموقع</label>
          <input class="form-control" id="ss-name" type="text" placeholder="FB Manager">
        </div>
        <div class="form-group">
          <label class="form-label">شعار الموقع (اختياري)</label>
          <label class="file-drop" id="ss-drop">
            <input type="file" id="ss-logo" accept="image/*">
            <div id="ss-drop-txt">
              <div style="margin-bottom:8px">${IC.image}</div>
              <div>اسحب الشعار هنا أو اضغط للاختيار</div>
              <div class="text-sm text-muted mt-1">PNG, JPG, SVG, WebP — حتى 2MB</div>
            </div>
          </label>
          <div class="flex gap-2 mt-2">
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;color:var(--muted)">
              <input type="checkbox" id="ss-clear-logo"> حذف الشعار الحالي
            </label>
          </div>
        </div>
        <button class="btn btn-primary" id="ss-save-btn" onclick="saveSiteSettings()">${IC.save} حفظ الإعدادات</button>
      </div>
    </div>`);

  const res = await API.get('admin/site-settings');
  if (res.success) {
    const s = res.settings;
    if (s.site_name) q('#ss-name').value = s.site_name;
    if (s.site_logo) {
      q('#current-logo-wrap').innerHTML = `
        <div class="flex items-center gap-3 mb-2" style="background:var(--bg2);padding:12px;border-radius:10px;border:1px solid var(--border)">
          <img src="${esc(s.site_logo)}" style="width:56px;height:56px;border-radius:10px;object-fit:cover">
          <div>
            <div class="font-bold text-sm">الشعار الحالي</div>
            <div class="text-sm text-muted">${esc(s.site_logo)}</div>
          </div>
        </div>`;
    }
  }

  const inp = q('#ss-logo');
  if (inp) {
    inp.addEventListener('change', () => {
      const f = inp.files[0];
      if (f) q('#ss-drop-txt').innerHTML = `${IC.check} ${esc(f.name)}`;
    });
  }
}

async function saveSiteSettings() {
  const name = (q('#ss-name')?.value || '').trim();
  const btn  = q('#ss-save-btn');
  qInner('#ss-alert', '');
  if (!name) return qInner('#ss-alert', alert_('اسم الموقع مطلوب', 'error'));

  const fd = new FormData();
  fd.append('site_name', name);
  const logo = q('#ss-logo')?.files[0];
  if (logo) fd.append('logo', logo);
  if (q('#ss-clear-logo')?.checked) fd.append('clear_logo', '1');

  setBtn(btn, true, 'جاري الحفظ...');
  const res = await API.form('admin/site-settings', fd);
  if (res.success) {
    // Update in-memory settings and sidebar
    S.siteSettings.site_name = name;
    if (res.site_logo) S.siteSettings.site_logo = res.site_logo;
    // Refresh sidebar brand name
    const brand = document.querySelector('.sidebar-brand');
    if (brand) brand.textContent = name;
    const logoEl = document.querySelector('.sidebar-header img');
    if (logoEl && res.site_logo) logoEl.src = res.site_logo;
    showToast('تم حفظ الإعدادات بنجاح');
    renderSiteSettings();
  } else {
    qInner('#ss-alert', alert_(res.message, 'error'));
  }
  setBtn(btn, false, 'حفظ الإعدادات');
}

//  Admin: Link Requests 
async function renderLinkRequests() {
  setMain(`
    <div class="page-header animate-fade-up">
      <div class="page-title">طلبات ربط الصفحات</div>
      <div class="page-sub">طلبات المستخدمين الجدد لربط صفحاتهم</div>
    </div>
    <div class="tabs animate-fade-up">
      <button class="tab active" onclick="loadLinkReqs('pending',this)">معلقة</button>
      <button class="tab" onclick="loadLinkReqs('approved',this)">موافق عليها</button>
      <button class="tab" onclick="loadLinkReqs('rejected',this)">مرفوضة</button>
      <button class="tab" onclick="loadLinkReqs('',this)">الكل</button>
    </div>
    <div id="link-reqs-list"><div class="loading-center"><div class="spinner spinner-blue"></div></div></div>`);
  loadLinkReqs('pending');
  showBadge('link-requests', 0);
}

async function loadLinkReqs(status, tabEl) {
  if (tabEl) { document.querySelectorAll('.tab').forEach(t => t.classList.remove('active')); tabEl.classList.add('active'); }
  const el = q('#link-reqs-list');
  el.innerHTML = '<div class="loading-center"><div class="spinner spinner-blue"></div></div>';
  const res = await API.get('admin/link-requests' + (status ? '?status=' + status : ''));
  const reqs = res.requests || [];
  if (!reqs.length) { el.innerHTML = emptyState('inbox', 'لا توجد طلبات'); return; }
  el.innerHTML = `<div class="card animate-fade-up"><div class="table-wrap"><table>
    <thead><tr><th>#</th><th>المستخدم</th><th>رقم الهاتف</th><th>المنصة</th><th>رابط الصفحة</th><th>واتساب</th><th>الحالة</th><th>التاريخ</th><th>إجراء</th></tr></thead>
    <tbody>${reqs.map((r, i) => `<tr>
      <td>${i+1}</td>
      <td><strong>${esc(r.user_name)}</strong></td>
      <td dir="ltr">${esc(r.phone)}</td>
      <td>${r.platform === 'instagram'
        ? `<span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;padding:3px 8px;border-radius:20px;background:linear-gradient(135deg,#f09433,#dc2743);color:#fff;font-weight:700">📷 إنستاغرام</span>`
        : `<span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;padding:3px 8px;border-radius:20px;background:#1877F2;color:#fff;font-weight:700">f فيسبوك</span>`
      }</td>
      <td><a href="${esc(r.page_url)}" target="_blank" class="btn btn-ghost btn-xs" style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${IC.externalLink} عرض الصفحة</a></td>
      <td dir="ltr"><a href="https://wa.me/${esc(r.whatsapp.replace(/\D/g,''))}" target="_blank" style="color:var(--green);font-weight:700">${esc(r.whatsapp)}</a></td>
      <td>${statusBadge(r.status)}</td>
      <td class="text-sm text-muted">${fmtDate(r.created_at)}</td>
      <td>
        ${r.status === 'pending' ? `
          <button class="btn btn-success btn-xs" onclick="updateLinkReq(${r.id},'approved')">${IC.check}</button>
          <button class="btn btn-danger btn-xs" onclick="updateLinkReq(${r.id},'rejected')">${IC.x}</button>
        ` : '—'}
      </td>
    </tr>`).join('')}</tbody>
  </table></div></div>`;
}

async function updateLinkReq(id, status) {
  let note = '';
  if (status === 'rejected') note = prompt('سبب الرفض (اختياري):') || '';
  const res = await API.post('admin/link-requests/update', { id, status, note });
  if (res.success) { showToast('تم التحديث'); loadLinkReqs(''); loadPendingBadges(); }
  else showToast('' + res.message, 'error');
}

// 
//  HELPERS
// 

function q(sel) { return document.querySelector(sel); }
function qInner(sel, html) { const el = q(sel); if (el) el.innerHTML = html; }
function setMain(html) { const m = q('#main'); if (m) { m.innerHTML = html; } }

function esc(str) {
  if (!str) return '';
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}

function fmtDate(d) {
  if (!d) return '';
  try { return new Date(d).toLocaleString('ar-SA', { year:'numeric', month:'short', day:'numeric', hour:'2-digit', minute:'2-digit' }); }
  catch { return d; }
}

function alert_(msg, type) { return `<div class="alert alert-${type}"> ${esc(msg)}</div>`; }
function setAlert(msg, type) { const el = q('#a-alert'); if (el) el.innerHTML = msg ? alert_(msg, type) : ''; }

function setBtn(btn, loading, txt) {
  if (!btn) return;
  btn.disabled = loading;
  btn.innerHTML = loading ? `<span class="spinner"></span> ${txt || '...'}` : (txt || btn.textContent);
}

function statCard(iconKey, num, label, color) {
  const svg = IC[iconKey] || '';
  return `<div class="stat-card ${color}">
    <div class="stat-icon ${color}">${svg}</div>
    <div class="stat-num">${num}</div>
    <div class="stat-lbl">${label}</div>
  </div>`;
}

function statusBadge(status) {
  const map = {
    pending:   ['badge-amber',  'معلق'],
    approved:  ['badge-green',  'موافق'],
    rejected:  ['badge-red',    'مرفوض'],
    running:   ['badge-blue',   'يعمل'],
    paused:    ['badge-gray',   'متوقف'],
    completed: ['badge-purple', 'مكتمل'],
  };
  const [cls, label] = map[status] || ['badge-gray', status];
  return `<span class="badge ${cls}">${label}</span>`;
}

function objLabel(obj) {
  return { engagement: 'تفاعل', followers: 'متابعون', messages: 'رسائل' }[obj] || obj;
}

function genderLabel(g) {
  return { all: 'الكل', male: 'ذكر', female: 'أنثى' }[g] || g;
}

function emptyState(iconKey, title, sub = '') {
  const svgIcon = IC[iconKey] || `<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/></svg>`;
  return `<div class="empty-state"><span class="empty-icon">${svgIcon}</span><h3>${title}</h3>${sub ? `<p>${sub}</p>` : ''}</div>`;
}

//  Toast 
function showToast(msg, type = 'success') {
  const toast = document.createElement('div');
  toast.style.cssText = `position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:${type==='error'?'var(--red)':'var(--green)'};color:white;padding:12px 22px;border-radius:10px;font-size:14px;font-weight:700;z-index:9999;box-shadow:0 8px 24px rgba(0,0,0,.3);animation:fadeUp .3s ease`;
  toast.textContent = msg;
  document.body.appendChild(toast);
  setTimeout(() => toast.remove(), 3000);
}

// ─── User: Payment History ────────────────────────────────────────────────────
async function renderPaymentHistory() {
  setMain(`
    <div class="page-header animate-fade-up">
      <div class="page-title" style="display:flex;align-items:center;gap:10px">${IC.history} سجل المدفوعات</div>
      <div class="page-sub">جميع عمليات الشحن والحملات والاسترداد</div>
    </div>
    <div id="ph-wrap"><div class="loading-center"><div class="spinner spinner-blue"></div></div></div>`);

  const res = await API.get('user/payment-history');
  if (!res.success) {
    q('#ph-wrap').innerHTML = `<div class="alert alert-error">${esc(res.message)}</div>`;
    return;
  }

  const { deposits, campaigns } = res;

  // Build unified timeline sorted by date descending
  const timeline = [
    ...deposits.map(d => ({
      type: 'deposit',
      date: d.created_at,
      amount: +d.amount,
      status: d.status,
      label: `شحن رصيد — ${esc(d.method_name)}`,
      note: d.admin_note || '',
      receipt: d.receipt_image || null,
    })),
    ...campaigns.map(c => ({
      type: 'campaign',
      date: c.created_at,
      amount: +c.budget,
      status: c.status,
      label: `حملة: ${esc(c.campaign_name)}`,
      note: c.admin_note || '',
      refunded: c.status === 'rejected',
    })),
  ].sort((a, b) => new Date(b.date) - new Date(a.date));

  // Summary cards
  const totalDeposited  = deposits.filter(d => d.status === 'approved').reduce((s, d) => s + +d.amount, 0);
  const totalSpent      = campaigns.filter(c => c.status !== 'rejected' && c.status !== 'pending').reduce((s, c) => s + +c.budget, 0);
  const totalRefunded   = campaigns.filter(c => c.status === 'rejected').reduce((s, c) => s + +c.budget, 0);
  const pendingDeposits = deposits.filter(d => d.status === 'pending').reduce((s, d) => s + +d.amount, 0);

  q('#ph-wrap').innerHTML = `
  <!-- Summary -->
  <div class="stats-grid animate-fade-up" style="grid-template-columns:repeat(auto-fill,minmax(200px,1fr));margin-bottom:24px">
    <div class="stat-card green">
      <div class="stat-icon green">${IC.dollar}</div>
      <div class="stat-num">$${totalDeposited.toFixed(2)}</div>
      <div class="stat-lbl">إجمالي الشحن المعتمد</div>
    </div>
    <div class="stat-card blue">
      <div class="stat-icon blue">${IC.rocket}</div>
      <div class="stat-num">$${totalSpent.toFixed(2)}</div>
      <div class="stat-lbl">إجمالي الإنفاق على الحملات</div>
    </div>
    <div class="stat-card purple">
      <div class="stat-icon purple">${IC.history}</div>
      <div class="stat-num">$${totalRefunded.toFixed(2)}</div>
      <div class="stat-lbl">إجمالي المبالغ المستردة</div>
    </div>
    <div class="stat-card amber">
      <div class="stat-icon amber">${IC.clock}</div>
      <div class="stat-num">$${pendingDeposits.toFixed(2)}</div>
      <div class="stat-lbl">شحن قيد المراجعة</div>
    </div>
  </div>

  <!-- Tabs -->
  <div class="tabs animate-fade-up" style="margin-bottom:16px">
    <button class="tab active" onclick="phFilter('all',this)">الكل</button>
    <button class="tab" onclick="phFilter('deposit',this)">الشحن</button>
    <button class="tab" onclick="phFilter('campaign',this)">الحملات</button>
    <button class="tab" onclick="phFilter('refund',this)">المستردة</button>
  </div>

  <!-- Timeline -->
  <div id="ph-timeline" class="animate-fade-up">
    ${timeline.length ? renderPhTimeline(timeline) : `<div class="card"><div class="card-body">${emptyState('inbox', 'لا توجد سجلات بعد', 'ستظهر هنا جميع عمليات الشحن والحملات')}</div></div>`}
  </div>`;

  window._phTimeline = timeline;
}

function phFilter(type, tabEl) {
  document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  tabEl.classList.add('active');
  const tl = window._phTimeline || [];
  const filtered = type === 'all'     ? tl
                 : type === 'refund'  ? tl.filter(e => e.type === 'campaign' && e.refunded)
                 : tl.filter(e => e.type === type);
  q('#ph-timeline').innerHTML = filtered.length
    ? renderPhTimeline(filtered)
    : `<div class="card"><div class="card-body">${emptyState('inbox', 'لا توجد سجلات')}</div></div>`;
}

function renderPhTimeline(items) {
  return `<div class="card"><div class="table-wrap"><table>
    <thead><tr>
      <th>النوع</th><th>التفاصيل</th><th>المبلغ</th><th>الحالة</th><th>ملاحظة</th><th>التاريخ</th>
    </tr></thead>
    <tbody>
      ${items.map(item => {
        const isDeposit  = item.type === 'deposit';
        const isRefunded = item.refunded;
        const amountColor = isDeposit ? 'var(--green)' : isRefunded ? 'var(--blue)' : 'var(--red)';
        const amountSign  = isDeposit ? '+' : isRefunded ? '↩ ' : '−';
        const typeLabel   = isDeposit
          ? `<span class="badge badge-green" style="display:inline-flex;align-items:center;gap:4px">${IC.card} شحن</span>`
          : isRefunded
            ? `<span class="badge badge-blue" style="display:inline-flex;align-items:center;gap:4px">${IC.history} استرداد</span>`
            : `<span class="badge badge-purple" style="display:inline-flex;align-items:center;gap:4px">${IC.rocket} حملة</span>`;

        return `<tr>
          <td>${typeLabel}</td>
          <td>
            <div style="font-weight:600;font-size:13px">${item.label}</div>
            ${item.receipt ? `<a href="${esc(item.receipt)}" target="_blank" class="btn btn-ghost btn-xs" style="margin-top:4px;display:inline-flex;align-items:center;gap:4px">${IC.paperclip} إيصال</a>` : ''}
          </td>
          <td><strong style="color:${amountColor};font-size:15px">${amountSign}$${item.amount.toFixed(2)}</strong></td>
          <td>${statusBadge(item.status)}</td>
          <td class="text-sm text-muted">${item.note ? esc(item.note) : '—'}</td>
          <td class="text-sm text-muted" style="white-space:nowrap">${fmtDate(item.date)}</td>
        </tr>`;
      }).join('')}
    </tbody>
  </table></div></div>`;
}

// ─── WhatsApp Broadcast Panel ─────────────────────────────────────────────────
async function renderWhatsappSend() {
  setMain(`
    <div class="page-header animate-fade-up">
      <div class="page-title">إرسال رسائل واتساب</div>
      <div class="page-sub">أرسل رسائل فردية أو جماعية للمستخدمين عبر واتساب</div>
    </div>

    <div class="grid-2 animate-fade-up" style="gap:20px;margin-bottom:24px">

      <!-- إرسال لمستخدم واحد -->
      <div class="card" style="padding:24px">
        <h3 style="margin:0 0 16px;font-size:16px;display:flex;align-items:center;gap:8px">
          <span style="font-size:22px">👤</span> إرسال لمستخدم محدد
        </h3>
        <div class="form-group">
          <label class="form-label">اختر المستخدم</label>
          <select class="form-control" id="wa-user-id">
            <option value="">— جاري التحميل —</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">الرسالة</label>
          <textarea class="form-control" id="wa-one-msg" rows="4" placeholder="اكتب الرسالة هنا..." style="resize:vertical"></textarea>
        </div>
        <div id="wa-one-alert"></div>
        <button class="btn btn-primary w-full" id="wa-one-btn" onclick="waSendOne()">
          إرسال الرسالة
        </button>
      </div>

      <!-- بث جماعي -->
      <div class="card" style="padding:24px">
        <h3 style="margin:0 0 16px;font-size:16px;display:flex;align-items:center;gap:8px">
          <span style="font-size:22px">📢</span> بث جماعي لجميع المستخدمين
        </h3>
        <div class="form-group">
          <label class="form-label">الرسالة</label>
          <textarea class="form-control" id="wa-all-msg" rows="4" placeholder="اكتب الرسالة هنا..." style="resize:vertical"></textarea>
        </div>
        <div class="form-group">
          <label class="form-label">
            التأخير بين كل رسالة
            <span class="badge" style="background:var(--surface2);color:var(--muted);font-weight:400;margin-right:6px">للحماية من الحظر</span>
          </label>
          <div style="display:flex;align-items:center;gap:10px">
            <input type="range" id="wa-delay" min="3" max="30" value="5" step="1"
                   oninput="document.getElementById('wa-delay-val').textContent=this.value"
                   style="flex:1;accent-color:var(--green)">
            <span style="min-width:40px;text-align:center;font-weight:700;color:var(--green)">
              <span id="wa-delay-val">5</span>ث
            </span>
          </div>
        </div>
        <div id="wa-all-alert"></div>
        <button class="btn btn-success w-full" id="wa-all-btn" onclick="waBroadcast()"
                style="background:var(--green);border-color:var(--green)">
          إرسال للجميع
        </button>
      </div>
    </div>

    <!-- سجل الرسائل -->
    <div class="card animate-fade-up" style="padding:24px">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
        <h3 style="margin:0;font-size:16px">📋 سجل الرسائل المرسلة</h3>
        <button class="btn btn-ghost btn-sm" onclick="loadWaLogs()">تحديث</button>
      </div>
      <div id="wa-logs-table"><div class="spinner"></div></div>
    </div>`);

  // Load users
  const usersRes = await API.get('admin/users');
  const sel = document.getElementById('wa-user-id');
  if (sel && usersRes.success) {
    sel.innerHTML = '<option value="">— اختر مستخدماً —</option>' +
      usersRes.users.filter(u => u.role === 'user').map(u =>
        `<option value="${u.id}">${esc(u.name)} (${esc(u.phone)})</option>`
      ).join('');
  }

  loadWaLogs();
}

async function loadWaLogs() {
  const el = document.getElementById('wa-logs-table');
  if (!el) return;
  el.innerHTML = '<div class="spinner"></div>';
  const res = await API.get('admin/whatsapp/logs');
  if (!res.success || !res.logs.length) {
    el.innerHTML = '<p class="text-center text-muted" style="padding:20px">لا توجد رسائل مرسلة بعد</p>';
    return;
  }
  el.innerHTML = `
    <div class="table-wrap">
      <table class="table">
        <thead><tr>
          <th>النوع</th><th>المستلم</th><th>الرسالة</th>
          <th>الحالة</th><th>مرسل/فشل</th><th>التاريخ</th>
        </tr></thead>
        <tbody>
          ${res.logs.map(l => `
            <tr>
              <td>${l.target_type === 'all'
                ? '<span class="badge" style="background:#f59e0b22;color:#f59e0b">جماعي</span>'
                : '<span class="badge" style="background:#3b82f622;color:#3b82f6">فردي</span>'}</td>
              <td>${l.target_type === 'all'
                ? `<span class="text-muted">جميع المستخدمين (${l.total_users})</span>`
                : `${esc(l.user_name || '—')}<br><small class="text-muted" dir="ltr">${esc(l.user_phone || '')}</small>`}</td>
              <td style="max-width:220px;white-space:pre-wrap;font-size:13px">${esc(l.message.substring(0,120))}${l.message.length > 120 ? '…' : ''}</td>
              <td>${l.status === 'sent'
                ? '<span class="badge badge-success">مرسل</span>'
                : l.status === 'queued'
                  ? '<span class="badge" style="background:#f59e0b22;color:#f59e0b">قيد الإرسال</span>'
                  : '<span class="badge badge-danger">فشل</span>'}</td>
              <td style="text-align:center">
                ${l.target_type === 'all'
                  ? `<span style="color:var(--green)">✓${l.sent_count}</span> / <span style="color:var(--red)">✗${l.failed_count}</span>`
                  : '<span style="color:var(--muted)">—</span>'}
              </td>
              <td class="text-muted text-sm">${esc(l.created_at?.substring(0,16) || '')}</td>
            </tr>`).join('')}
        </tbody>
      </table>
    </div>`;
}

async function waSendOne() {
  const userId = document.getElementById('wa-user-id')?.value;
  const msg    = document.getElementById('wa-one-msg')?.value?.trim();
  const btn    = document.getElementById('wa-one-btn');
  const alertEl = document.getElementById('wa-one-alert');

  if (alertEl) alertEl.innerHTML = '';
  if (!userId) { if(alertEl) alertEl.innerHTML = alert_('يرجى اختيار مستخدم', 'error'); return; }
  if (!msg)    { if(alertEl) alertEl.innerHTML = alert_('يرجى كتابة الرسالة', 'error'); return; }

  setBtn(btn, true, 'جاري الإرسال...');
  const res = await API.post('admin/whatsapp/send-one', { user_id: parseInt(userId), message: msg });
  setBtn(btn, false, 'إرسال الرسالة');
  if (res.success) {
    if(alertEl) alertEl.innerHTML = alert_('تم الإرسال بنجاح ✓', 'success');
    document.getElementById('wa-one-msg').value = '';
    setTimeout(() => loadWaLogs(), 1000);
  } else {
    if(alertEl) alertEl.innerHTML = alert_(res.message, 'error');
  }
}

async function waBroadcast() {
  const msg   = document.getElementById('wa-all-msg')?.value?.trim();
  const delay = parseInt(document.getElementById('wa-delay')?.value || '5');
  const btn   = document.getElementById('wa-all-btn');
  const alertEl = document.getElementById('wa-all-alert');

  if (alertEl) alertEl.innerHTML = '';
  if (!msg) { if(alertEl) alertEl.innerHTML = alert_('يرجى كتابة الرسالة', 'error'); return; }

  if (!confirm(`سيتم الإرسال لجميع المستخدمين بفاصل ${delay} ثانية بين كل رسالة.\nهل أنت متأكد؟`)) return;

  setBtn(btn, true, 'جاري الإرسال... (قد يستغرق وقتاً)');
  if(alertEl) alertEl.innerHTML = alert_('⏳ جاري الإرسال، يرجى الانتظار...', 'info');

  const res = await API.post('admin/whatsapp/broadcast', { message: msg, delay_seconds: delay });
  setBtn(btn, false, 'إرسال للجميع');
  if (res.success) {
    if(alertEl) alertEl.innerHTML = alert_(
      `✅ اكتمل الإرسال: ${res.sent} نجح · ${res.failed} فشل من أصل ${res.total}`, 'success'
    );
    document.getElementById('wa-all-msg').value = '';
    setTimeout(() => loadWaLogs(), 1000);
  } else {
    if(alertEl) alertEl.innerHTML = alert_(res.message, 'error');
  }
}

//  Start 
document.addEventListener('DOMContentLoaded', init);
// ─── Theme & Sidebar ───────────────────────────────────────────
function toggleTheme() {
  const root = document.documentElement;
  const next = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
  root.setAttribute('data-theme', next);
  localStorage.setItem('theme', next);
  // حدّث أيقونة كل أزرار الثيم
  document.querySelectorAll('.theme-toggle').forEach(btn => {
    btn.textContent = next === 'light' ? '🌙' : '☀️';
  });
}

function openSidebar() {
  document.getElementById('sidebar')?.classList.add('open');
  document.getElementById('sidebar-overlay')?.classList.add('active');
}

function closeSidebar() {
  document.getElementById('sidebar')?.classList.remove('open');
  document.getElementById('sidebar-overlay')?.classList.remove('active');
}

// حمّل الثيم عند أول تشغيل
(function initTheme() {
  const saved = localStorage.getItem('theme');
  const preferred = window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', saved || preferred);
})();
