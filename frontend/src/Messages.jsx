import React, { useCallback, useEffect, useRef, useState } from 'react';
import { ArrowLeft, Camera, Clock3, Lock, MessageCircle, Send, X } from 'lucide-react';
import { request, store, prepareImage, dateTime, timeAgo, timeLabel, dayLabel, dayKey } from './lib.js';
import { Avatar, AuthImg, EmptyState, Lightbox } from './ui.jsx';

/* Messages for both patient and doctor. One thread per patient-doctor pair; open only while a paid Video + chat visit is in its window. */
export default function Messages({ user, activeId, go, flash, refreshUnread }) {
  const [convs, setConvs] = useState(null);
  const loadList = useCallback(() => request('/api/conversations').then(d => { setConvs(d); refreshUnread && refreshUnread(d.reduce((n, c) => n + c.unread, 0)); }).catch(e => flash(e.message, 'error')), [flash, refreshUnread]);
  useEffect(() => { loadList(); const t = setInterval(() => { if (!document.hidden) loadList(); }, 15000); return () => clearInterval(t); }, [loadList]);
  const current = convs?.find(c => String(c.id) === String(activeId));
  const isDoctor = user.role === 'clinician';
  if (convs && !convs.length) return <EmptyState icon={MessageCircle} title="No conversations yet" text={isDoctor ? 'When a patient books your Video + chat plan and pays, a conversation opens here.' : 'Chat comes with the Video + chat plan. Book a visit with that plan and you can message your doctor and send photos for 30 days.'} action={isDoctor ? undefined : () => go('find-a-doctor')} actionText="Find a doctor" />;
  return <div className={`messages-layout ${current ? 'has-thread' : ''}`}>
    <aside className="conv-list" aria-label="Conversations">{(convs || []).map(c => <button key={c.id} className={`conv-item ${String(c.id) === String(activeId) ? 'on' : ''}`} onClick={() => go(`messages/${c.id}`)}>
      <Avatar name={c.other_name} url={c.other_photo} size={44} /><span className="conv-text"><strong>{c.other_name}</strong><small>{c.last || (c.chat_open ? 'Say hello' : 'Chat closed')}</small></span><span className="conv-meta">{c.last_at && <small>{timeAgo(c.last_at)}</small>}{c.unread > 0 && <b className="badge">{c.unread}</b>}{!c.chat_open && <Lock size={13} />}</span></button>)}</aside>
    <section className="thread">{current ? <Thread key={current.id} conv={current} user={user} back={() => go('messages')} flash={flash} onChange={loadList} /> : <div className="thread-empty"><MessageCircle size={32} /><p>Select a conversation</p></div>}</section>
  </div>;
}

