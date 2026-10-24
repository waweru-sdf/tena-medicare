import React, { useEffect, useRef, useState } from 'react';
import { Sparkles, Send, X, MessageCircle, Check, RefreshCw, ArrowRight } from 'lucide-react';
import { request, money, dateTime, publicUrl } from './lib.js';
import { Avatar, Modal } from './ui.jsx';

const useLoad = (path, deps = []) => {
  const [data, setData] = useState(null);
  const reload = () => request(path).then(setData).catch(() => setData([]));
  useEffect(() => { reload(); }, deps); // eslint-disable-line
  return [data, reload];
};
const Status = ({ s }) => <span className={`status ${['resolved', 'paid', 'approved', 'refunded'].includes(s) ? 'green' : ['rejected', 'failed'].includes(s) ? 'red' : 'amber'}`}>{String(s).replaceAll('_', ' ')}</span>;

/* ---------- Chatbot (OpenAI or Anthropic, chosen on the server) ---------- */
const WELCOME = 'Hello! I am the Tena Medicine skin-care assistant. I can share general skin-care tips, help you choose a doctor and plan, and explain booking and payment. I cannot diagnose or prescribe. / ሰላም! ስለ ቆዳ እንክብካቤ አጠቃላይ መረጃ እና ዶክተር ለመምረጥ መርዳት እችላለሁ።';
export function ChatPanel({ onClose, onDoctor }) {
  const [msgs, setMsgs] = useState(() => { try { return JSON.parse(sessionStorage.getItem('tena_chat')) || [{ role: 'assistant', text: WELCOME }]; } catch { return [{ role: 'assistant', text: WELCOME }]; } });
  const [input, setInput] = useState(''); const [busy, setBusy] = useState(false); const end = useRef(null);
  useEffect(() => { try { sessionStorage.setItem('tena_chat', JSON.stringify(msgs.slice(-30))); } catch {} end.current?.scrollIntoView({ block: 'end' }); }, [msgs, busy]);
  const send = async text => {
    const q = (text ?? input).trim(); if (!q || busy) return;
    const history = msgs.map(m => ({ role: m.role, content: m.text })); setMsgs(m => [...m, { role: 'user', text: q }]); setInput(''); setBusy(true);
    try { const d = await request('/api/ai/assistant', { method: 'POST', body: JSON.stringify({ question: q, history }) }); setMsgs(m => [...m, { role: 'assistant', text: d.answer, urgent: d.emergency, doctors: d.doctors }]); }
    catch (e) { setMsgs(m => [...m, { role: 'assistant', text: e.message, error: true }]); }
    setBusy(false);
  };
  return <div className="chatbot">
    <div className="assistant-heading"><div className="ai-avatar"><Sparkles/></div><div><h2>Skin-care assistant</h2><p>General skin tips and doctor matching. Not a diagnosis.</p></div>{onClose && <button className="icon-btn" onClick={onClose} aria-label="Close chat"><X size={18}/></button>}</div>
    <div className="chat-window" aria-live="polite">{msgs.map((m, i) => <div className={`chat-row ${m.role}`} key={i}>{m.role === 'assistant' && <div className="chat-avatar"><Sparkles size={15}/></div>}<div className="chat-bubble" style={m.urgent ? { background: '#fdecec', color: '#8a1c1c', fontWeight: 600 } : m.error ? { opacity: .75 } : undefined}>{m.text}{!!m.doctors?.length && <div className="suggest-docs"><small>Doctors who may help:</small>{m.doctors.map(d => <button type="button" key={d.id} onClick={() => onDoctor && onDoctor(d.id)}><Avatar name={d.name} url={d.photo_url} size={26} /> <span>{d.name}<em>{d.specialty}</em></span><ArrowRight size={14} /></button>)}</div>}</div></div>)}
      {busy && <div className="chat-row assistant"><div className="chat-avatar"><Sparkles size={15}/></div><div className="chat-bubble">Typing…</div></div>}<div ref={end}/></div>
    {msgs.length < 3 && <div className="chat-chips">{['I get acne, who should I see?', 'How do I take a good skin photo?', 'Video or video + chat?', 'How do I sunscreen properly?'].map(q => <button key={q} onClick={() => send(q)}>{q}</button>)}</div>}
    <form className="chat-compose" onSubmit={e => { e.preventDefault(); send(); }}><input value={input} onChange={e => setInput(e.target.value)} maxLength={500} placeholder="Ask a question…" aria-label="Your question"/><button className="btn btn-primary icon-send" disabled={busy || !input.trim()} aria-label="Send"><Send size={17}/></button></form>
    <p className="disclaimer">AI-generated. It cannot diagnose or choose treatment: a doctor must look at your skin. In an emergency call 907 or go to the nearest hospital.</p>
  </div>;
}
export function ChatLauncher({ onDoctor }) {
  const [open, setOpen] = useState(false); const [on, setOn] = useState(false);
  useEffect(() => { request('/api/ai/status').then(d => setOn(d.assistant)).catch(() => {}); }, []);
  if (!on) return null;
  return <>{open && <div className="chat-float"><ChatPanel onClose={() => setOpen(false)} onDoctor={id => { setOpen(false); onDoctor && onDoctor(id); }}/></div>}
    <button className="chat-fab" onClick={() => setOpen(!open)} aria-label={open ? 'Close assistant' : 'Open assistant'}>{open ? <X/> : <MessageCircle/>}</button></>;
}

