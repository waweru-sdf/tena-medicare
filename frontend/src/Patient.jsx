import React, { useEffect, useMemo, useState } from 'react';
import { AlertCircle, ArrowRight, Calendar, CalendarDays, Camera, Check, Clock3, CreditCard, FileText, HelpCircle, MessageCircle, Pill, Search, ShieldCheck, Sparkles, Stethoscope, Users, Video, Wallet, Plus } from 'lucide-react';
import { request, store, money, dateTime, dayLabel, timeLabel, dayKey, prepareImage } from './lib.js';
import { Avatar, EmptyState, PhotoPicker, Stat, TierChip, DoctorCard, statusClass, label } from './ui.jsx';
import { ChatPanel, HoldTimer } from './Extra.jsx';

/* ---------- Find a doctor ---------- */
export function FindDoctor({ doctors, go }) {
  const [q, setQ] = useState(() => store.get('tena_find_q', '')); const [cond, setCond] = useState(() => store.get('tena_find_cond', ''));
  useEffect(() => { store.set('tena_find_q', q); store.set('tena_find_cond', cond); }, [q, cond]);
  const conditions = useMemo(() => { const n = {}; (doctors || []).forEach(d => (d.conditions || []).forEach(c => { n[c] = (n[c] || 0) + 1; })); return Object.entries(n).sort((a, b) => b[1] - a[1]).slice(0, 10).map(x => x[0]); }, [doctors]);
  const shown = (doctors || []).filter(d => (!cond || (d.conditions || []).includes(cond)) && `${d.name} ${d.specialty} ${(d.conditions || []).join(' ')} ${d.language} ${d.bio}`.toLowerCase().includes(q.toLowerCase()));
  return <div className="section-stack">
    <div className="section-head"><div><h2>Find your skin doctor</h2><p>Every doctor here has been checked and approved. Open a profile to see what they treat and book a time.</p></div><div className="searchbox"><Search size={17} /><input placeholder="Search a condition, name or language" value={q} onChange={e => setQ(e.target.value)} aria-label="Search doctors" /></div></div>
    {!!conditions.length && <div className="chip-row filters"><button className={`chip ${!cond ? 'on' : ''}`} onClick={() => setCond('')}>All</button>{conditions.map(c => <button key={c} className={`chip ${cond === c ? 'on' : ''}`} onClick={() => setCond(cond === c ? '' : c)}>{c}</button>)}</div>}
    <div className="doctor-grid">{shown.map(d => <DoctorCard key={d.id} d={d} onOpen={() => go(`doctor/${d.id}`)} />)}</div>
    {doctors && !shown.length && <EmptyState icon={Users} title="No doctors match that" text="Try another word, or clear the filter. New doctors join after the Tena Medicare team approves them." action={() => { setQ(''); setCond(''); }} actionText="Clear filters" />}
  </div>;
}

