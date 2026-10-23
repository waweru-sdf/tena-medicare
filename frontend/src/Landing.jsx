import React, { useState } from 'react';
import { ArrowRight, Camera, Check, CalendarDays, FileText, HeartPulse, Menu, MessageCircle, ShieldCheck, Sparkles, Stethoscope, Video, X, Sun, Droplets, Pill, UserRound } from 'lucide-react';
import { ChatLauncher } from './Extra.jsx';

const SPECIALTIES = ['Dermatology', 'Paediatric dermatology', 'Cosmetic dermatology', 'Dermatology and skin infections', 'Dermatopathology', 'Hair and scalp'];
const CONDITIONS = [['Acne and scarring', 'Pimples, cysts, marks left behind'], ['Eczema and dry skin', 'Itchy, red or flaky patches'], ['Psoriasis', 'Thick, scaly plaques'], ['Pigmentation', 'Dark patches and uneven tone'], ['Hair and scalp', 'Hair loss, dandruff, itchy scalp'], ['Fungal and skin infections', 'Ringworm, scabies, warts, nails'], ['Moles and skin checks', 'Changes in size, shape or colour'], ['Children’s skin', 'Baby eczema, rashes, birthmarks']];
const FAQ = [
  ['Is this for emergencies?', 'No. For a spreading rash with fever, swelling of the face or lips, blistering in the mouth or eyes, or a serious burn, call 907 or go to the nearest hospital.'],
  ['Can a doctor really help from photos and video?', 'For many skin conditions, yes: skin is visible, so a clear photo and a video visit often give the doctor what they need. If you need an in-person test or biopsy, your doctor will tell you.'],
  ['What is the difference between the two plans?', 'Video visit is a single private consultation. Video + chat adds secure messaging with the same doctor, including photos, for 30 days after the visit, so you can check progress and ask follow-up questions.'],
  ['Who sees my photos?', 'Only you and the doctor you booked. Photos are stored encrypted and are never shown publicly.'],
  ['Do I get a prescription?', 'If your doctor decides you need one, yes, whichever plan you chose. It appears in your account under My records.'],
  ['How do doctors join?', 'Doctors register, complete their profile and licence details, and wait for the Tena Medicare team to approve them. Only approved doctors appear to patients.'],
];

function SkinArt() {
  return <svg className="skin-art" viewBox="0 0 480 400" role="img" aria-label="Illustration of the layers of the skin and a video visit">
    <defs>
      <linearGradient id="g1" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stopColor="#dbeafe" /><stop offset="1" stopColor="#bfdbfe" /></linearGradient>
      <linearGradient id="g2" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stopColor="#93c5fd" /><stop offset="1" stopColor="#60a5fa" /></linearGradient>
      <linearGradient id="g3" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stopColor="#bfdbfe" /><stop offset="1" stopColor="#e0f2fe" /></linearGradient>
    </defs>
    <rect x="20" y="20" width="440" height="360" rx="34" fill="url(#g1)" />
    <circle cx="390" cy="82" r="46" fill="#fff" opacity=".55" /><circle cx="90" cy="320" r="62" fill="#fff" opacity=".4" />
    <g transform="translate(70 118)">
      <path d="M0 24 Q80 -8 170 24 T340 24 V74 H0 Z" fill="url(#g2)" />
      <path d="M0 74 H340 V150 Q250 170 170 150 T0 150 Z" fill="url(#g3)" />
      <path d="M0 150 Q85 172 170 150 T340 150 V215 H0 Z" fill="#eaf4ff" />
      {[60, 130, 205, 275].map((x, i) => <g key={x}><path d={`M${x} ${14 + (i % 2) * 6} V${112 + i * 6}`} stroke="#1769e8" strokeWidth="3" strokeLinecap="round" opacity=".55" /><circle cx={x} cy={118 + i * 6} r="9" fill="#fff" stroke="#1769e8" strokeWidth="2" opacity=".9" /></g>)}
      {[[40, 178], [110, 190], [190, 182], [260, 196], [315, 180]].map(([x, y], i) => <circle key={i} cx={x} cy={y} r={7 + (i % 3)} fill="#fff" stroke="#93c5fd" strokeWidth="2" />)}
      <text x="12" y="52" fontSize="11" fontWeight="700" fill="#0b3b86">EPIDERMIS</text><text x="12" y="108" fontSize="11" fontWeight="700" fill="#0b3b86">DERMIS</text><text x="12" y="212" fontSize="11" fontWeight="700" fill="#0b3b86">HYPODERMIS</text>
    </g>
    <g transform="translate(236 30)"><rect width="190" height="58" rx="14" fill="#fff" /><circle cx="30" cy="29" r="15" fill="#1769e8" /><path d="M24 22 L38 29 L24 36 Z" fill="#fff" /><text x="54" y="26" fontSize="12" fontWeight="700" fill="#142e50">Video visit</text><text x="54" y="43" fontSize="10" fill="#72819a">Your dermatologist · live</text></g>
    <g transform="translate(40 40)"><rect width="150" height="54" rx="14" fill="#fff" /><circle cx="28" cy="27" r="14" fill="#e7faf3" /><path d="M21 27 l5 5 l9 -10" stroke="#138765" strokeWidth="3" fill="none" strokeLinecap="round" strokeLinejoin="round" /><text x="50" y="25" fontSize="11" fontWeight="700" fill="#142e50">Photo shared</text><text x="50" y="40" fontSize="10" fill="#72819a">Only your doctor sees it</text></g>
  </svg>;
}