/* ---------- Support: patients/clinicians send requests, staff answer ---------- */
export function SupportDesk({ flash }) {
  const [f, setF] = useState({ kind: 'support', subject: '', body: '' }); const [busy, setBusy] = useState(false);
  const [list, reload] = useLoad('/api/tickets');
  const draft = async () => { if (!f.body.trim()) return flash('Describe the problem first, then I can tidy it.', 'error'); setBusy(true); try { const d = await request('/api/ai/ticket-draft', { method: 'POST', body: JSON.stringify({ issue: f.body }) }); setF({ ...f, subject: d.draft.subject || f.subject, body: d.draft.body || f.body }); flash('Draft ready. Check it, then send.'); } catch (e) { flash(e.message, 'error'); } setBusy(false); };
  const send = async e => { e.preventDefault(); setBusy(true); try { await request('/api/tickets', { method: 'POST', body: JSON.stringify(f) }); setF({ kind: 'support', subject: '', body: '' }); flash('Request sent. Replies appear below.'); reload(); } catch (e2) { flash(e2.message, 'error'); } setBusy(false); };
  return <div className="two-column"><div className="panel form-panel"><h2>Contact support</h2><p className="muted">Never include passwords, secret keys or card numbers. For payment issues add your payment reference.</p>
    <form className="form-stack" onSubmit={send}><label>What do you need?<select value={f.kind} onChange={e => setF({ ...f, kind: e.target.value })}><option value="support">Help with booking or payment</option><option value="complaint">Make a complaint</option><option value="data_request">Access, correct or delete my data</option></select></label>
      <label>Subject<input value={f.subject} onChange={e => setF({ ...f, subject: e.target.value })} required maxLength={150}/></label>
      <label>Details<textarea rows={5} value={f.body} onChange={e => setF({ ...f, body: e.target.value })} maxLength={2000}/></label>
      <div className="button-row"><button className="btn btn-primary" disabled={busy}><Send size={15}/> Send request</button><button type="button" className="btn btn-outline" onClick={draft} disabled={busy}><Sparkles size={15}/> Tidy with AI</button></div></form></div>
    <div className="panel"><h2>Your requests</h2>{list?.length === 0 && <p className="muted">No requests yet.</p>}{list?.map(t => <div className="queue-item" key={t.id}><strong>{t.subject}</strong> <Status s={t.status}/>{t.due_at && <small className="muted"> · reply due {dateTime(t.due_at)}</small>}{t.resolution && <p>{t.resolution}</p>}</div>)}</div></div>;
}
export function TicketQueue({ flash }) {
  const [list, reload] = useLoad('/api/tickets'); const [res, setRes] = useState({});
  const set = async (t, status) => { try { await request(`/api/tickets/${t.id}`, { method: 'PUT', body: JSON.stringify({ status, resolution: res[t.id] ?? t.resolution ?? '' }) }); flash('Ticket updated.'); reload(); } catch (e) { flash(e.message, 'error'); } };
  return <div className="section-stack">{list?.length === 0 && <p className="muted">No tickets right now.</p>}{list?.map(t => <article className="panel" key={t.id}><div className="record-top"><strong>{t.subject}</strong><span><Status s={t.kind}/> <Status s={t.status}/></span></div><p className="muted">{t.who} · {dateTime(t.created)}{t.due_at && ` · due ${dateTime(t.due_at)}`}</p><p>{t.body}</p>
    <textarea rows={2} placeholder="Reply / resolution note" defaultValue={t.resolution || ''} onChange={e => setRes({ ...res, [t.id]: e.target.value })}/><div className="button-row"><button className="btn btn-outline btn-sm" onClick={() => set(t, 'in_progress')}>In progress</button><button className="btn btn-primary btn-sm" onClick={() => set(t, 'resolved')}><Check size={14}/> Resolve</button></div></article>)}</div>;
}

