import React, { useEffect, useState } from 'react';
import { ArrowRight, Languages, ShieldCheck, Stethoscope, X, ImageOff } from 'lucide-react';
import { useBlobUrl, publicUrl, initials, money } from './lib.js';

export const statusClass = s => (['confirmed', 'completed', 'paid', 'approved'].includes((s || '').toLowerCase()) ? 'status green' : ['cancelled', 'failed', 'expired', 'rejected'].includes((s || '').toLowerCase()) ? 'status red' : 'status amber');
export const label = s => String(s || '').replaceAll('_', ' ');

export function Avatar({ name, url, size = 56, round = true }) {
  const style = { width: size, height: size, borderRadius: round ? '50%' : 16, fontSize: size * 0.36 };
  return url ? <img className="avatar-img" style={style} src={publicUrl(url)} alt={name || 'Doctor'} loading="lazy" /> : <span className="avatar-fallback" style={style} aria-hidden="true">{initials(name)}</span>;
}

/* a photo that needs the sign-in header (chat and visit photos) */
export function AuthImg({ url, alt = 'Shared photo', onOpen }) {
  const src = useBlobUrl(url);
  if (src === 'error') return <span className="img-error"><ImageOff size={16} /> Photo unavailable</span>;
  if (!src) return <span className="img-loading" />;
  return <img className="shared-photo" src={src} alt={alt} onClick={() => onOpen && onOpen(src)} />;
}

export function Lightbox({ src, onClose }) {
  useEffect(() => { const f = e => e.key === 'Escape' && onClose(); window.addEventListener('keydown', f); return () => window.removeEventListener('keydown', f); }, [onClose]);
  if (!src) return null;
  return <div className="lightbox" onClick={onClose} role="dialog" aria-label="Photo"><button className="icon-btn lightbox-close" onClick={onClose} aria-label="Close"><X /></button><img src={src} alt="Large view" onClick={e => e.stopPropagation()} /></div>;
}

export function Modal({ title, onClose, children, wide }) {
  useEffect(() => { const f = e => e.key === 'Escape' && onClose(); window.addEventListener('keydown', f); return () => window.removeEventListener('keydown', f); }, [onClose]);
  return <div className="modal-backdrop" onMouseDown={e => { if (e.target === e.currentTarget) onClose(); }}><div className={`dialog ${wide ? 'wide' : ''}`} role="dialog" aria-label={title}><div className="dialog-head"><h2>{title}</h2><button className="icon-btn" onClick={onClose} aria-label="Close"><X /></button></div>{children}</div></div>;
}

export function Stat({ icon: Icon, label: l, value, caption, tone }) {
  return <article className="stat-card"><div className={`stat-icon ${tone}`}><Icon size={20} /></div><span className="stat-label">{l}</span><strong className="stat-value">{value}</strong><small>{caption || ''}</small></article>;
}

export function EmptyState({ icon: Icon, title, text, action, actionText }) {
  return <div className="empty-state"><div className="empty-icon"><Icon size={25} /></div><h3>{title}</h3><p>{text}</p>{action && <button className="btn btn-primary btn-sm" onClick={action}>{actionText || 'Continue'} <ArrowRight size={15} /></button>}</div>;
}

export const TierChip = ({ tier }) => <span className={`tier-chip ${tier === 'video_chat' ? 'plus' : ''}`}>{tier === 'video_chat' ? 'Video + chat' : 'Video visit'}</span>;

export function DoctorCard({ d, onOpen, preview }) {
  return <article className={`doctor-card pro ${preview ? 'is-preview' : ''}`}>
    <div className="pro-top"><Avatar name={d.name} url={d.photo_url} size={72} round={false} /><div className="pro-id"><h3>{d.name || 'Your name'}</h3><p>{d.specialty || 'Dermatology'}</p><span className="verified-line"><ShieldCheck size={14} /> {preview ? 'Shown after approval' : 'Approved by Tena Medicare'}</span></div></div>
    {d.bio && <p className="pro-bio">{d.bio.length > 150 ? d.bio.slice(0, 150) + '…' : d.bio}</p>}
    {!!(d.conditions || []).length && <div className="chip-row">{d.conditions.slice(0, 5).map(c => <span className="chip" key={c}>{c}</span>)}</div>}
    <div className="doctor-meta"><span><Languages size={14} /> {d.language || 'English'}</span>{d.years_exp ? <span><Stethoscope size={14} /> {d.years_exp} yrs</span> : null}{d.open_slots != null && <span className={d.open_slots ? 'ok' : ''}>{d.open_slots ? `${d.open_slots} open times` : 'No open times'}</span>}</div>
    <div className="doctor-footer"><span><small>From</small> <strong>{money(d.fee_video_etb ?? d.fee_etb)}</strong></span>{onOpen && <button className="btn btn-primary btn-sm" onClick={onOpen}>View profile <ArrowRight size={15} /></button>}</div>
  </article>;
}

/* ---- photo picker: up to N photos, previews, remove ---- */
export function PhotoPicker({ files, setFiles, max = 4, prepare }) {
  const [err, setErr] = useState('');
  const urls = React.useMemo(() => files.map(f => URL.createObjectURL(f)), [files]);
  useEffect(() => () => urls.forEach(URL.revokeObjectURL), [urls]);
  const pick = async e => {
    setErr(''); const chosen = [...e.target.files]; e.target.value = '';
    try { const ready = []; for (const f of chosen.slice(0, max - files.length)) ready.push(await prepare(f)); setFiles([...files, ...ready]); } catch (x) { setErr(x.message); }
  };
  return <div className="photo-picker"><div className="photo-row">{files.map((f, i) => <div className="photo-thumb" key={i}><img src={urls[i]} alt={`Selected ${i + 1}`} /><button type="button" onClick={() => setFiles(files.filter((_, j) => j !== i))} aria-label="Remove photo"><X size={13} /></button></div>)}
    {files.length < max && <label className="photo-add"><input type="file" accept="image/*" multiple hidden onChange={pick} />+<small>Add photo</small></label>}</div>
    {err && <p className="field-error">{err}</p>}</div>;
}