/* ---------- One doctor: profile + booking ---------- */
const PLANS = [
  { id: 'video', title: 'Video visit', icon: Video, blurb: 'One private 30-minute video consultation.', bullets: ['Your message and photos reach the doctor first', 'Prescription if your doctor decides you need one'] },
  { id: 'video_chat', title: 'Video + chat', icon: MessageCircle, blurb: 'The visit, plus messaging with this doctor for 30 days.', bullets: ['Everything in Video visit', 'Send progress photos and ask follow-up questions', 'Best for follow-ups and ongoing conditions'], flag: 'Best for follow-ups' },
];
export function DoctorProfilePage({ id, user, consented, acceptConsent, go, flash, afterBooking }) {
  const [d, setD] = useState(null); const [slots, setSlots] = useState(null); const [err, setErr] = useState('');
  const draftKey = `tena_draft_book_${id}`; const saved = store.get(draftKey, {});
  const [day, setDay] = useState(''); const [slot, setSlot] = useState(''); const [tier, setTier] = useState(saved.tier || 'video'); const [reason, setReason] = useState(saved.reason || ''); const [files, setFiles] = useState([]); const [busy, setBusy] = useState(false);
  useEffect(() => { setD(null); setErr(''); request(`/api/clinicians/${id}`).then(setD).catch(e => setErr(e.message)); request(`/api/clinicians/${id}/slots`).then(s => { setSlots(s); setDay(dayKey(s[0]?.starts_at)); }).catch(() => setSlots([])); }, [id]);
  useEffect(() => { store.set(draftKey, { tier, reason }); }, [tier, reason, draftKey]);   // the message to the doctor is kept if you leave and come back
  const days = useMemo(() => { const m = new Map(); (slots || []).forEach(s => { const k = dayKey(s.starts_at); if (!m.has(k)) m.set(k, []); m.get(k).push(s); }); return m; }, [slots]);
  if (err) return <EmptyState icon={AlertCircle} title="This doctor is not available" text={err} action={() => go('find-a-doctor')} actionText="Back to doctors" />;
  if (!d) return <div className="panel"><p className="muted">Loading profile…</p></div>;
  const price = tier === 'video_chat' ? d.fee_chat_etb : d.fee_video_etb;
  const book = async e => {
    e.preventDefault();
    if (!consented) return flash('Please accept the privacy notice before booking.', 'error');
    setBusy(true); let ap;
    try { ap = await request('/api/appointments', { method: 'POST', body: JSON.stringify({ slot_id: Number(slot), reason, tier }) }); }
    catch (x) { flash(x.message, 'error'); setBusy(false); request(`/api/clinicians/${id}/slots`).then(setSlots).catch(() => {}); return; }
    let failed = 0;
    for (const f of files) { try { const fd = new FormData(); fd.append('photo', f); await request(`/api/appointments/${ap.id}/photos`, { method: 'POST', body: fd }); } catch { failed += 1; } }
    store.del(draftKey);
    if (failed) flash(`${failed} photo${failed > 1 ? 's' : ''} could not be uploaded. You can add them from Appointments.`, 'error');
    try {
      const pay = await request(`/api/appointments/${ap.id}/pay`, { method: 'POST', body: '{}' });
      if (pay.checkout_url || pay.authorization_url) { window.location.href = pay.checkout_url || pay.authorization_url; return; }
      await request(`/api/dev/simulate-payment/${ap.id}`, { method: 'POST', body: '{}' }); flash('Booked. (Test payment: no real money moved.)');
    } catch (x) { flash(x.message + ' Your time is held for a few minutes: finish payment from Appointments.', 'error'); }
    setBusy(false); afterBooking();
  };
  return <div className="profile-layout">
    <section className="profile-main">
      <button className="text-button back-link" onClick={() => go('find-a-doctor')}>← All doctors</button>
      <div className="profile-hero panel"><Avatar name={d.name} url={d.photo_url} size={132} round={false} /><div><div className="eyebrow">{d.specialty}</div><h2>{d.name}</h2><p className="profile-facts">{d.years_exp ? <span><Stethoscope size={15} /> {d.years_exp} years of experience</span> : null}<span>🗣 {d.language}</span>{d.consultations > 0 && <span><Check size={15} /> {d.consultations} visits completed</span>}</p><span className="verified-line"><ShieldCheck size={15} /> Approved by Tena Medicare</span></div></div>
      <div className="panel"><h3>What I do</h3><p className="profile-bio">{d.bio}</p></div>
      <div className="panel"><h3>Conditions I treat</h3><div className="chip-row">{d.conditions.map(c => <span className="chip" key={c}>{c}</span>)}</div></div>
      <div className="panel soft"><h3>Before you book</h3><ul className="check-list"><li><Check /> Describe what you are going through and add up to 4 photos: daylight, one close-up, one from further away</li><li><Check /> If you need follow-up, choose Video + chat to message this doctor for 30 days</li><li><Check /> For emergencies (spreading rash with fever, swollen face or lips) call 907</li></ul></div>
    </section>
    <form className="booking-card panel" onSubmit={book}>
      <h3>Book {d.name.split(' ').slice(0, 2).join(' ')}</h3>
      <div className="step-label"><b>1</b> Pick a time</div>
      {slots === null ? <p className="muted">Loading times…</p> : !days.size ? <p className="muted">No open times right now. Please check again soon.</p> : <>
        <div className="day-row">{[...days.keys()].map(k => <button type="button" key={k} className={day === k ? 'on' : ''} onClick={() => { setDay(k); setSlot(''); }}>{dayLabel(days.get(k)[0].starts_at)}</button>)}</div>
        <div className="time-row">{(days.get(day) || []).map(s => <button type="button" key={s.id} className={String(s.id) === String(slot) ? 'on' : ''} onClick={() => setSlot(String(s.id))}>{timeLabel(s.starts_at)}</button>)}</div></>}
      <div className="step-label"><b>2</b> Choose your plan</div>
      <div className="plan-pick">{PLANS.map(p => <label key={p.id} className={`plan-option ${tier === p.id ? 'on' : ''}`}><input type="radio" name="tier" checked={tier === p.id} onChange={() => setTier(p.id)} /><span className="po-icon"><p.icon size={18} /></span><span className="po-body"><strong>{p.title} {p.flag && <em>{p.flag}</em>}</strong><small>{p.blurb}</small><ul>{p.bullets.map(b => <li key={b}>{b}</li>)}</ul></span><b className="po-price">{money(p.id === 'video_chat' ? d.fee_chat_etb : d.fee_video_etb)}</b></label>)}</div>
      <div className="step-label"><b>3</b> Tell the doctor what is going on</div>
      <label className="plain">Your message<textarea rows={4} maxLength={500} value={reason} onChange={e => setReason(e.target.value)} required placeholder="Where is it, when did it start, does it itch or hurt, what have you tried?" /><small className="field-hint">{reason.length}/500 · Saved on this device as you type.</small></label>
      <label className="plain">Photos of the skin concern (up to 4)<PhotoPicker files={files} setFiles={setFiles} max={4} prepare={prepareImage} /></label>
      {!consented && <div className="consent-inline"><ShieldCheck size={18} /><span>Please accept the privacy notice to book.</span><button type="button" className="btn btn-outline btn-sm" onClick={acceptConsent}>Accept</button></div>}
      <div className="payment-summary"><div className="payment-icon"><Wallet /></div><div><strong>{tier === 'video_chat' ? 'Video + chat' : 'Video visit'}</strong><p>Secure payment with Chapa, in birr</p></div><strong>{money(price)}</strong></div>
      <button className="btn btn-primary btn-full" disabled={busy || !slot || !consented || !reason.trim()}>{busy ? 'Please wait…' : 'Continue to payment'} <ArrowRight size={17} /></button>
      <p className="mini-note">Your time is held for a few minutes while you pay.</p>
    </form>
  </div>;
}

