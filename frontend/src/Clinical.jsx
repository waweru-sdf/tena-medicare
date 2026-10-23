import React, { useEffect, useState } from 'react';
import { BookOpen, FileText, Languages, ShieldCheck, Sparkles } from 'lucide-react';
import { request, dateTime } from './lib.js';
import { PrescriptionForm } from './Extra.jsx';

const usable = a => ['confirmed', 'checked_in', 'in_consultation', 'completed'].includes(a.status);

/* Notes and AI helpers. Everything the AI writes is a draft the doctor must read and sign. */
function useNote(flash, initialId) {
  const [noteId, setNoteId] = useState(initialId || ''); const [note, setNote] = useState({ assessment: '', plan: '', summary_for_patient: '' }); const [aiResult, setAiResult] = useState(''); const [busy, setBusy] = useState(false);
  const run = async (fn, ok) => { setBusy(true); try { const r = await fn(); if (ok) flash(ok); return r; } catch (e) { flash(e.message, 'error'); return null; } finally { setBusy(false); } };
  const loadNote = async id => { setNoteId(id); setAiResult(''); if (!id) return; const d = await run(() => request(`/api/appointments/${id}/note`)); if (d) setNote({ assessment: d.assessment || '', plan: d.plan || '', summary_for_patient: d.summary_for_patient || '' }); };
  useEffect(() => { if (initialId) loadNote(initialId); }, [initialId]); // eslint-disable-line
  const callAi = async (path, body, aid = noteId) => { if (!aid) return flash('Select a visit first.', 'error'); const d = await run(() => request(`/api/appointments/${aid}/ai/${path}`, { method: 'POST', body: JSON.stringify(body) })); if (d) setAiResult(d.draft ? (typeof d.draft === 'string' ? d.draft : JSON.stringify(d.draft, null, 2)) : d.text || (d.questions || []).join('\n• ')); };
  return { noteId, note, setNote, aiResult, setAiResult, busy, run, loadNote, callAi };
}
const VisitSelect = ({ appointments, value, onChange }) => <label>Visit<select value={value} onChange={e => onChange(e.target.value)} required><option value="">Select visit</option>{appointments.filter(usable).map(a => <option key={a.id} value={a.id}>{a.patient} · {dateTime(a.starts_at)} · {a.status.replaceAll('_', ' ')}</option>)}</select></label>;

export function ClinicalNotes({ appointments, flash, initialId, reload }) {
  const n = useNote(flash, initialId);
  const save = async e => { e.preventDefault(); if (!n.noteId) return flash('Select a visit first.', 'error'); const d = await n.run(() => request(`/api/appointments/${n.noteId}/note`, { method: 'PUT', body: JSON.stringify(n.note) }), 'Draft saved.'); if (d) flash('Draft saved. Signing needs a paid visit and your final review.'); };
  return <div className="two-column"><div className="panel form-panel"><div className="panel-title"><FileText /><div><h2>Clinical notes</h2><p>Save drafts, then review and sign only when you are sure.</p></div></div>
    <form className="form-stack" onSubmit={save}><VisitSelect appointments={appointments} value={n.noteId} onChange={n.loadNote} />
      <label>Assessment<textarea rows={3} value={n.note.assessment} onChange={e => n.setNote({ ...n.note, assessment: e.target.value })} /></label>
      <label>Plan<textarea rows={3} value={n.note.plan} onChange={e => n.setNote({ ...n.note, plan: e.target.value })} /></label>
      <label>Plain-language summary for the patient<textarea rows={3} value={n.note.summary_for_patient} onChange={e => n.setNote({ ...n.note, summary_for_patient: e.target.value })} /></label>
      <div className="button-row"><button className="btn btn-primary" disabled={n.busy}>Save draft</button><button type="button" className="btn btn-outline" onClick={() => n.callAi('draft-note', { rough: [n.note.assessment, n.note.plan, n.note.summary_for_patient].filter(Boolean).join('\n'), patient_consent: window.confirm('Has the patient agreed to AI-assisted note drafting?') })}><Sparkles size={16} /> Tidy with AI</button></div></form></div>
    <div className="panel blue-panel"><div className="illustration-icon"><ShieldCheck /></div><h2>The clinical decision is yours</h2><p>AI output is a draft. Check it, edit it and follow your professional standards before signing.</p><div className="ai-result">{n.aiResult || 'AI drafts appear here for review.'}</div>
      {n.noteId && <PrescriptionForm aid={n.noteId} flash={flash} />}
      <button className="btn btn-outline btn-full" onClick={async () => { if (!n.noteId || !window.confirm('Sign and finalize this note? This is permanent.')) return; const d = await n.run(() => request(`/api/appointments/${n.noteId}/note/finalize`, { method: 'POST', body: '{}' }), 'Note signed.'); if (d) reload(); }}>Review and sign note</button></div></div>;
}

