# Implementation notes

This update preserves the existing Flask app and adds a Chapa-backed Ethiopia configuration, ETB public API labels, provider-selectable AI, payment guards, expiring booking holds, private-Jitsi fail-closed behavior, backend endpoints for additional SRS items, and a responsive React/Vite frontend.

## API changes
- `POST /api/appointments/<id>/pay`: Chapa hosted checkout, ETB.
- `GET /api/payments/verify/<tx_ref>`: authenticated server-side verification.
- `POST /api/payments/chapa/webhook`: signed Chapa webhook and server-side verification.
- `POST /api/appointments/<id>/intake` and `GET` counterpart: pre-visit intake.
- `POST /api/appointments/<id>/ai/transcribe`: audio transcription; OpenAI provider only.
- `POST /api/appointments/<id>/ai/copilot`: clinician workflow copilot.
- `POST /api/appointments/<id>/ai/suggested-questions`: draft question suggestions.
- `GET /api/ai/reference-lookup`: official reference starting points.
- `POST /api/appointments/<id>/ai/translate`: text translation; not real-time captions.
- `POST /api/appointments/<id>/ai/connection-help`: deterministic troubleshooting.
- `POST /api/appointments/<id>/ai/post-call-actions`: draft follow-up checklist.
- `POST /api/ai/ticket-draft`: support-ticket draft.
- `POST /api/admin/ai/analytics-summary`: aggregate-only analytics summary.

## Configuration switches
- `AI_PROVIDER=anthropic|openai`; `AI_MODEL` overrides the default model.
- Admin switches: `ai_assistant`, `ai_notes`, `ai_intake`, `ai_translate`, `ai_ticket_drafts`, `ai_analytics`.
- `SLOT_HOLD_MINUTES` sets default unpaid hold duration; admin config can override via `hold_minutes`.

## Test status
- Python syntax compilation passed in the build workspace.
- React JSX syntax transpilation passed using the installed TypeScript transpiler.
- Full Vite production build could not be run because npm dependency installation timed out in this environment. Run `cd frontend && npm install && npm run build` on a network-enabled machine.
- Automated pytest execution could not run because required Python packages are not preinstalled in the environment and package installation failed due unavailable network access. Run `pip install -r backend/requirements.txt && cd backend && pytest -q` in a network-enabled development environment.

## Not included / must not be represented as finished
- A responsive React/Vite frontend is now included with white/blue styling, a generated telemedicine showcase image, ETB pricing display, Addis Ababa timezone formatting, payment redirect handling, intake, records, AI assistant/copilot, translation, audio upload transcription, connection help, support drafts, and reports.
- True live captions/streaming translation are not implemented; the frontend supports uploaded-audio transcription and text translation, but not real-time captions.
- Jitsi JWT needs validation against the selected hosted/self-managed Jitsi deployment before production.
- Chapa signature validation must be tested using real Chapa test-mode webhook deliveries before enabling live transactions.
- Database columns `fee_kes` and `amount_kes` remain as legacy internal names, but their values are ETB; public API response names have been changed. Rename them only via a reviewed database migration.
- Existing data stored as KES cannot be automatically converted to ETB without a deliberate migration/pricing decision. Back up the database and reconcile existing appointments, balances, and payouts before using this version against live data.


## Local demo accounts added in this package
- Patient: `patient@example.com` / `PatientDemo123!`
- Clinician: `demo0@example.com` / `DemoPass123`
- Admin: `admin@example.com` / `AdminDemo123!`
These are development-only credentials and are not suitable for a public deployment.
