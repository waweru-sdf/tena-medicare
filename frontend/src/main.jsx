import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { Activity, AlertCircle, ArrowRight, Bell, CalendarDays, ChevronDown, CircleCheck, FileText, HeartPulse, HelpCircle, Home, LogOut, Menu, MessageCircle, RefreshCw, Search, ShieldCheck, Sparkles, Stethoscope, UserRound, Users, Wallet, X, Clock3, Pill, ClipboardList } from 'lucide-react';
import './styles.css';
import './derm.css';

import { request, money, dateTime, initials, clearSession, saveSession, getToken, store, slug, useHash } from './lib.js';
import { Avatar, Stat, statusClass, label as fmtLabel, TierChip } from './ui.jsx';
import { ChatLauncher, SupportDesk, TicketQueue, ClinicianReview, FinancePanel, SettingsPanel } from './Extra.jsx';
import Landing from './Landing.jsx';
import Messages from './Messages.jsx';
import { FindDoctor, DoctorProfilePage, Appointments, MyDoctors, Records, HealthProfile, SkinAssistant, PatientOverview } from './Patient.jsx';
import { ProfileEditor, Availability, VisitDetail, MyPatients, DoctorOverview } from './Doctor.jsx';
import { ClinicalNotes, Copilot } from './Clinical.jsx';

const NAV = {
  patient: [['Overview', Home], ['Find a doctor', Search], ['Appointments', CalendarDays], ['Messages', MessageCircle], ['My doctors', Stethoscope], ['My records', FileText], ['My skin profile', ClipboardList], ['Skin assistant', Sparkles], ['Support', HelpCircle]],
  doctor: [['Overview', Home], ['My profile', UserRound], ['Availability', Clock3], ['Appointments', CalendarDays], ['My patients', Users], ['Messages', MessageCircle], ['Clinical notes', FileText], ['Assistant', Sparkles], ['Support', HelpCircle]],
  doctorLimited: [['Overview', Home], ['My profile', UserRound], ['Support', HelpCircle]],
  admin: [['Overview', Home], ['Doctors', Stethoscope], ['Appointments', CalendarDays], ['Reports', Activity], ['Support', HelpCircle], ['Settings', ShieldCheck]],
  finance: [['Overview', Home], ['Reports', Activity]],
  support: [['Support', HelpCircle]],
};
const TITLES = {
  doctor: ['Doctor profile', ''],
  'find-a-doctor': ['Find your skin doctor', 'Approved dermatologists, with profiles, fees and open times.'], appointments: ['Appointments', 'Visits, payments and video rooms.'], messages: ['Messages', 'Chat and photos with your doctors.'],
  'my-doctors': ['My doctors', 'Your care team.'], 'my-records': ['My records', 'Prescriptions and visit summaries.'], 'my-skin-profile': ['My skin profile', 'Saved once, shared with the doctors you book.'], 'skin-assistant': ['Skin assistant', 'General tips and doctor matching.'],
  'my-profile': ['My profile', 'What patients see, and your approval status.'], availability: ['Availability', 'Publish the times patients can book.'], 'my-patients': ['My patients', 'Everyone you are caring for.'], 'clinical-notes': ['Clinical notes', 'Document a visit and sign it.'], assistant: ['Doctor’s assistant', 'Drafts that you review.'],
  support: ['Support', 'Help with your account, booking or payment.'], doctors: ['Doctors', 'Review profiles and approve who patients can see.'], reports: ['Reports', 'Service performance and finance.'], settings: ['Settings', 'Chat window, AI, policy and staff.'],
};

