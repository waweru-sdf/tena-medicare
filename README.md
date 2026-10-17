# Tena Medicare — Ethiopia telemedicine

Flask API + React site. Patients book and pay in ETB through **Chapa**, meet clinicians in a private video room, and get help from an **OpenAI chatbot** (Anthropic also supported). Admin, finance, support and clinician screens are included.

> A development MVP, not a certified medical service. Do not use real patient data until security, privacy, clinical governance, payments, backups and Ethiopian legal requirements have been reviewed.

## Run it locally (Ubuntu)

```bash
# 1. backend (Python 3.11 or 3.12)
cd backend
python3 -m venv .venv && source .venv/bin/activate
pip install -r requirements-dev.txt
cp .env.example .env
python -c 'import secrets;print(secrets.token_urlsafe(48))'                          # paste into SECRET_KEY
python -c 'from cryptography.fernet import Fernet;print(Fernet.generate_key().decode())'   # paste into DATA_KEY
python app.py                                                                          # http://127.0.0.1:5000

# 2. frontend (second terminal, Node 18+)
cd frontend && npm install && npm run dev                                              # http://localhost:5173
```

Demo logins (local only): admin `admin@example.com` / `ChangeMe123!`, clinicians `demo0@example.com` … `demo3@example.com` / `DemoPass123`. Patients register in the site.

With no keys set everything still works: payments are simulated, the chatbot says it is unavailable. Add keys when you are ready.

## Add your keys (only in `backend/.env`, then restart `python app.py`)

| Key | Where to get it |
|---|---|
| `CHAPA_SECRET_KEY` | Chapa dashboard → Settings → API (use the **test** key first) |
| `CHAPA_WEBHOOK_SECRET` | You invent it (long random text) and type the same into Chapa → Settings → Webhooks |
| `OPENAI_API_KEY` | platform.openai.com → API keys (set `AI_PROVIDER=openai`, the default) |
| `ANTHROPIC_API_KEY` | only if you set `AI_PROVIDER=anthropic`. If Anthropic answers "not scoped to a workspace", also set `ANTHROPIC_WORKSPACE_ID` (Console → Workspaces, looks like `wrkspc_…`) or create the key inside a workspace |

Then run `python check_keys.py`, or sign in as admin and press **Settings → Test connections**. Both show the provider's exact error if a key fails, and the webhook URL for Chapa. See [CHAPA_SETUP.md](CHAPA_SETUP.md).

## What is in it

- **Patients:** find doctors, hold a slot (default 15 min), pay with Chapa, pre-visit form, join video 10 min before, records, support requests, cancel with automatic refund, chatbot (also on the landing page, in Amharic or English).
- **Clinicians:** availability and fee, notes, prescriptions, optional AI drafting/copilot/transcription (off by default, patient consent required each time).
- **Admin:** approve clinicians, appointments, reports, AI switches and policy settings, add staff.
- **Finance:** payouts, refunds waiting on Chapa (check, retry, mark done). **Support / compliance:** ticket queue.
- **Safety rules enforced by the server:** unpaid appointments cannot get notes, prescriptions or signatures; expired holds release the slot; a payment is only trusted after Chapa's verify API agrees on amount and ETB; AI never signs or prescribes; emergency wording never reaches the AI.

## Tests

```bash
cd backend && pytest -q        # 39 tests, no network or keys needed
cd frontend && npm run build
```

## Private video (needed before real visits)

Public rooms are refused. Choose one in `.env`: self-hosted Jitsi with JWT (`JITSI_DOMAIN`, `JITSI_APP_ID`, `JITSI_APP_SECRET`) or JaaS by 8x8 (`JITSI_APP_ID`, `JITSI_KID`, `JITSI_PRIVATE_KEY`). For quick local checks only, set `ALLOW_PUBLIC_ROOMS=1` (ignored in production).

## Deploy (Render)

`render.yaml` creates the web service and a Postgres database. In the Render dashboard set: `CHAPA_SECRET_KEY`, `CHAPA_WEBHOOK_SECRET`, `APP_URL` (your https address), `DATA_KEY` (back it up: losing it loses clinical text), `OPENAI_API_KEY`, the video keys, and `ADMIN_EMAIL` + `ADMIN_PASSWORD` (12+ chars, creates the first admin; remove them afterwards). `SEED_DEMO=0` and `ENABLE_DEV_PAYMENTS=0` are already set.

## Git

```bash
git init && git branch -M main && git add . && git status --short   # .env, node_modules, *.db must not appear
git commit -m "Tena Medicare Ethiopia" && git remote add origin https://github.com/YOU/REPO.git && git push -u origin main
```
