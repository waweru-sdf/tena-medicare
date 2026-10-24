import React, { useCallback, useEffect, useRef, useState } from 'react';
import { AlertCircle, ArrowRight, CalendarDays, Camera, Check, Clock3, FileText, MessageCircle, Pill, Plus, ShieldCheck, Trash2, Users, Video } from 'lucide-react';
import { request, store, money, dateTime, dayLabel, timeLabel, prepareImage, publicUrl } from './lib.js';
import { Avatar, AuthImg, DoctorCard, EmptyState, Lightbox, Modal, Stat, TierChip, statusClass, label } from './ui.jsx';
import { PrescriptionForm } from './Extra.jsx';

const CONDITION_IDEAS = ['Acne', 'Eczema', 'Psoriasis', 'Rosacea', 'Pigmentation', 'Melasma', 'Hair loss', 'Dandruff', 'Fungal infections', 'Scabies', 'Warts', 'Moles', 'Scars', 'Nail problems', 'Baby eczema', 'Allergic rashes'];
const STATUS_COPY = {
  draft: ['Finish your profile', 'Patients cannot see you yet. Complete the checklist, then submit your profile for approval.', 'amber'],
  pending: ['Waiting for admin approval', 'The Tena Medicine team is reviewing your profile and licence. You will be notified, and you can keep editing meanwhile.', 'amber'],
  rejected: ['Changes needed', 'Your profile was not approved yet. Read the reason below, fix it and submit again.', 'red'],
  approved: ['Approved: you are live', 'Patients can find you and book the times you publish.', 'green'],
};