/* ---------- Appointments (patient and doctor) ---------- */
export function Appointments({ appointments, user, go, onRoom, onCancel, onPay, onConnectionHelp, onOpenVisit, onNote, reload, flash }) {
  const upload = async (a, file) => { try { const f = await prepareImage(file); const fd = new FormData(); fd.append('photo', f); await request(`/api/appointments/${a.id}/photos`, { method: 'POST', body: fd }); flash('Photo added.'); reload(); } catch (x) { flash(x.message, 'error'); } };
  const isPatient = user.role === 'patient';
  return <div className="section-stack"><div className="section-head"><div><h2>Appointments</h2><p>{isPatient ? 'Your visits, payments and video rooms.' : 'Visits booked with you. Open a visit to see the patient’s message and photos.'}</p></div><span className="count-chip">{appointments.length} total</span></div>
    {appointments.map(a => { const soon = new Date(a.starts_at) - Date.now() <= 600000; const live = ['confirmed', 'checked_in', 'in_consultation'].includes(a.status);
      return <article className="appointment-card" key={a.id}><Avatar name={isPatient ? a.clinician : a.patient} url={isPatient ? a.photo_url : null} size={46} />
        <div className="appointment-card-main"><div className="appointment-card-top"><div><h3>{isPatient ? a.clinician : a.patient}</h3><p>{a.specialty} · <TierChip tier={a.tier} /></p></div><span className={statusClass(a.status)}>{label(a.status)}</span></div>
          {a.status === 'pending_payment' && a.hold_expires_at && <p><HoldTimer iso={a.hold_expires_at} /></p>}
          {a.status === 'cancelled' && a.payment && ['refund_pending', 'refunded'].includes(a.payment) && <p className="muted">Refund {a.payment === 'refunded' ? 'completed' : 'in progress with Chapa'}.</p>}
          {isPatient && a.reason && <p className="appt-reason">“{a.reason}”</p>}
          <div className="appointment-card-meta"><span><CalendarDays size={15} />{dateTime(a.starts_at)}</span>{isPatient && <span><Wallet size={15} />{money(a.fee_etb)}</span>}{a.photos > 0 && <span><Camera size={15} />{a.photos} photo{a.photos > 1 ? 's' : ''}</span>}{a.tier === 'video_chat' && a.chat_until && a.status !== 'pending_payment' && a.status !== 'cancelled' && <span><MessageCircle size={15} />{a.chat_open ? `Chat open until ${dateTime(a.chat_until)}` : 'Chat closed'}</span>}</div>
          <div className="appointment-card-actions">
            {isPatient && a.status === 'pending_payment' && <button className="btn btn-primary btn-sm" onClick={() => onPay(a.id)}><CreditCard size={15} /> Complete payment</button>}
            {live && <button className="btn btn-primary btn-sm" disabled={!soon} title="The room opens 10 minutes before the appointment" onClick={() => onRoom(a.id)}><Video size={15} /> {soon ? 'Join consultation' : 'Opens 10 min before'}</button>}
            {a.chat_open && a.conversation_id && <button className="btn btn-outline btn-sm" onClick={() => go(`messages/${a.conversation_id}`)}><MessageCircle size={15} /> Open chat</button>}
            {!isPatient && ['confirmed', 'checked_in', 'in_consultation', 'completed'].includes(a.status) && <button className="btn btn-outline btn-sm" onClick={() => onOpenVisit(a.id)}><FileText size={15} /> Open visit</button>}
            {!isPatient && live && <button className="btn btn-outline btn-sm" onClick={() => onNote(a.id)}><FileText size={15} /> Clinical note</button>}
            {isPatient && live && <button className="btn btn-outline btn-sm" onClick={() => onConnectionHelp(a.id)}><HelpCircle size={15} /> Connection help</button>}
            {isPatient && ['pending_payment', 'confirmed'].includes(a.status) && a.photos < 4 && <label className="btn btn-outline btn-sm file-btn"><Camera size={15} /> Add photo<input type="file" accept="image/*" hidden onChange={e => { const f = e.target.files?.[0]; e.target.value = ''; if (f) upload(a, f); }} /></label>}
            {['pending_payment', 'confirmed'].includes(a.status) && <button className="btn btn-ghost btn-sm danger-text" onClick={() => { if (window.confirm('Cancel this appointment? If you cancel early enough, your payment is refunded.')) onCancel(a.id); }}>Cancel</button>}
          </div></div></article>; })}
    {!appointments.length && <EmptyState icon={CalendarDays} title="No appointments yet" text={isPatient ? 'Find a doctor and book your first visit.' : 'Booked visits will appear here.'} action={isPatient ? () => go('find-a-doctor') : undefined} actionText="Find a doctor" />}</div>;
}