export default function Landing({ onStart, showAuth, setShowAuth, authMode, setAuthMode, authForm, setAuthForm, authenticate, busy, notice, askDoctor }) {
  const [menu, setMenu] = useState(false);
  const [open, setOpen] = useState(0);
  const brand = <a className="brand landing-brand" href="#home"><span className="brand-mark"><HeartPulse size={21} /></span><span>Tena<span className="brand-light">Medicare</span><small>SKIN HEALTH, ONLINE</small></span></a>;
  return <div className="landing">
    <header className="landing-nav">{brand}
      <nav className={menu ? 'nav-open' : ''}><a href="#how" onClick={() => setMenu(false)}>How it works</a><a href="#plans" onClick={() => setMenu(false)}>Plans</a><a href="#conditions" onClick={() => setMenu(false)}>What we treat</a><a href="#doctors" onClick={() => setMenu(false)}>For doctors</a><a href="#faq" onClick={() => setMenu(false)}>Questions</a></nav>
      <div className="nav-buttons"><button className="btn btn-ghost" onClick={() => onStart('login')}>Sign in</button><button className="btn btn-primary" onClick={() => onStart('register')}>Create account <ArrowRight size={16} /></button></div>
      <button className="icon-btn nav-mobile-toggle" onClick={() => setMenu(!menu)} aria-label="Menu">{menu ? <X /> : <Menu />}</button></header>
    <main id="home">
      <section className="hero"><div className="hero-copy"><div className="eyebrow-pill"><span /> ONLINE DERMATOLOGY FOR ETHIOPIA</div>
        <h1>Skin care,<br /><span>from your phone.</span></h1>
        <p className="hero-lede">Tena Medicare connects you with approved dermatologists. Choose a doctor, describe what is going on, share photos, meet on video and, if you want, keep chatting while your skin gets better.</p>
        <div className="hero-buttons"><button className="btn btn-primary btn-large" onClick={() => onStart('register')}>Find a skin doctor <ArrowRight size={18} /></button><a className="btn btn-outline btn-large" href="#how">See how it works</a></div>
        <div className="hero-points"><span><Check size={15} /> Approved doctors only</span><span><Check size={15} /> Prices in birr, shown first</span><span><Check size={15} /> Private photos</span></div></div>
        <div className="hero-visual"><SkinArt /></div></section>

      <section className="intro-band"><div><h2>What is Tena Medicare?</h2><p>A skin-health service where you can see a dermatologist without travelling or waiting in a queue. Every doctor has a public profile that says what they treat, so you can choose the right person, book a time that suits you, tell them what you are going through and attach photos. Your care continues after the visit if you choose a plan with chat, and your doctor can send you a prescription whichever plan you pick.</p></div></section>

      <section className="services-section" id="how"><div className="section-center"><div className="eyebrow">HOW IT WORKS</div><h2>From “what is this rash?” to <span>a plan</span>, in four steps.</h2></div>
        <div className="feature-grid">
          <Step icon={Stethoscope} n="01" title="Choose your doctor" text="Browse profiles with a photo, what each doctor treats, languages and fees. Pick the one who fits." />
          <Step icon={CalendarDays} n="02" title="Book and describe" text="Choose a time, write what you are going through and attach up to four photos of the skin concern." />
          <Step icon={Video} n="03" title="Meet on video" text="Pay securely with Chapa and join a private video room at your time. No app to install." />
          <Step icon={MessageCircle} n="04" title="Follow up" text="With the chat plan, message your doctor and send progress photos for 30 days. Prescriptions arrive in your account." />
        </div></section>

      <section className="plans-section" id="plans"><div className="section-center"><div className="eyebrow">TWO WAYS TO BE SEEN</div><h2>Pick the plan that fits <span>your skin.</span></h2><p>Doctors set their own prices. You always see the price before you pay.</p></div>
        <div className="plan-grid">
          <article className="plan-card"><div className="plan-icon"><Video /></div><h3>Video visit</h3><p>One private consultation with a dermatologist.</p><ul className="check-list"><li><Check /> 30-minute video session</li><li><Check /> Photos and your message shared before the visit</li><li><Check /> Prescription if your doctor decides you need one</li></ul><small>Good for a one-off question or a second opinion.</small></article>
          <article className="plan-card featured"><span className="plan-flag">Best for follow-ups</span><div className="plan-icon"><MessageCircle /></div><h3>Video + chat</h3><p>The visit, plus secure messaging with the same doctor.</p><ul className="check-list"><li><Check /> Everything in Video visit</li><li><Check /> Chat with your doctor for 30 days</li><li><Check /> Send progress photos and ask follow-up questions</li></ul><small>Good for acne, eczema, pigmentation and anything that needs watching.</small></article>
        </div></section>

      <section className="services-section alt" id="conditions"><div className="section-center"><div className="eyebrow">WHAT WE TREAT</div><h2>Common skin, hair and nail <span>concerns.</span></h2></div>
        <div className="condition-grid">{CONDITIONS.map(([t, s]) => <div className="condition" key={t}><h3>{t}</h3><p>{s}</p></div>)}</div></section>

      <section className="care-section" id="care"><div className="care-copy"><div className="eyebrow">YOUR PRIVACY</div><h2>Photos of your skin are <span>personal.</span></h2><p>We treat them that way.</p><ul className="check-list"><li><Check /> Photos are encrypted and shared only with the doctor you booked</li><li><Check /> Doctors are checked and approved before anyone can book them</li><li><Check /> You stay signed in on your own device, with your details saved for next time</li><li><Check /> Our skin-care assistant gives general advice and points you to a doctor. It never diagnoses</li></ul></div>
        <div className="tips-card"><h3><Sparkles size={18} /> Taking a good skin photo</h3><ul><li><Sun size={16} /> Use daylight, not flash</li><li><Camera size={16} /> One close-up and one from further away</li><li><Droplets size={16} /> Clean, dry skin and no filters</li><li><Pill size={16} /> Include any product you are using</li></ul></div></section>

      <section className="doctor-cta" id="doctors"><div><div className="eyebrow">ARE YOU A DERMATOLOGIST?</div><h2>Join Tena Medicare.</h2><p>Create your profile, tell patients what you treat, set your fees and availability. Our team reviews your registration and approves your profile before you appear to patients. One doctor, many patients.</p></div><button className="btn btn-white" onClick={() => { setAuthForm({ ...authForm, role: 'clinician' }); onStart('register'); }}>Apply as a doctor <UserRound size={16} /></button></section>

      <section className="faq-section" id="faq"><div className="section-center"><div className="eyebrow">GOOD TO KNOW</div><h2>Questions people ask.</h2></div>
        <div className="faq-list">{FAQ.map(([q, a], i) => <div className={`faq ${open === i ? 'open' : ''}`} key={q}><button onClick={() => setOpen(open === i ? -1 : i)} aria-expanded={open === i}>{q}<span>{open === i ? '−' : '+'}</span></button>{open === i && <p>{a}</p>}</div>)}</div></section>

      <section className="cta-section"><div className="cta-icon"><HeartPulse size={26} /></div><div><div className="eyebrow">READY WHEN YOU ARE</div><h2>Healthier skin starts with a conversation.</h2><p>Create a free account to see our doctors.</p></div><button className="btn btn-white" onClick={() => onStart('register')}>Create your account <ArrowRight size={16} /></button></section>
    </main>
    <footer className="landing-footer">{brand}<span>© {new Date().getFullYear()} Tena Medicare</span><span className="footer-note"><ShieldCheck size={15} /> Private, encrypted skin care</span></footer>
    {showAuth && <div className="modal-backdrop" onMouseDown={e => { if (e.target === e.currentTarget) setShowAuth(false); }}><div className="auth-modal"><button className="modal-close icon-btn" onClick={() => setShowAuth(false)} aria-label="Close"><X /></button>
      <div className="auth-brand"><span className="brand-mark"><HeartPulse /></span><div><h2>{authMode === 'login' ? 'Welcome back' : authForm.role === 'clinician' ? 'Apply as a doctor' : 'Create your account'}</h2><p>{authMode === 'login' ? 'Sign in to continue your care.' : authForm.role === 'clinician' ? 'You will complete your profile next, then wait for approval.' : 'A few details to get you started.'}</p></div></div>
      {notice && <div className={`toast ${notice.kind}`}>{notice.message}</div>}
      <form className="form-stack" onSubmit={authenticate}>
        {authMode === 'register' && <><div className="role-switch" role="tablist"><button type="button" className={authForm.role === 'patient' ? 'on' : ''} onClick={() => setAuthForm({ ...authForm, role: 'patient' })}>I am a patient</button><button type="button" className={authForm.role === 'clinician' ? 'on' : ''} onClick={() => setAuthForm({ ...authForm, role: 'clinician' })}>I am a doctor</button></div>
          <label>Full name<input value={authForm.name} onChange={e => setAuthForm({ ...authForm, name: e.target.value })} required placeholder={authForm.role === 'clinician' ? 'Dr. First Last' : 'Your full name'} /></label>
          {authForm.role === 'clinician' && <><label>Specialty<select value={authForm.specialty} onChange={e => setAuthForm({ ...authForm, specialty: e.target.value })}>{SPECIALTIES.map(s => <option key={s}>{s}</option>)}</select></label><label>Medical registration number<input value={authForm.reg_number || ''} onChange={e => setAuthForm({ ...authForm, reg_number: e.target.value })} required placeholder="As on your licence" /></label></>}
          <label>Phone {authForm.role === 'patient' ? '(for payment)' : '(optional)'}<input value={authForm.phone} onChange={e => setAuthForm({ ...authForm, phone: e.target.value })} placeholder="+251…" /></label></>}
        <label>Email address<input type="email" autoComplete="email" value={authForm.email} onChange={e => setAuthForm({ ...authForm, email: e.target.value })} required placeholder="you@example.com" /></label>
        <label>Password<input type="password" autoComplete={authMode === 'login' ? 'current-password' : 'new-password'} minLength={8} value={authForm.password} onChange={e => setAuthForm({ ...authForm, password: e.target.value })} required placeholder="At least 8 characters" /></label>
        <button className="btn btn-primary btn-full" disabled={busy}>{busy ? 'Please wait…' : authMode === 'login' ? 'Sign in' : authForm.role === 'clinician' ? 'Create doctor account' : 'Create account'} <ArrowRight size={16} /></button></form>
      <p className="auth-switch">{authMode === 'login' ? 'New to Tena Medicare?' : 'Already have an account?'} <button onClick={() => setAuthMode(authMode === 'login' ? 'register' : 'login')}>{authMode === 'login' ? 'Create account' : 'Sign in'}</button></p>
      <p className="auth-legal"><ShieldCheck size={14} /> You stay signed in on this device, so you do not start from scratch next time. Sign out on shared devices.</p></div></div>}
    <footer className="emergency-foot">This service is not for emergencies. For a spreading rash with fever, swelling of the face or lips, or a serious burn, call 907 or go to the nearest hospital.</footer>
    <ChatLauncher onDoctor={askDoctor} />
  </div>;
}
function Step({ icon: Icon, n, title, text }) { return <article className="feature-card"><div className="feature-top"><div className="feature-icon"><Icon size={22} /></div><span>{n}</span></div><h3>{title}</h3><p>{text}</p></article>; }