/* ---------- Profile and approval ---------- */
export function ProfileEditor({ flash, onChanged }) {
  const [p, setP] = useState(null); const [busy, setBusy] = useState(false); const photoInput = useRef(null);
  const load = useCallback(() => request('/api/clinician/profile').then(setP).catch(e => flash(e.message, 'error')), [flash]);
  useEffect(() => { load(); }, [load]);
  if (!p) return <div className="panel"><p className="muted">Loading…</p></div>;
  const set = k => e => setP({ ...p, [k]: e.target.value });
  const addCond = c => { const cur = p.conditions.split(',').map(x => x.trim()).filter(Boolean); if (!cur.includes(c)) setP({ ...p, conditions: [...cur, c].join(', ') }); };
  const save = async () => {
    setBusy(true);
    try { const d = await request('/api/clinician/profile', { method: 'PUT', body: JSON.stringify({ specialty: p.specialty, language: p.language, bio: p.bio, conditions: p.conditions, years_exp: p.years_exp ?? '', reg_number: p.reg_number }) }); setP(d); onChanged(); flash(d.status === 'pending' && p.status === 'approved' ? 'Saved. Changing your licence number sends your profile back for review.' : 'Profile saved.'); return d; }
    catch (e) { flash(e.message, 'error'); return null; } finally { setBusy(false); }
  };
  const submit = async () => { const d = await save(); if (!d) return; try { setP(await request('/api/clinician/profile/submit', { method: 'POST', body: '{}' })); onChanged(); flash('Submitted. The admin team will review your profile.'); } catch (e) { flash(e.message, 'error'); } };
  const upload = async e => { const f = e.target.files?.[0]; e.target.value = ''; if (!f) return; setBusy(true); try { const fd = new FormData(); fd.append('photo', await prepareImage(f, 900)); const d = await request('/api/clinician/photo', { method: 'POST', body: fd }); setP({ ...p, photo_url: d.photo_url + '?v=' + Date.now(), missing: d.missing }); onChanged(); flash('Photo saved.'); } catch (x) { flash(x.message, 'error'); } finally { setBusy(false); } };
  const [title, text, tone] = STATUS_COPY[p.status] || STATUS_COPY.draft;
  const preview = { name: p.name, specialty: p.specialty, bio: p.bio, language: p.language, years_exp: p.years_exp, photo_url: p.photo_url, conditions: p.conditions.split(',').map(x => x.trim()).filter(Boolean), price_chat: p.price_chat };
  return <div className="section-stack">
    <div className={`approval-banner ${tone}`}>{p.status === 'approved' ? <ShieldCheck size={22} /> : <AlertCircle size={22} />}<div><strong>{title}</strong><p>{text}</p>{p.status === 'rejected' && p.review_reason && <p className="review-reason">Reason: {p.review_reason}</p>}</div></div>
    <div className="two-column">
      <div className="panel form-panel"><h2>Your public profile</h2>
        <div className="photo-edit"><Avatar name={p.name} url={p.photo_url} size={96} round={false} /><div><button type="button" className="btn btn-outline btn-sm" onClick={() => photoInput.current?.click()} disabled={busy}><Camera size={15} /> {p.photo_url ? 'Change photo' : 'Upload photo'}</button><input ref={photoInput} type="file" accept="image/*" hidden onChange={upload} /><small className="field-hint">A clear, friendly head-and-shoulders photo. Patients see it first.</small></div></div>
        <form className="form-stack" onSubmit={e => { e.preventDefault(); save(); }}>
          <div className="form-grid"><label>Specialty<input value={p.specialty} onChange={set('specialty')} required /></label><label>Years of experience<input type="number" min="0" max="60" value={p.years_exp ?? ''} onChange={set('years_exp')} /></label></div>
          <label>Languages you consult in<input value={p.language} onChange={set('language')} placeholder="Amharic, English" /></label>
          <label>What you do (shown on your profile)<textarea rows={5} maxLength={2000} value={p.bio} onChange={set('bio')} placeholder="Describe your work, the people you help most and how you like to work with patients." /><small className="field-hint">{p.bio.length}/2000 · at least 40 characters</small></label>
          <label>Conditions you treat (comma separated)<input value={p.conditions} onChange={set('conditions')} placeholder="Acne, Eczema, Psoriasis" /></label>
          <div className="chip-row">{CONDITION_IDEAS.map(c => <button type="button" key={c} className="chip" onClick={() => addCond(c)}>+ {c}</button>)}</div>
          <div className="price-note"><b>Prices are set by Tena Medicine.</b> Every doctor charges the same: chat with a doctor {money(p.price_chat)}, video consultation + chat {money(p.price_video_chat)}.</div>
          <label>Medical registration (licence) number<input value={p.reg_number || ''} onChange={set('reg_number')} required /></label>
          <div className="button-row"><button className="btn btn-primary" disabled={busy}>Save profile</button>
            {['draft', 'rejected'].includes(p.status) && <button type="button" className="btn btn-outline" disabled={busy} onClick={submit}>Save and submit for approval <ArrowRight size={15} /></button>}</div></form></div>
      <div className="stack">
        <div className="panel"><h3>Checklist</h3>{p.missing.length ? <ul className="todo-list">{p.missing.map(m => <li key={m}><AlertCircle size={15} /> {m}</li>)}</ul> : <p className="ok-line"><Check size={16} /> Everything needed is filled in.</p>}</div>
        <div className="panel"><h3>How patients see you</h3><DoctorCard d={{ ...preview, open_slots: null }} preview /></div></div></div></div>;
}