function App() {
  const [user, setUser] = useState(() => (getToken() ? store.get('tena_user') : null));
  const [hash, go] = useHash();
  const [mobileNav, setMobileNav] = useState(false);
  const [notice, setNotice] = useState(null);
  const [busy, setBusy] = useState(false);
  const [appointments, setAppointments] = useState([]); const [records, setRecords] = useState([]); const [doctors, setDoctors] = useState(null); const [reports, setReports] = useState(null);
  const [unread, setUnread] = useState(0); const [pendingDoctors, setPendingDoctors] = useState(0);
  const [authMode, setAuthMode] = useState('login'); const [showAuth, setShowAuth] = useState(false);
  const [authForm, setAuthForm] = useState({ name: '', email: '', password: '', role: 'patient', phone: '', specialty: 'Dermatology', reg_number: '' });
  const [consented, setConsented] = useState(!!user?.consented);
  const [visitId, setVisitId] = useState(null);

  const [seg, arg] = hash.split('?')[0].split('/').filter(Boolean);
  const page = seg || 'overview';
  const roleKey = !user ? null : user.role === 'clinician' ? (user.doctor_status === 'approved' ? 'doctor' : 'doctorLimited') : user.role === 'compliance' ? 'support' : user.role;
  const navItems = useMemo(() => (roleKey ? NAV[roleKey] || [] : []).map(([l, Icon]) => ({ label: l, slug: slug(l), Icon })), [roleKey]);
  const allowed = useMemo(() => new Set([...navItems.map(n => n.slug), ...(user?.role === 'patient' ? ['doctor'] : [])]), [navItems]);

  const flash = useCallback((message, kind = 'success') => { setNotice({ message, kind }); window.setTimeout(() => setNotice(n => (n && n.message === message ? null : n)), 5000); }, []);
  const run = async (fn, ok) => { setBusy(true); try { const r = await fn(); if (ok) flash(ok); return r; } catch (e) { flash(e.message, 'error'); return null; } finally { setBusy(false); } };

  /* stay signed in: renew the token whenever the site opens, and refresh what we know about the person */
  useEffect(() => {
    if (!getToken()) return;
    request('/api/auth/me').then(d => { saveSession(d.token, d.user); setUser(d.user); setConsented(!!d.user.consented); }).catch(() => {});
  }, []);

  /* remember where they were */
  useEffect(() => { if (user && seg && !seg.startsWith('?')) store.set('tena_last', hash.split('?')[0]); }, [hash, user]); // eslint-disable-line
  useEffect(() => {
    if (!user || !roleKey) return;
    if (!seg) { const last = store.get('tena_last'); const ok = last && allowed.has(String(last).split('/').filter(Boolean)[0]); go(ok ? last : (user.role === 'support' || user.role === 'compliance' ? 'support' : 'overview')); }
    else if (!allowed.has(seg)) go('overview');
  }, [user, roleKey, seg, allowed]); // eslint-disable-line

  const loadData = useCallback(async () => {
    if (!user) return;
    try {
      if (user.role === 'patient') { setAppointments(await request('/api/appointments')); setRecords(await request('/api/records')); setDoctors(await request('/api/clinicians')); }
      if (user.role === 'clinician') setAppointments(await request('/api/appointments'));
      if (user.role === 'admin') { setAppointments(await request('/api/admin/appointments')); setPendingDoctors((await request('/api/admin/clinicians')).filter(c => c.status === 'pending').length); }
      if (['admin', 'finance'].includes(user.role)) setReports(await request('/api/reports'));
    } catch (e) { if (!String(e.message).includes('sign in')) flash(e.message, 'error'); }
  }, [user, flash]);
  useEffect(() => { loadData(); }, [loadData]);
  const refreshMe = useCallback(() => request('/api/auth/me').then(d => { saveSession(d.token, d.user); setUser(d.user); }).catch(() => {}), []);
  useEffect(() => {   // unread badge
    if (!user || !['patient', 'clinician'].includes(user.role) || (user.role === 'clinician' && user.doctor_status !== 'approved')) return undefined;
    const tick = () => { if (!document.hidden) request('/api/conversations').then(d => setUnread(d.reduce((n, c) => n + c.unread, 0))).catch(() => {}); };
    tick(); const t = setInterval(tick, 30000); return () => clearInterval(t);
  }, [user]);

  const authenticate = async e => {
    e.preventDefault();
    await run(async () => {
      const payload = authMode === 'login' ? { email: authForm.email, password: authForm.password } : authForm;
      const data = await request(authMode === 'login' ? '/api/auth/login' : '/api/auth/register', { method: 'POST', body: JSON.stringify(payload) });
      if (authMode === 'register') store.set('tena_last', data.user.role === 'clinician' ? '/my-profile' : '/overview');   // new people start at the right place
      saveSession(data.token, data.user); setUser(data.user); setShowAuth(false); setConsented(!!data.user.consented); return data;
    }, authMode === 'login' ? 'Welcome back.' : 'Your account is ready.');
  };
  const logout = () => { clearSession(); setUser(null); setAppointments([]); setShowAuth(false); window.location.hash = ''; };
  const acceptConsent = async () => { const d = await run(() => request('/api/consent', { method: 'POST', body: '{}' }), 'Privacy notice accepted.'); if (d) { setUser(d.user); store.set('tena_user', d.user); setConsented(true); } };

  const openRoom = async aid => { const d = await run(() => request(`/api/appointments/${aid}/room`)); if (d?.url) window.open(d.url, '_blank', 'noopener,noreferrer'); loadData(); };
  const connectionHelp = async aid => { const d = await run(() => request(`/api/appointments/${aid}/ai/connection-help`, { method: 'POST', body: JSON.stringify({ issue: 'video and audio connection problem' }) })); if (d) flash(d.steps.join(' • '), 'info'); };
  const cancelAppointment = async aid => { const d = await run(() => request(`/api/appointments/${aid}/cancel`, { method: 'POST', body: '{}' }), 'Cancellation processed.'); if (d) loadData(); };
  const payAppointment = async id => {
    const d = await run(() => request(`/api/appointments/${id}/pay`, { method: 'POST', body: '{}' }));
    if (d?.checkout_url || d?.authorization_url) window.location.href = d.checkout_url || d.authorization_url;
    else if (d?.provider_ref) { const dev = await run(() => request(`/api/dev/simulate-payment/${id}`, { method: 'POST', body: '{}' }), 'Test payment done.'); if (dev) loadData(); else flash(d.message || 'Payment started. Configure Chapa to continue.', 'info'); } else loadData();
  };
  useEffect(() => {   // coming back from Chapa
    const q = new URLSearchParams(window.location.search); const ref = q.get('ref') || q.get('trx_ref'); if (!ref || !user) return;
    (async () => { const r = await run(() => request(`/api/payments/verify/${encodeURIComponent(ref)}`)); window.history.replaceState({}, '', window.location.pathname + '#/appointments');
      if (r) { flash(r.status === 'paid' ? 'Payment received. Your visit is confirmed.' : r.status === 'failed' ? 'The payment did not go through. You can try again from Appointments.' : 'Chapa has not confirmed the payment yet. Check Appointments in a minute.', r.status === 'paid' ? 'success' : 'info'); go('appointments'); loadData(); } })();
  }, [user]); // eslint-disable-line

  const hour = new Date().getHours(); const greet = `Good ${hour < 12 ? 'morning' : hour < 17 ? 'afternoon' : 'evening'}, ${user?.name?.replace(/^Dr\.?\s+/i, '').split(' ')[0] || 'there'}`;

  if (!user) return <Landing onStart={mode => { setAuthMode(mode); setShowAuth(true); }} askDoctor={() => { setAuthMode('register'); setShowAuth(true); }} showAuth={showAuth} setShowAuth={setShowAuth} authMode={authMode} setAuthMode={setAuthMode} authForm={authForm} setAuthForm={setAuthForm} authenticate={authenticate} busy={busy} notice={notice} />;

  const isPatient = user.role === 'patient'; const isDoctor = user.role === 'clinician';
  const approvedDoc = isDoctor && user.doctor_status === 'approved';
  const [title, desc] = page === 'overview' ? [greet, isPatient ? 'Your skin care, in one place.' : isDoctor ? 'Your patients and visits.' : 'Service overview.'] : TITLES[page] || [page, ''];
  const badge = s => (s === 'messages' && unread > 0 ? unread : s === 'appointments' && isPatient && appointments.some(a => a.status === 'pending_payment') ? '•' : s === 'doctors' && pendingDoctors > 0 ? pendingDoctors : null);

  let content = null;
  if (isPatient) {
    content = page === 'overview' ? <PatientOverview user={user} appointments={appointments} doctors={doctors} unread={unread} go={go} onRoom={openRoom} />
      : page === 'find-a-doctor' ? <FindDoctor doctors={doctors} go={go} />
      : page === 'doctor' ? <DoctorProfilePage key={arg} id={arg} user={user} consented={consented} acceptConsent={acceptConsent} go={go} flash={flash} afterBooking={() => { loadData(); go('appointments'); }} />
      : page === 'appointments' ? <Appointments appointments={appointments} user={user} go={go} onRoom={openRoom} onCancel={cancelAppointment} onPay={payAppointment} onConnectionHelp={connectionHelp} reload={loadData} flash={flash} />
      : page === 'messages' ? <Messages user={user} activeId={arg} go={go} flash={flash} refreshUnread={setUnread} />
      : page === 'my-doctors' ? <MyDoctors go={go} />
      : page === 'my-records' ? <Records records={records} go={go} />
      : page === 'my-skin-profile' ? <HealthProfile flash={flash} onSaved={u => { setUser(u); store.set('tena_user', u); }} />
      : page === 'skin-assistant' ? <SkinAssistant go={go} /> : null;
  } else if (isDoctor) {
    content = page === 'overview' ? <DoctorOverview user={user} appointments={appointments} unread={unread} go={go} onRoom={openRoom} />
      : page === 'my-profile' ? <ProfileEditor flash={flash} onChanged={refreshMe} />
      : page === 'availability' ? <Availability approved={approvedDoc} flash={flash} go={go} />
      : page === 'appointments' ? <Appointments appointments={appointments} user={user} go={go} onRoom={openRoom} onCancel={cancelAppointment} onOpenVisit={setVisitId} onNote={id => go(`clinical-notes/${id}`)} reload={loadData} flash={flash} />
      : page === 'my-patients' ? <MyPatients go={go} activeId={arg} flash={flash} onNote={id => go(`clinical-notes/${id}`)} />
      : page === 'messages' ? <Messages user={user} activeId={arg} go={go} flash={flash} refreshUnread={setUnread} />
      : page === 'clinical-notes' ? <ClinicalNotes appointments={appointments} flash={flash} initialId={arg} reload={loadData} />
      : page === 'assistant' ? <Copilot appointments={appointments} flash={flash} /> : null;
  } else {
    content = page === 'overview' ? <StaffOverview user={user} reports={reports} appointments={appointments} pendingDoctors={pendingDoctors} go={go} />
      : page === 'doctors' ? <ClinicianReview flash={flash} />
      : page === 'appointments' ? <AdminAppointments appointments={appointments} />
      : page === 'reports' ? <ReportsPage reports={reports} flash={flash} run={run} />
      : page === 'settings' ? <SettingsPanel flash={flash} /> : null;
  }
  if (!content && page === 'support') content = ['patient', 'clinician'].includes(user.role) ? <SupportDesk flash={flash} /> : <TicketQueue flash={flash} />;

  const brand = <div className="brand"><span className="brand-mark"><HeartPulse size={21} /></span><span>Tena<span className="brand-light">Medicare</span><small>SKIN HEALTH, ONLINE</small></span><button className="icon-btn mobile-close" onClick={() => setMobileNav(false)} aria-label="Close menu"><X size={18} /></button></div>;
  return <div className="app-shell">
    <aside className={`sidebar ${mobileNav ? 'open' : ''}`}>{brand}<div className="workspace-label">{isPatient ? 'MY SKIN CARE' : isDoctor ? 'MY PRACTICE' : 'WORKSPACE'}</div>
      <nav>{navItems.map(({ label, slug: s, Icon }) => <button key={s} className={`nav-item ${page === s || (s === 'find-a-doctor' && page === 'doctor') ? 'active' : ''}`} onClick={() => { go(s); setMobileNav(false); }}><Icon size={18} /><span>{label}</span>{badge(s) && <b className="nav-badge">{badge(s)}</b>}</button>)}</nav>
      <div className="sidebar-bottom"><div className="help-card"><div className="help-icon"><ShieldCheck size={18} /></div><strong>Private by design</strong><p>Photos and messages are encrypted and shared only with your doctor.</p><button onClick={() => go('support')}>Visit help centre <ArrowRight size={14} /></button></div><button className="nav-item" onClick={logout}><LogOut size={18} /><span>Sign out</span></button></div></aside>
    {mobileNav && <button className="mobile-scrim" onClick={() => setMobileNav(false)} aria-label="Close menu" />}
    <main className="main-area"><header className="topbar"><button className="icon-btn mobile-menu" onClick={() => setMobileNav(true)} aria-label="Open menu"><Menu /></button><div className="breadcrumb">Tena Medicare <span>/</span> <b>{title === greet ? 'Overview' : title}</b></div>
      <div className="top-actions"><span className="country-chip"><span className="ethiopia-dot" /> Ethiopia · ETB</span><button className="icon-btn bell" title="Messages" aria-label="Messages" onClick={() => ['patient', 'clinician'].includes(user.role) && (approvedDoc || isPatient) ? go('messages') : flash('You are all caught up.', 'info')}><Bell size={18} />{unread > 0 && <i className="bell-dot" />}</button>
        <div className="user-chip"><Avatar name={user.name} url={user.photo_url} size={36} /><div><strong>{user.name}</strong><small>{isDoctor ? `doctor · ${user.doctor_status}` : user.role}</small></div><ChevronDown size={15} /></div></div></header>
      <section className="page-content">
        {page !== 'doctor' && page !== 'messages' && <div className="page-heading"><div><div className="eyebrow">{isPatient ? 'YOUR SKIN, MADE SIMPLER' : isDoctor ? 'DOCTOR WORKSPACE' : 'SERVICE'}</div><h1>{title}</h1><p>{desc}</p></div>{isPatient && page !== 'find-a-doctor' && <button className="btn btn-primary" onClick={() => go('find-a-doctor')}><Search size={17} /> Find a doctor</button>}</div>}
        {notice && <div className={`toast ${notice.kind}`}><span>{notice.kind === 'error' ? <AlertCircle size={18} /> : <CircleCheck size={18} />}</span>{notice.message}<button onClick={() => setNotice(null)} aria-label="Dismiss"><X size={15} /></button></div>}
        {isPatient && !consented && <div className="consent-banner"><ShieldCheck size={22} /><div><strong>Privacy notice needs your attention</strong><p>Before booking, please confirm that you understand how your health information and photos are used to provide care.</p></div><button className="btn btn-primary" onClick={acceptConsent} disabled={busy}>Review & accept</button></div>}
        {content}
      </section></main>
    {visitId && <VisitDetail id={visitId} onClose={() => { setVisitId(null); loadData(); }} flash={flash} go={go} onNote={id => go(`clinical-notes/${id}`)} />}
    {isPatient && page !== 'skin-assistant' && <ChatLauncher onDoctor={id => go(`doctor/${id}`)} />}
  </div>;
}