/* ---------- Admin ---------- */
export function ClinicianReview({ flash }) {
  const [list, reload] = useLoad('/api/admin/clinicians'); const [tab, setTab] = useState('pending'); const [reject, setReject] = useState(null); const [reason, setReason] = useState(''); const [open, setOpen] = useState(null);
  const decide = async (id, decision, why = '') => { try { await request(`/api/admin/clinicians/${id}/review`, { method: 'POST', body: JSON.stringify({ decision, reason: why }) }); flash(decision === 'approved' ? 'Doctor approved. They can now add availability and patients can find them.' : 'Doctor rejected. They will see your reason and can resubmit.'); setReject(null); setReason(''); reload(); } catch (e) { flash(e.message, 'error'); } };
  const shown = (list || []).filter(c => tab === 'all' || c.status === tab); const count = s => (list || []).filter(c => c.status === s).length;
  return <div className="section-stack"><p className="muted">Check each registration number with the regulator before approving. Only approved doctors are shown to patients.</p>
    <div className="tab-row">{[['pending', 'Waiting for review'], ['approved', 'Approved'], ['rejected', 'Rejected'], ['draft', 'Still writing profile'], ['all', 'All']].map(([k, l]) => <button key={k} className={tab === k ? 'on' : ''} onClick={() => setTab(k)}>{l}{k !== 'all' && <b>{count(k)}</b>}</button>)}</div>
    {list && shown.length === 0 && <p className="muted">Nobody here right now.</p>}
    {shown.map(c => <article className="panel review-card" key={c.id}>
      <div className="review-head"><Avatar name={c.name} url={c.photo_url} size={64} round={false} /><div className="review-id"><strong>{c.name}</strong> <Status s={c.status}/><br/><span>{c.specialty}{c.years_exp ? ` · ${c.years_exp} years` : ''} · {c.language}</span><br/><small className="muted">{c.email} · Licence: <b>{c.reg_number || 'not given'}</b>{c.submitted_at && ` · submitted ${dateTime(c.submitted_at)}`}</small></div></div>
      {c.bio ? <p className="review-bio">{open === c.id ? c.bio : c.bio.slice(0, 220) + (c.bio.length > 220 ? '…' : '')}{c.bio.length > 220 && <button className="text-button" onClick={() => setOpen(open === c.id ? null : c.id)}>{open === c.id ? 'less' : 'more'}</button>}</p> : <p className="muted">No description yet.</p>}
      <div className="chip-row">{(c.conditions || '').split(',').filter(x => x.trim()).map(x => <span className="chip" key={x}>{x.trim()}</span>)}</div>
      <p className="muted">Prices set by admin: chat {money(c.price_chat)} · video + chat {money(c.price_video_chat)}</p>
      {c.review_reason && c.status === 'rejected' && <p className="review-reason">Reason given: {c.review_reason}</p>}
      {!!c.missing?.length && c.status !== 'approved' && <p className="review-missing">Missing: {c.missing.join('; ')}</p>}
      <div className="button-row"><button className="btn btn-primary btn-sm" disabled={c.status === 'approved' || c.status === 'draft'} onClick={() => decide(c.id, 'approved')}><Check size={14}/> Approve</button><button className="btn btn-outline btn-sm" disabled={c.status === 'rejected' || c.status === 'draft'} onClick={() => { setReject(c); setReason(''); }}>Reject…</button></div>
    </article>)}
    {reject && <Modal title={`Reject ${reject.name}?`} onClose={() => setReject(null)}><form className="form-stack" onSubmit={e => { e.preventDefault(); decide(reject.id, 'rejected', reason); }}><label>Tell them what to fix (they will see this)<textarea rows={4} required value={reason} onChange={e => setReason(e.target.value)} placeholder="e.g. The licence number could not be verified. Please check it and resubmit."/></label><div className="button-row"><button className="btn btn-primary">Reject and send reason</button><button type="button" className="btn btn-ghost" onClick={() => setReject(null)}>Cancel</button></div></form></Modal>}</div>;
}
export function FinancePanel({ flash }) {
  const [pay, reloadPay] = useLoad('/api/finance/payouts'); const [ref, reloadRef] = useLoad('/api/finance/refunds');
  const act = async (url, ok) => { try { await request(url, { method: 'POST', body: '{}' }); flash(ok); reloadPay(); reloadRef(); } catch (e) { flash(e.message, 'error'); } };
  return <div className="two-column"><div className="panel"><h2>Refunds waiting for Chapa</h2><p className="muted">A refund stays here until Chapa confirms the money went back.</p>{ref?.length === 0 && <p className="muted">None. 🎉</p>}
    {ref?.map(r => <div className="queue-item record-top" key={r.id}><span>{money(r.amount_etb)} · <small className="muted">{r.ref}</small></span><span className="button-row"><button className="btn btn-outline btn-sm" onClick={() => act(`/api/finance/refunds/${r.id}/retry`, r.can_check ? 'Checked with Chapa.' : 'Refund requested again.')}><RefreshCw size={13}/> {r.can_check ? 'Check status' : 'Retry'}</button><button className="btn btn-ghost btn-sm" onClick={() => window.confirm('Only do this after confirming the refund in your Chapa dashboard.') && act(`/api/finance/refunds/${r.id}/mark-refunded`, 'Marked as refunded.')}>Mark refunded</button></span></div>)}</div>
    <div className="panel"><h2>Clinician payouts</h2>{pay?.length === 0 && <p className="muted">Payouts appear after notes are signed.</p>}
      {pay?.map(p => <div className="queue-item record-top" key={p.id}><span><strong>{p.clinician}</strong><br/><small className="muted">{money(p.net)} net (commission {money(p.commission)})</small></span>{p.status === 'pending' ? <button className="btn btn-primary btn-sm" onClick={() => act(`/api/finance/payouts/${p.id}/paid`, 'Marked paid.')}>Mark paid</button> : <Status s="paid"/>}</div>)}</div></div>;
}
const SWITCHES = { ai_assistant: 'Patient skin-care assistant', ai_notes: 'Clinician AI (note drafts, copilot, questions, post-call, transcription)', ai_translate: 'Translation', ai_ticket_drafts: 'Support-ticket drafting', ai_analytics: 'AI analytics summary' };
const NUMBERS = { chat_days: 'Chat stays open after payment or after the video visit (days)', commission_pct: 'Platform commission (%)', cancel_hours: 'Free cancel window (hours before visit)', reminder_hours: 'Reminder (hours before visit)', hold_minutes: 'Unpaid slot hold (minutes)' };
export function SettingsPanel({ flash }) {
  const [c, setC] = useState(null); const [st, setSt] = useState({ role: 'support', name: '', email: '', password: '' }); const [ai, setAi] = useState(null);
  useEffect(() => { request('/api/admin/config').then(setC).catch(e => flash(e.message, 'error')); request('/api/ai/status').then(setAi).catch(() => {}); }, []); // eslint-disable-line
  const [test, setTest] = useState(null); const [testing, setTesting] = useState(false);
  if (!c) return null;
  const runTest = async () => { setTesting(true); try { setTest(await request('/api/admin/integrations-test', { method: 'POST', body: '{}' })); } catch (e) { flash(e.message, 'error'); } setTesting(false); };
  const save = async () => { try { setC(await request('/api/admin/config', { method: 'PUT', body: JSON.stringify(c) })); flash('Settings saved.'); } catch (e) { flash(e.message, 'error'); } };
  const addStaff = async e => { e.preventDefault(); try { await request('/api/admin/staff', { method: 'POST', body: JSON.stringify(st) }); flash('Staff account created.'); setSt({ role: 'support', name: '', email: '', password: '' }); } catch (x) { flash(x.message, 'error'); } };
  return <div className="two-column"><div className="panel form-panel form-stack"><h2>Prices (birr)</h2><p className="muted">One price per plan, the same for every doctor. Only you can change these. New bookings use the new price straight away; payments already made are not affected.</p>
    <div className="form-grid"><label>Chat with a doctor<input type="number" min="1" step="1" value={c.price_chat} onChange={e => setC({ ...c, price_chat: e.target.value })}/></label><label>Video consultation + chat<input type="number" min="1" step="1" value={c.price_video_chat} onChange={e => setC({ ...c, price_video_chat: e.target.value })}/></label></div>
    <button className="btn btn-primary" onClick={save}>Save prices</button>
    <h2 style={{ marginTop: 16 }}>AI switches</h2><p className="muted">{ai?.configured ? `Connected to ${ai.provider}.` : `No API key found for ${ai?.provider || 'the AI provider'}: add it to backend/.env and restart.`} Clinical features stay off until your clinical lead approves them.</p>
    {Object.entries(SWITCHES).map(([k, l]) => <label className="switch-row" key={k}><input type="checkbox" checked={c[k] === '1'} onChange={e => setC({ ...c, [k]: e.target.checked ? '1' : '0' })}/> {l}</label>)}
    <button type="button" className="btn btn-outline" onClick={runTest} disabled={testing}><RefreshCw size={15}/> {testing ? 'Testing…' : 'Test connections (AI and Chapa)'}</button>
    {test && <div className="test-result">{[['AI', test.ai], ['Chapa', test.chapa]].map(([n, r]) => <p key={n} className={r.ok ? 'ok' : 'bad'}><b>{n}{r.provider ? ` (${r.provider}${r.model ? ', ' + r.model : ''})` : r.mode ? ` (${r.mode} mode)` : ''}:</b> {r.ok ? 'Connected.' : r.message}</p>)}<p className="muted">Chapa webhook URL: <code>{test.webhook_url}</code></p></div>}
    <h2 style={{ marginTop: 8 }}>Policy</h2>{Object.entries(NUMBERS).map(([k, l]) => <label key={k}>{l}<input type="number" min="0" value={c[k]} onChange={e => setC({ ...c, [k]: e.target.value })}/></label>)}<button className="btn btn-primary" onClick={save}>Save settings</button></div>
    <div className="panel form-panel"><h2>Add a staff member</h2><form className="form-stack" onSubmit={addStaff}><label>Role<select value={st.role} onChange={e => setSt({ ...st, role: e.target.value })}><option value="support">Support agent</option><option value="finance">Finance</option><option value="compliance">Compliance lead</option></select></label>
      <label>Name<input value={st.name} onChange={e => setSt({ ...st, name: e.target.value })} required/></label><label>Email<input type="email" value={st.email} onChange={e => setSt({ ...st, email: e.target.value })} required/></label><label>Temporary password (8+)<input type="password" minLength={8} value={st.password} onChange={e => setSt({ ...st, password: e.target.value })} required/></label><button className="btn btn-primary">Create account</button></form></div></div>;
}