/* ---------- Availability ---------- */
const HOURS = ['08:00', '09:00', '10:00', '11:00', '12:00', '14:00', '15:00', '16:00', '17:00', '18:00'];
export function Availability({ approved, flash, go }) {
  const [rows, setRows] = useState(null); const [date, setDate] = useState(''); const [times, setTimes] = useState(['09:00', '11:00', '14:00']); const [busy, setBusy] = useState(false);
  const load = useCallback(() => request('/api/clinician/slots').then(setRows).catch(() => setRows([])), []);
  useEffect(() => { load(); }, [load]);
  if (!approved) return <EmptyState icon={ShieldCheck} title="Available once you are approved" text="Finish your profile and wait for approval, then you can publish the times patients can book." action={() => go('my-profile')} actionText="Go to my profile" />;
  const add = async e => { e.preventDefault(); if (!date || !times.length) return; setBusy(true); let ok = 0; let fail = '';
    for (const t of times) { try { await request('/api/clinician/slots', { method: 'POST', body: JSON.stringify({ starts_at: new Date(`${date}T${t}:00`).toISOString() }) }); ok += 1; } catch (x) { fail = x.message; } }
    setBusy(false); flash(ok ? `${ok} time${ok > 1 ? 's' : ''} added.${fail ? ' Some were skipped: ' + fail : ''}` : fail, ok ? 'success' : 'error'); load(); };
  const del = async s => { try { await request(`/api/clinician/slots/${s.id}`, { method: 'DELETE' }); load(); } catch (x) { flash(x.message, 'error'); } };
  const byDay = (rows || []).reduce((m, s) => { const k = dayLabel(s.starts_at); (m[k] = m[k] || []).push(s); return m; }, {});
  return <div className="two-column"><div className="panel form-panel"><div className="panel-title"><CalendarDays /><div><h2>Publish times</h2><p>Each time is a 30-minute video slot (Ethiopia time).</p></div></div>
    <form className="form-stack" onSubmit={add}><label>Day<input type="date" value={date} min={new Date().toISOString().slice(0, 10)} onChange={e => setDate(e.target.value)} required /></label>
      <div><span className="plain-label">Start times</span><div className="time-row">{HOURS.map(h => <button type="button" key={h} className={times.includes(h) ? 'on' : ''} onClick={() => setTimes(times.includes(h) ? times.filter(x => x !== h) : [...times, h])}>{h}</button>)}</div></div>
      <button className="btn btn-primary" disabled={busy || !date || !times.length}><Plus size={16} /> Add {times.length} time{times.length === 1 ? '' : 's'}</button></form></div>
    <div className="panel"><h2>Your upcoming times</h2>{rows && !rows.length && <p className="muted">Nothing published yet.</p>}
      {Object.entries(byDay).map(([day, list]) => <div className="slot-day" key={day}><strong>{day}</strong><div className="slot-list">{list.map(s => <span className={`slot-pill ${s.booked ? 'booked' : ''}`} key={s.id}>{timeLabel(s.starts_at)}{s.booked ? ' · booked' : <button onClick={() => del(s)} aria-label={`Remove ${timeLabel(s.starts_at)}`}><Trash2 size={12} /></button>}</span>)}</div></div>)}</div></div>;
}

/* ---------- A single visit (what the patient sent) + prescribing ---------- */
export function VisitDetail({ id, onClose, flash, go, onNote }) {
  const [v, setV] = useState(null); const [light, setLight] = useState(null);
  const load = useCallback(() => request(`/api/appointments/${id}`).then(setV).catch(e => { flash(e.message, 'error'); onClose(); }), [id]); // eslint-disable-line
  useEffect(() => { load(); }, [load]);
  return <Modal title={v ? `Visit with ${v.patient}` : 'Visit'} onClose={onClose} wide>{!v ? <p className="muted">Loading…</p> : <div className="visit-detail">
    <div className="visit-top"><TierChip tier={v.tier} /><span className={statusClass(v.status)}>{label(v.status)}</span><span className="muted">{dateTime(v.starts_at)}</span></div>
    {v.locked ? <p className="muted">The patient’s message and photos unlock when the visit is paid.</p> : <>
      <h4>What the patient says</h4><p className="patient-says">“{v.reason || 'No message.'}”</p>
      {!!v.photo_ids.length && <><h4>Photos</h4><div className="photo-grid">{v.photo_ids.map(pid => <AuthImg key={pid} url={`/api/media/${pid}`} onOpen={setLight} />)}</div></>}
      {v.patient_profile && Object.values(v.patient_profile).some(Boolean) && <><h4>Patient’s saved details</h4><dl className="profile-dl">{[['Date of birth', 'date_of_birth'], ['Sex', 'sex'], ['Skin type', 'skin_type'], ['Allergies', 'allergies'], ['Using now', 'medications'], ['History', 'history'], ['Language', 'language']].filter(([, k]) => v.patient_profile[k]).map(([l, k]) => <React.Fragment key={k}><dt>{l}</dt><dd>{v.patient_profile[k]}</dd></React.Fragment>)}</dl></>}
      {!!v.prescriptions?.length && <><h4>Prescribed so far</h4>{v.prescriptions.map(p => <p className="rx-line" key={p.id}><Pill size={14} /> {p.medicine} · {p.dose} · {p.frequency} · {p.duration}</p>)}</>}
      <PrescriptionForm aid={v.id} flash={flash} onIssued={load} />
      <div className="button-row">{v.chat_open && v.conversation_id && <button className="btn btn-outline" onClick={() => { onClose(); go(`messages/${v.conversation_id}`); }}><MessageCircle size={15} /> Open chat</button>}<button className="btn btn-outline" onClick={() => { onClose(); onNote(v.id); }}><FileText size={15} /> Clinical note</button></div></>}
    <Lightbox src={light} onClose={() => setLight(null)} /></div>}</Modal>;
}