/* ---------- My doctors (a patient can have many) ---------- */
export function MyDoctors({ go }) {
  const [rows, setRows] = useState(null);
  useEffect(() => { request('/api/patient/doctors').then(setRows).catch(() => setRows([])); }, []);
  if (rows && !rows.length) return <EmptyState icon={Stethoscope} title="Your care team will appear here" text="Once you have paid for a visit with a doctor, they join your care team. You can have as many doctors as you need." action={() => go('find-a-doctor')} actionText="Find a doctor" />;
  return <div className="section-stack"><div className="section-head"><div><h2>My doctors</h2><p>Everyone who is looking after your skin.</p></div></div>
    {(rows || []).map(r => <article className="panel team-card" key={r.id}><Avatar name={r.name} url={r.photo_url} size={64} round={false} />
      <div className="team-main"><div className="record-top"><div><h3>{r.name}</h3><p className="muted">{r.specialty} · {r.visits.length} visit{r.visits.length > 1 ? 's' : ''} · last {dateTime(r.last_visit)}</p></div>{r.chat_open ? <span className="status green">Chat open until {dateTime(r.chat_until)}</span> : <span className="status amber">No chat</span>}</div>
        <div className="chip-row">{r.visits.slice(-3).map(v => <TierChip key={v.id} tier={v.tier} />)}{r.prescriptions > 0 && <span className="chip"><Pill size={12} /> {r.prescriptions} prescription{r.prescriptions > 1 ? 's' : ''}</span>}</div>
        <div className="button-row">{r.chat_open && r.conversation_id && <button className="btn btn-primary btn-sm" onClick={() => go(`messages/${r.conversation_id}`)}><MessageCircle size={15} /> Message{r.unread > 0 ? ` (${r.unread})` : ''}</button>}<button className="btn btn-outline btn-sm" onClick={() => go(`doctor/${r.id}`)}>{r.chat_open ? 'View profile' : 'Book again'}</button></div></div></article>)}</div>;
}

