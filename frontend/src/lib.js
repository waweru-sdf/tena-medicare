import { useEffect, useState } from 'react';

const API_BASE = (import.meta.env.VITE_API_BASE_URL || '').replace(/\/$/, '');

/* ---- saved details: everything lives in localStorage so a refresh or a new visit never starts from zero ---- */
export const store = {
  get(key, fallback = null) { try { const v = localStorage.getItem(key); return v == null ? fallback : JSON.parse(v); } catch { return fallback; } },
  set(key, value) { try { localStorage.setItem(key, JSON.stringify(value)); } catch { /* private mode or full: the site still works */ } },
  del(key) { try { localStorage.removeItem(key); } catch { /* ignore */ } },
};
export const getToken = () => { try { return localStorage.getItem('tena_token'); } catch { return null; } };
export const saveSession = (token, user) => { try { if (token) localStorage.setItem('tena_token', token); localStorage.setItem('tena_user', JSON.stringify(user)); } catch { /* ignore */ } };
export const clearSession = () => { try { localStorage.removeItem('tena_token'); localStorage.removeItem('tena_user'); localStorage.removeItem('tena_last'); } catch { /* ignore */ } };

export const request = async (path, options = {}) => {
  const token = getToken();
  const headers = { ...(options.body instanceof FormData ? {} : { 'Content-Type': 'application/json' }), ...(token ? { Authorization: `Bearer ${token}` } : {}), ...options.headers };
  const response = await fetch(`${API_BASE}${path}`, { ...options, headers });
  const type = response.headers.get('content-type') || '';
  const data = type.includes('application/json') ? await response.json() : { error: await response.text() };
  if (response.status === 401 && token) { clearSession(); window.location.reload(); } // session expired
  if (!response.ok) throw new Error(response.status === 413 ? 'That file is too large.' : data.error || `Request failed (${response.status})`);
  return data;
};

/* ---- formatting ---- */
export const money = n => new Intl.NumberFormat('en-ET', { style: 'currency', currency: 'ETB', maximumFractionDigits: 0 }).format(Number(n || 0));
export const dateTime = v => { try { return new Date(v).toLocaleString('en-ET', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Africa/Addis_Ababa' }); } catch { return v; } };
export const dayLabel = v => new Date(v).toLocaleDateString('en-ET', { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'Africa/Addis_Ababa' });
export const timeLabel = v => new Date(v).toLocaleTimeString('en-ET', { hour: '2-digit', minute: '2-digit', timeZone: 'Africa/Addis_Ababa' });
export const dayKey = v => new Date(v).toLocaleDateString('en-CA', { timeZone: 'Africa/Addis_Ababa' });
export const initials = n => (n || 'D').replace(/^Dr\.?\s+/i, '').split(' ').map(x => x[0]).join('').slice(0, 2).toUpperCase();
export const timeAgo = v => {
  const s = Math.max(0, (Date.now() - new Date(v).getTime()) / 1000);
  if (s < 60) return 'now'; if (s < 3600) return `${Math.floor(s / 60)} min`; if (s < 86400) return `${Math.floor(s / 3600)} h`; return `${Math.floor(s / 86400)} d`;
};

/* ---- routing: the page lives in the address (#/find-a-doctor, #/doctor/12) so refresh, back and shared links all work ---- */
export const slug = s => String(s).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
export function useHash() {
  const read = () => window.location.hash.replace(/^#\/?/, '');
  const [hash, setHash] = useState(read);
  useEffect(() => { const f = () => setHash(read()); window.addEventListener('hashchange', f); return () => window.removeEventListener('hashchange', f); }, []);
  return [hash, path => { window.location.hash = '/' + String(path).replace(/^\/+/, ''); }];
}

/* ---- photos: private images need the sign-in header, so they are fetched and shown from a local blob ---- */
export function useBlobUrl(url) {
  const [src, setSrc] = useState(null);
  useEffect(() => {
    if (!url) { setSrc(null); return undefined; }
    let alive = true, made = null;
    const token = getToken();
    fetch(`${API_BASE}${url}`, { headers: token ? { Authorization: `Bearer ${token}` } : {} })
      .then(r => (r.ok ? r.blob() : Promise.reject(new Error('no image'))))
      .then(b => { if (alive) { made = URL.createObjectURL(b); setSrc(made); } })
      .catch(() => { if (alive) setSrc('error'); });
    return () => { alive = false; if (made) URL.revokeObjectURL(made); };
  }, [url]);
  return src;
}
export const publicUrl = url => (url ? `${API_BASE}${url}` : null);

/* Shrink phone photos in the browser before upload (a 12 MP photo becomes ~300 KB), which also makes uploads work on weak mobile data. */
export async function prepareImage(file, max = 1600) {
  if (!file || !file.type.startsWith('image/')) throw new Error('Choose a photo (JPEG, PNG or WebP).');
  try {
    const bmp = await createImageBitmap(file);
    const scale = Math.min(1, max / Math.max(bmp.width, bmp.height));
    const canvas = document.createElement('canvas'); canvas.width = Math.round(bmp.width * scale); canvas.height = Math.round(bmp.height * scale);
    canvas.getContext('2d').drawImage(bmp, 0, 0, canvas.width, canvas.height);
    const blob = await new Promise(res => canvas.toBlob(res, 'image/jpeg', 0.86));
    if (blob) return new File([blob], 'photo.jpg', { type: 'image/jpeg' });
  } catch { /* fall through: send the original if the browser cannot decode it */ }
  if (file.size > 6 * 1024 * 1024) throw new Error('That photo is too large. Try a smaller one.');
  return file;
}