/* ---------- My patients (a doctor has many) ---------- */
export function MyPatients({ go, activeId, flash, onNote }) {
  const [rows, setRows] = useState(null); const [q, setQ] = useState('');
  useEffect(() => { request('/api/clinician/patients').then(setRows).catch(() => setRows([])); }, []);
  if (activeId) return <PatientPage id={activeId} go={go} flash={flash} onNote={onNote} />;
  if (rows && !rows.length) return <EmptyState icon={Users} title="No patients yet" text="Patients appear here after they pay for a visit with you. Make sure your profile is approved and you have published times." action={() => go('availability')} actionText="Publish times" />;
  const shown = (rows || []).filter(r => r.name.toLowerCase().includes(q.toLowerCase()));
  return <div className="section-stack"><div className="section-head"><div><h2>My patients</h2><p>Everyone who has paid for a visit with you.</p></div><div className="searchbox"><input placeholder="Search by name" value={q} onChange={e => setQ(e.target.value)} aria-label="Search patients" /></div></div>
    {shown.map(r => <article className="panel patient-row" key={r.id} onClick={() => go(`my-patients/${r.id}`)} role="button" tabIndex={0} onKeyDown={e => e.key === 'Enter' && go(`my-patients/${r.id}`)}><Avatar name={r.name} size={46} /><div className="patient-main"><strong>{r.name}</strong><span className="muted">{r.visits.length} visit{r.visits.length > 1 ? 's' : ''} · last {dateTime(r.last_visit)}</span></div><div className="chip-row">{r.chat_open ? <span className="status green">Chat open</span> : <span className="status amber">No chat</span>}{r.unread > 0 && <b className="badge">{r.unread}</b>}{r.prescriptions > 0 && <span className="chip"><Pill size={12} /> {r.prescriptions}</span>}</div><ArrowRight size={18} /></article>)}</div>;
}
function PatientPage({ id, go, flash, onNote }) {
  const [d, setD] = useState(null); const [light, setLight] = useState(null); const [rxFor, setRxFor] = useState('');
  const load = useCallback(() => request(`/api/clinician/patients/${id}`).then(setD).catch(e => { flash(e.message, 'error'); go('my-patients'); }), [id]); // eslint-disable-line
  useEffect(() => { load(); }, [load]);
  if (!d) return <div className="panel"><p className="muted">Loading…</p></div>;
  return <div className="section-stack"><button className="text-button back-link" onClick={() => go('my-patients')}>← All patients</button>
    <div className="panel patient-head"><Avatar name={d.name} size={64} /><div><h2>{d.name}</h2><p className="muted">{d.visits.length} visit{d.visits.length > 1 ? 's' : ''} with you</p></div>
      <div className="button-row">{d.chat_open && d.conversation_id ? <button className="btn btn-primary" onClick={() => go(`messages/${d.conversation_id}`)}><MessageCircle size={16} /> Message{d.unread ? ` (${d.unread})` : ''}</button> : <span className="status amber">Chat not included in their plan</span>}</div></div>
    {Object.values(d.profile).some(Boolean) && <div className="panel"><h3>Saved details</h3><dl className="profile-dl">{[['Date of birth', 'date_of_birth'], ['Sex', 'sex'], ['Skin type', 'skin_type'], ['Allergies', 'allergies'], ['Using now', 'medications'], ['History', 'history'], ['Language', 'language']].filter(([, k]) => d.profile[k]).map(([l, k]) => <React.Fragment key={k}><dt>{l}</dt><dd>{d.profile[k]}</dd></React.Fragment>)}</dl></div>}
    {d.visits.map(v => <article className="panel visit-card" key={v.id}><div className="visit-top"><TierChip tier={v.tier} /><span className={statusClass(v.status)}>{label(v.status)}</span><span className="muted">{dateTime(v.starts_at)}</span></div>
      <p className="patient-says">“{v.reason || 'No message.'}”</p>{!!v.photo_ids.length && <div className="photo-grid">{v.photo_ids.map(pid => <AuthImg key={pid} url={`/api/media/${pid}`} onOpen={setLight} />)}</div>}
      {v.prescriptions.map(p => <p className="rx-line" key={p.id}><Pill size={14} /> {p.medicine} · {p.dose} · {p.frequency} · {p.duration}</p>)}
      <div className="button-row"><button className="btn btn-outline btn-sm" onClick={() => setRxFor(rxFor === v.id ? '' : v.id)}><Pill size={14} /> {rxFor === v.id ? 'Close' : 'Write prescription'}</button><button className="btn btn-ghost btn-sm" onClick={() => onNote(v.id)}><FileText size={14} /> Clinical note</button></div>
      {rxFor === v.id && <PrescriptionForm aid={v.id} flash={flash} onIssued={() => { setRxFor(''); load(); }} />}</article>)}
    <Lightbox src={light} onClose={() => setLight(null)} /></div>;
}