/* ---------- Records and prescriptions ---------- */
export function Records({ records, go }) {
  const [rx, setRx] = useState(null);
  useEffect(() => { request('/api/prescriptions').then(setRx).catch(() => setRx([])); }, []);
  return <div className="section-stack">
    <div className="section-head"><div><h2>Prescriptions</h2><p>From every doctor you have seen, on any plan.</p></div></div>
    {(rx || []).map(p => <article className="record-card" key={p.id}><div className="record-icon"><Pill /></div><div className="record-main"><div className="record-top"><strong>{p.medicine}</strong><span>{dateTime(p.issued_at)}</span></div><p>{p.dose} · {p.route} · {p.frequency} · for {p.duration} · quantity {p.quantity}</p>{p.instructions && <div className="record-plan"><b>Instructions</b><p>{p.instructions}</p></div>}<small className="muted">Prescribed by {p.doctor} ({p.specialty})</small></div></article>)}
    {rx && !rx.length && <EmptyState icon={Pill} title="No prescriptions yet" text="If a doctor prescribes something after your visit, it will appear here." />}
    <div className="section-head"><div><h2>Visit summaries</h2><p>Written by your doctor after a completed visit.</p></div></div>
    {records.map((r, i) => <article className="record-card" key={i}><div className="record-icon"><FileText /></div><div className="record-main"><div className="record-top"><strong>{r.appointment?.clinician} · {r.appointment?.specialty}</strong><span>{dateTime(r.appointment?.starts_at)}</span></div><p>{r.summary || 'No summary was recorded for this visit.'}</p>{r.plan && <div className="record-plan"><b>Plan</b><p>{r.plan}</p></div>}</div><span className="status green">Completed</span></article>)}
    {!records.length && <EmptyState icon={FileText} title="Summaries appear after a completed visit" text="Your doctor’s written summary and plan will be here." />}</div>;
}

