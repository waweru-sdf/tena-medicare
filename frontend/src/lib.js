const API_BASE = (import.meta.env.VITE_API_BASE_URL || '').replace(/\/$/, '');
export const clearSession = () => { localStorage.removeItem('tena_token'); localStorage.removeItem('tena_user'); };
export const request = async (path, options = {}) => {
  const token = localStorage.getItem('tena_token');
  const headers = { ...(options.body instanceof FormData ? {} : { 'Content-Type': 'application/json' }), ...(token ? { Authorization: `Bearer ${token}` } : {}), ...options.headers };
  const response = await fetch(`${API_BASE}${path}`, { ...options, headers });
  const type = response.headers.get('content-type') || '';
  const data = type.includes('application/json') ? await response.json() : { error: await response.text() };
  if (response.status === 401 && token) { clearSession(); window.location.reload(); } // session expired
  if (!response.ok) throw new Error(data.error || `Request failed (${response.status})`);
  return data;
};
export const money = n => new Intl.NumberFormat('en-ET', { style: 'currency', currency: 'ETB', maximumFractionDigits: 2 }).format(Number(n || 0));
export const dateTime = v => { try { return new Date(v).toLocaleString('en-ET', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Africa/Addis_Ababa' }); } catch { return v; } };
export const initials = n => (n || 'D').split(' ').map(x => x[0]).join('').slice(0, 2).toUpperCase();