export function Copilot({ appointments, flash }) {
  const n = useNote(flash); const [q, setQ] = useState(''); const [audio, setAudio] = useState(null);
  const consent = what => window.confirm(`Has the patient agreed to ${what}?`);
  const transcribe = async () => { if (!n.noteId || !audio) return flash('Choose a visit and an audio file first.', 'error'); const fd = new FormData(); fd.append('audio', audio); fd.append('patient_consent', consent('transcription') ? 'true' : 'false'); const d = await n.run(() => request(`/api/appointments/${n.noteId}/ai/transcribe`, { method: 'POST', body: fd })); if (d?.transcript) n.setAiResult(d.transcript); };
  return <div className="two-column"><div className="panel form-panel"><div className="panel-title"><Sparkles /><div><h2>Doctor’s assistant</h2><p>Organise notes and prepare questions. It never diagnoses or prescribes.</p></div></div>
    <form className="form-stack" onSubmit={e => { e.preventDefault(); n.callAi('copilot', { question: q, notes: n.note.assessment + '\n' + n.note.plan, patient_consent: consent('AI assistance') }); }}><VisitSelect appointments={appointments} value={n.noteId} onChange={n.loadNote} />
      <label>What would you like help organising?<textarea rows={5} value={q} onChange={e => setQ(e.target.value)} placeholder="e.g. list what is missing from my notes on this eczema follow-up" required /></label>
      <div className="button-row"><button className="btn btn-primary"><Sparkles size={16} /> Ask</button><button type="button" className="btn btn-outline" onClick={() => n.callAi('suggested-questions', { context: q, patient_consent: consent('AI assistance') })}>Suggest questions</button><button type="button" className="btn btn-outline" onClick={() => n.callAi('post-call-actions', { notes: q, patient_consent: consent('AI assistance') })}>Post-visit actions</button><button type="button" className="btn btn-outline" onClick={() => n.callAi('translate', { text: q, language: 'Amharic', patient_consent: consent('translation') })}><Languages size={15} /> To Amharic</button></div></form></div>
    <div className="panel"><h2>Draft output</h2><p className="muted">Review everything before using it in patient care.</p><div className="ai-result large">{n.aiResult || 'Your draft will show here.'}</div>
      <button className="btn btn-outline" onClick={() => navigator.clipboard?.writeText(n.aiResult).then(() => flash('Copied.')).catch(() => flash('Could not copy.', 'error'))}>Copy draft</button>
      <div className="form-stack audio-transcribe"><label>Transcribe a consented audio recording<input type="file" accept="audio/*" onChange={e => setAudio(e.target.files?.[0] || null)} /></label><button className="btn btn-outline" onClick={transcribe} disabled={n.busy || !audio}><FileText size={15} /> Transcribe</button><small>Needs OpenAI transcription configured. Audio is not kept.</small></div>
      <div className="reference-links"><h3>Reference starting points</h3><button onClick={async () => { const d = await n.run(() => request('/api/ai/reference-lookup')); if (d) n.setAiResult(d.references.map(r => `${r.title}\n${r.url}\n${r.scope}`).join('\n\n')); }}>Open curated references <BookOpen size={15} /></button></div></div></div>;
}