/* ---------- My skin profile: saved once, shared with each doctor you book ---------- */
export function HealthProfile({ flash, onSaved }) {
  const [f, setF] = useState(null); const [busy, setBusy] = useState(false);
  useEffect(() => { request('/api/patient/profile').then(d => setF({ name: d.name, phone: d.phone || '', ...d.profile })).catch(e => flash(e.message, 'error')); }, []); // eslint-disable-line
  if (!f) return <div className="panel"><p className="muted">Loading…</p></div>;
  const set = k => e => setF({ ...f, [k]: e.target.value });
  const save = async e => { e.preventDefault(); setBusy(true); try { const d = await request('/api/patient/profile', { method: 'PUT', body: JSON.stringify(f) }); onSaved && onSaved(d.user); flash('Saved. Your doctors will see this, so you never have to repeat it.'); } catch (x) { flash(x.message, 'error'); } setBusy(false); };
  return <div className="two-column"><div className="panel form-panel"><div className="panel-title"><ShieldCheck /><div><h2>My skin profile</h2><p>Fill it in once. Each doctor you book can see it, and only after you have paid for their visit.</p></div></div>
    <form className="form-stack" onSubmit={save}><div className="form-grid"><label>Full name<input value={f.name} onChange={set('name')} required /></label><label>Phone (used for payment)<input value={f.phone} onChange={set('phone')} placeholder="09… or +251…" /></label></div>
      <div className="form-grid"><label>Date of birth<input type="date" value={f.date_of_birth} onChange={set('date_of_birth')} max={new Date().toISOString().slice(0, 10)} /></label><label>Sex<select value={f.sex} onChange={set('sex')}><option value="">Prefer not to say</option><option>Female</option><option>Male</option></select></label></div>
      <label>Skin type<select value={f.skin_type} onChange={set('skin_type')}><option value="">Not sure</option><option>Dry</option><option>Oily</option><option>Combination</option><option>Sensitive</option><option>Normal</option></select></label>
      <label>Allergies<textarea rows={2} value={f.allergies} onChange={set('allergies')} placeholder="Medicines, creams, foods… or “none known”" /></label>
      <label>Medicines and products you use now<textarea rows={2} value={f.medications} onChange={set('medications')} placeholder="Include creams, soaps and supplements" /></label>
      <label>Skin and health history<textarea rows={3} value={f.history} onChange={set('history')} placeholder="Past skin problems, family history, long-term illnesses" /></label>
      <label>Preferred language<select value={f.language} onChange={set('language')}><option value="">Any</option><option>Amharic</option><option>English</option><option>Oromo</option><option>Tigrinya</option><option>Somali</option></select></label>
      <button className="btn btn-primary" disabled={busy}>Save profile <Check size={16} /></button></form></div>
    <div className="panel blue-panel"><div className="illustration-icon"><ShieldCheck size={30} /></div><h2>Saved for next time</h2><p>Your details stay on your account, encrypted. You can change them whenever you like.</p></div></div>;
}

/* ---------- Skin assistant page ---------- */
export function SkinAssistant({ go }) {
  return <div className="assistant-layout"><div className="panel assistant-panel"><ChatPanel onDoctor={id => go(`doctor/${id}`)} /></div>
    <div className="suggestion-panel"><h3>What it can do</h3><p>Share general skin-care tips, explain skin terms, help you choose between our doctors and plans, and tell you how to take a useful photo.</p><h3>What it cannot do</h3><p>Diagnose, name medicines or choose a treatment. Only a doctor who has seen your skin can do that.</p><div className="urgent-box"><AlertCircle size={18} /><p><strong>Need urgent help?</strong><br />Spreading rash with fever, swollen face or lips, blistering in the mouth or eyes: call 907 or go to the nearest hospital.</p></div></div></div>;
}