/* ---------- admin and finance ---------- */
function StaffOverview({ user, reports, appointments, pendingDoctors, go }) {
  const total = Object.values(reports?.appointments || {}).reduce((a, b) => a + b, 0);
  return <div className="section-stack"><div className="stat-grid"><Stat icon={CalendarDays} label="Appointments" value={total} tone="blue" /><Stat icon={Wallet} label="Paid revenue" value={money(reports?.revenue_etb)} tone="green" />{user.role === 'admin' ? <Stat icon={Stethoscope} label="Doctors waiting" value={pendingDoctors} caption="Need your decision" tone="orange" /> : <Stat icon={RefreshCw} label="Refunds" value={money(reports?.refunds_etb)} tone="purple" />}<Stat icon={Users} label="Pending payouts" value={money(reports?.payouts_pending_etb)} tone="purple" /></div>
    {user.role === 'admin' && pendingDoctors > 0 && <div className="consent-banner"><Stethoscope size={22} /><div><strong>{pendingDoctors} doctor{pendingDoctors > 1 ? 's' : ''} waiting for approval</strong><p>Check their licence numbers, then approve or reject with a reason.</p></div><button className="btn btn-primary" onClick={() => go('doctors')}>Review now</button></div>}</div>;
}
function AdminAppointments({ appointments }) {
  return <div className="panel table-panel"><table className="table"><thead><tr><th>When</th><th>Patient</th><th>Doctor</th><th>Plan</th><th>Status</th><th>Payment</th></tr></thead><tbody>{appointments.map(a => <tr key={a.id}><td>{dateTime(a.starts_at)}</td><td>{a.patient}</td><td>{a.clinician}</td><td><TierChip tier={a.tier} /></td><td><span className={statusClass(a.status)}>{fmtLabel(a.status)}</span></td><td>{a.payment ? fmtLabel(a.payment) : '—'}</td></tr>)}</tbody></table>{!appointments.length && <p className="muted">No appointments yet.</p>}</div>;
}
function ReportsPage({ reports, flash, run }) {
  const max = Math.max(1, ...Object.values(reports?.appointments || {}));
  return <div className="section-stack"><div className="section-head"><div><h2>Service analytics</h2><p>Aggregate numbers only. No patient details.</p></div><button className="btn btn-outline" onClick={async () => { const d = await run(() => request('/api/admin/ai/analytics-summary', { method: 'POST', body: '{}' })); if (d) flash(d.summary, 'info'); }}><Sparkles size={15} /> AI summary</button></div>
    <div className="stat-grid"><Stat icon={Wallet} label="Paid revenue" value={money(reports?.revenue_etb)} tone="blue" /><Stat icon={CalendarDays} label="Appointments" value={Object.values(reports?.appointments || {}).reduce((a, b) => a + b, 0)} tone="green" /><Stat icon={RefreshCw} label="Refunds" value={money(reports?.refunds_etb)} tone="orange" /><Stat icon={Users} label="Pending payouts" value={money(reports?.payouts_pending_etb)} tone="purple" /></div>
    <div className="panel"><h2>Appointments by status</h2>{Object.entries(reports?.appointments || {}).map(([k, v]) => <div className="bar-row" key={k}><span>{k.replaceAll('_', ' ')}</span><div className="bar-track"><div className="bar-fill" style={{ width: `${Math.max(5, v / max * 100)}%` }} /></div><b>{v}</b></div>)}</div><FinancePanel flash={flash} /></div>;
}

createRoot(document.getElementById('root')).render(<React.StrictMode><App /></React.StrictMode>);