function Thread({ conv, user, back, flash, onChange }) {
  const [msgs, setMsgs] = useState([]); const [meta, setMeta] = useState(conv);
  const [text, setText] = useState(() => store.get(`tena_draft_chat_${conv.id}`, '')); const [photo, setPhoto] = useState(null); const [sending, setSending] = useState(false); const [light, setLight] = useState(null);
  const lastId = useRef(0); const end = useRef(null); const fileInput = useRef(null);
  const previewUrl = React.useMemo(() => (photo ? URL.createObjectURL(photo) : null), [photo]);
  useEffect(() => () => previewUrl && URL.revokeObjectURL(previewUrl), [previewUrl]);
  const pull = useCallback(async first => {
    try {
      const d = await request(`/api/conversations/${conv.id}/messages?after=${first ? 0 : lastId.current}`); setMeta(d.conversation);
      if (d.messages.length) { lastId.current = d.messages[d.messages.length - 1].id; setMsgs(m => (first ? d.messages : [...m, ...d.messages.filter(x => !m.some(y => y.id === x.id))])); onChange(); }
    } catch (e) { if (first) flash(e.message, 'error'); }
  }, [conv.id]); // eslint-disable-line
  useEffect(() => { lastId.current = 0; pull(true); const t = setInterval(() => { if (!document.hidden) pull(false); }, 5000); return () => clearInterval(t); }, [pull]);
  useEffect(() => { end.current?.scrollIntoView({ block: 'end' }); }, [msgs.length]);
  useEffect(() => { store.set(`tena_draft_chat_${conv.id}`, text); }, [text, conv.id]);   // a half-written message survives a refresh
  const attach = async e => { const f = e.target.files?.[0]; e.target.value = ''; if (!f) return; try { setPhoto(await prepareImage(f)); } catch (x) { flash(x.message, 'error'); } };
  const send = async e => {
    e?.preventDefault(); if ((!text.trim() && !photo) || sending) return; setSending(true);
    try {
      const fd = new FormData(); fd.append('body', text.trim()); if (photo) fd.append('photo', photo);
      const d = await request(`/api/conversations/${conv.id}/messages`, { method: 'POST', body: fd });
      lastId.current = Math.max(lastId.current, d.message.id); setMsgs(m => [...m, d.message]); setText(''); setPhoto(null); store.del(`tena_draft_chat_${conv.id}`); onChange();
    } catch (x) { flash(x.message, 'error'); await pull(false); } finally { setSending(false); }
  };
  let lastDay = '';
  return <>
    <header className="thread-head"><button className="icon-btn thread-back" onClick={back} aria-label="Back to conversations"><ArrowLeft /></button><Avatar name={meta.other_name} url={meta.other_photo} size={42} /><div><strong>{meta.other_name}</strong><small>{meta.specialty || 'Patient'}</small></div>
      <span className={`window-chip ${meta.chat_open ? 'open' : ''}`}>{meta.chat_open ? <><Clock3 size={13} /> Open until {dateTime(meta.chat_until)}</> : <><Lock size={13} /> Closed</>}</span></header>
    <div className="thread-body" aria-live="polite">{!msgs.length && <p className="thread-hint">{user.role === 'patient' ? 'Write to your doctor about how your skin is doing. You can attach photos.' : 'No messages yet. Say hello to your patient.'}</p>}
      {msgs.map(m => { const dk = dayKey(m.at); const show = dk !== lastDay; lastDay = dk; return <React.Fragment key={m.id}>{show && <div className="day-sep">{dayLabel(m.at)}</div>}
        <div className={`msg ${m.mine ? 'mine' : 'theirs'}`}><div className="msg-bubble">{m.image_url && <AuthImg url={m.image_url} onOpen={setLight} />}{m.body && <p>{m.body}</p>}<small>{timeLabel(m.at)}{m.mine && m.read ? ' · seen' : ''}</small></div></div></React.Fragment>; })}<div ref={end} /></div>
    {meta.chat_open ? <form className="thread-compose" onSubmit={send}>
      {previewUrl && <div className="compose-preview"><img src={previewUrl} alt="Photo to send" /><button type="button" onClick={() => setPhoto(null)} aria-label="Remove photo"><X size={14} /></button></div>}
      <div className="compose-row"><input ref={fileInput} type="file" accept="image/*" hidden onChange={attach} /><button type="button" className="icon-btn" onClick={() => fileInput.current?.click()} aria-label="Attach a photo" title="Attach a photo"><Camera /></button>
        <textarea rows={1} value={text} maxLength={2000} placeholder="Write a message…" onChange={e => setText(e.target.value)} onKeyDown={e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } }} />
        <button className="btn btn-primary icon-send" disabled={sending || (!text.trim() && !photo)} aria-label="Send"><Send size={17} /></button></div>
      <small className="compose-note">Photos are private to you and your {user.role === 'patient' ? 'doctor' : 'patient'}. Not for emergencies: call 907.</small></form>
      : <div className="thread-closed"><Lock size={16} /> {user.role === 'patient' ? 'This chat is closed. Book another Video + chat visit to message this doctor again. Your earlier messages stay here.' : 'The chat window for this patient has closed.'}</div>}
    <Lightbox src={light} onClose={() => setLight(null)} /></>;
}