/* ---------- Patient overview ---------- */
export function PatientOverview({ user, appointments, doctors, unread, go, onRoom }) {
  const upcoming = appointments.filter(a => ['confirmed', 'checked_in', 'in_consultation'].includes(a.status)).sort((a, b) => new Date(a.starts_at) - new Date(b.starts_at));
  const waiting = appointments.filter(a => a.status === 'pending_payment');
  const next = upcoming[0];
  const [team, setTeam] = useState([]); const [rxCount, setRx] = useState(0); const [profileDone, setDone] = useState(true);
  useEffect(() => { request('/api/patient/doctors').then(setTeam).catch(() => {}); request('/api/prescriptions').then(r => setRx(r.length)).catch(() => {}); request('/api/patient/profile').then(d => setDone(!!(d.profile.skin_type || d.profile.allergies || d.profile.history))).catch(() => {}); }, []);
  return <div className="section-stack">
    <div className="welcome-banner"><div><span className="welcome-chip"><Sparkles size={14} /> YOUR SKIN, YOUR PLAN</span><h2>Let’s get your skin looked at.</h2><p>Find a dermatologist, book a time, send photos and follow up, all in one place.</p><button className="btn btn-white" onClick={() => go('find-a-doctor')}>Find a doctor <ArrowRight size={16} /></button></div></div>
    {waiting.length > 0 && <div className="consent-banner"><CreditCard size={22} /><div><strong>{waiting.length} booking{waiting.length > 1 ? 's' : ''} waiting for payment</strong><p>Your time is held for a few minutes. Finish payment to confirm it.</p></div><button className="btn btn-primary" onClick={() => go('appointments')}>Pay now</button></div>}
    <div className="stat-grid"><Stat icon={CalendarDays} label="Upcoming visits" value={upcoming.length} caption="Confirmed and paid" tone="blue" /><Stat icon={Users} label="My doctors" value={team.length} caption="Your care team" tone="green" /><Stat icon={MessageCircle} label="Unread messages" value={unread} caption="From your doctors" tone="purple" /><Stat icon={Pill} label="Prescriptions" value={rxCount} caption="From all doctors" tone="orange" /></div>
    <div className="dashboard-grid"><div className="panel upcoming-panel"><div className="panel-heading"><div><h2>Next visit</h2><p>Your next video consultation.</p></div><button className="text-button" onClick={() => go('appointments')}>All appointments <ArrowRight size={15} /></button></div>
      {next ? <div className="next-visit"><Avatar name={next.clinician} url={next.photo_url} size={56} /><div><strong>{next.clinician}</strong><span>{next.specialty} · {dateTime(next.starts_at)}</span><TierChip tier={next.tier} /></div><button className="btn btn-primary btn-sm" disabled={new Date(next.starts_at) - Date.now() > 600000} onClick={() => onRoom(next.id)}><Video size={15} /> {new Date(next.starts_at) - Date.now() > 600000 ? 'Opens 10 min before' : 'Join'}</button></div> : <EmptyState icon={CalendarDays} title="No upcoming visits" text="Choose a doctor and book a time." action={() => go('find-a-doctor')} actionText="Find a doctor" />}</div>
      <div className="panel quick-panel"><div className="panel-heading"><div><h2>Quick actions</h2></div></div><div className="quick-actions">{[[Search, 'Find a doctor', 'Profiles and fees', 'find-a-doctor'], [MessageCircle, 'Messages', 'Chat and photos', 'messages'], [Pill, 'Prescriptions', 'And visit summaries', 'my-records'], [Sparkles, 'Skin assistant', 'Tips and doctor matching', 'skin-assistant']].map(([Icon, l, sub, to]) => <button className="quick-action" key={l} onClick={() => go(to)}><span className="quick-icon"><Icon size={18} /></span><span><strong>{l}</strong><small>{sub}</small></span><ArrowRight size={16} /></button>)}</div></div></div>
    {!profileDone && <div className="bottom-banner"><div className="bottom-banner-icon"><ShieldCheck /></div><div><strong>Save your skin profile once.</strong><p>Allergies, products and history: your doctors see it for every visit, so you never repeat yourself.</p></div><button className="text-button" onClick={() => go('my-skin-profile')}>Fill it in <ArrowRight size={15} /></button></div>}
  </div>;
}