/* ---------- Clinician: prescriptions ---------- */
const HINTS = { medicine: 'e.g. Adapalene 0.1% gel', dose: 'e.g. thin layer', route: 'e.g. topical', frequency: 'e.g. once at night', duration: 'e.g. 8 weeks', quantity: 'e.g. 1 tube (30 g)', instructions: 'e.g. avoid sun, use sunscreen' };
export function PrescriptionForm({ aid, flash, onIssued }) {
  const empty = { medicine: '', dose: '', route: '', frequency: '', duration: '', quantity: '', instructions: '' }; const [rx, setRx] = useState(empty);
  const submit = async e => { e.preventDefault(); try { await request(`/api/appointments/${aid}/prescriptions`, { method: 'POST', body: JSON.stringify(rx) }); flash('Prescription sent to the patient.'); setRx(empty); onIssued && onIssued(); } catch (x) { flash(x.message, 'error'); } };
  return <form className="form-stack rx-form" onSubmit={submit}><h3>Prescription</h3><div className="form-grid">{Object.keys(empty).map(k => <label key={k}>{k[0].toUpperCase() + k.slice(1)}<input value={rx[k]} placeholder={HINTS[k]} onChange={e => setRx({ ...rx, [k]: e.target.value })} required={k !== 'instructions'}/></label>)}</div><button className="btn btn-outline">Issue prescription</button></form>;
}

/* ---------- small helpers ---------- */
export function HoldTimer({ iso }) {
  const [left, setLeft] = useState(() => new Date(iso) - Date.now());
  useEffect(() => { const t = setInterval(() => setLeft(new Date(iso) - Date.now()), 1000); return () => clearInterval(t); }, [iso]);
  if (left <= 0) return <span className="muted">Reservation expired</span>;
  return <span className="hold-timer">Pay within {Math.floor(left / 60000)}:{String(Math.floor(left / 1000) % 60).padStart(2, '0')}</span>;
}