/* ---------- Doctor overview ---------- */
export function DoctorOverview({ user, appointments, unread, go, onRoom }) {
  const status = user.doctor_status || 'draft'; const [title, text, tone] = STATUS_COPY[status] || STATUS_COPY.draft;
  const upcoming = appointments.filter(a => a.tier !== 'chat' && ['confirmed', 'checked_in', 'in_consultation'].includes(a.status)).sort((a, b) => new Date(a.starts_at) - new Date(b.starts_at));
  const [patients, setPatients] = useState([]);
  useEffect(() => { if (status === 'approved') request('/api/clinician/patients').then(setPatients).catch(() => {}); }, [status]);
  return <div className="section-stack">
    <div className={`approval-banner ${tone}`}>{status === 'approved' ? <ShieldCheck size={22} /> : <AlertCircle size={22} />}<div><strong>{title}</strong><p>{text}</p></div>{status !== 'approved' && <button className="btn btn-primary btn-sm" onClick={() => go('my-profile')}>{status === 'pending' ? 'View profile' : 'Open my profile'}</button>}</div>
    {status === 'approved' && <><div className="stat-grid"><Stat icon={CalendarDays} label="Upcoming visits" value={upcoming.length} caption="Paid and confirmed" tone="blue" /><Stat icon={Users} label="My patients" value={patients.length} caption="Many patients, one doctor" tone="green" /><Stat icon={MessageCircle} label="Unread messages" value={unread} caption="From chat patients" tone="purple" /><Stat icon={Check} label="Completed" value={appointments.filter(a => a.status === 'completed').length} caption="Signed visits" tone="orange" /></div>
      <div className="panel"><div className="panel-heading"><div><h2>Next visits</h2><p>Your upcoming video consultations.</p></div><button className="text-button" onClick={() => go('appointments')}>All appointments <ArrowRight size={15} /></button></div>
        {upcoming.slice(0, 4).map(a => <div className="appointment-row" key={a.id}><div className="appointment-info"><strong>{a.patient}</strong><span>{dateTime(a.starts_at)} · {a.tier_label}</span></div><button className="btn btn-primary btn-sm" disabled={new Date(a.starts_at) - Date.now() > 600000} onClick={() => onRoom(a.id)}><Video size={15} /> Join</button></div>)}
        {!upcoming.length && <EmptyState icon={CalendarDays} title="No upcoming visits" text="Publish more times so patients can book you." action={() => go('availability')} actionText="Publish times" />}</div></>}
  </div>;
}
