# Deploy for free, no card (Hugging Face Spaces + Neon)

You need: your GitHub repo, a Neon database, a free Hugging Face account. The `Dockerfile` builds the site and the API into one container.

1. **Neon:** project dashboard → Connect → copy the connection string (`postgresql://…neon.tech/…?sslmode=require`). That is your `DATABASE_URL`.
2. **Hugging Face:** sign up at huggingface.co → New Space → name `tena-medicare`, SDK **Docker**, **Public** (a private Space cannot receive Chapa's webhook). Your address will be `https://<username>-tena-medicare.hf.space` (lowercase).
3. **Send the code:** create a token at huggingface.co/settings/tokens (Write). Then, in the project folder:
   ```bash
   git remote add space https://huggingface.co/spaces/<username>/tena-medicare
   git push space main        # username = your HF username, password = the token
   ```
4. **Space → Settings → Variables and secrets.** Secrets: `SECRET_KEY`, `DATA_KEY`, `DATABASE_URL`, `ADMIN_EMAIL`, `ADMIN_PASSWORD` (12+ chars), `CHAPA_SECRET_KEY`, `CHAPA_WEBHOOK_SECRET`, `ANTHROPIC_API_KEY` (+ `ANTHROPIC_WORKSPACE_ID` if needed) or `OPENAI_API_KEY`. Variables: `AI_PROVIDER` (`anthropic` or `openai`), `APP_URL` (your address from step 2).
5. The Space builds (a few minutes). Open the address, sign in as the admin, Settings → **Test connections**.
6. Chapa → Settings → Webhooks: `<APP_URL>/api/payments/chapa/webhook`, secret hash = `CHAPA_WEBHOOK_SECRET`.

Notes: a free Space sleeps after a couple of days without visitors and wakes on the next visit. Neon's free tier also pauses when idle; the first request after a pause is slow. Remove `ADMIN_EMAIL`/`ADMIN_PASSWORD` after the first start. Any other Docker host (Koyeb, Fly.io, Railway) can use the same `Dockerfile`; set `PORT` if it asks.

## Quick public demo from your own computer (no hosting)

```bash
cd frontend && npm run build && cd ../backend && source .venv/bin/activate && python app.py
# second terminal: https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/downloads/
cloudflared tunnel --url http://localhost:5000
```
It prints a public https address that works while your computer is on. Use it as `APP_URL` if you want Chapa's webhook to reach you.
