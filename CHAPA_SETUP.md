# Chapa setup

1. Create a Chapa merchant account and open **Settings → API**. Copy the **test secret key** into `backend/.env` as `CHAPA_SECRET_KEY`.
2. Invent a long random string, put it in `CHAPA_WEBHOOK_SECRET` and in **Settings → Webhooks** (secret hash) in Chapa.
3. Restart the backend, run `python check_keys.py`.
4. Book an appointment in the site. You are sent to Chapa's checkout, pay with a Chapa **test** method, and are returned to the site, which asks Chapa directly whether the payment succeeded. Test payment details are in Chapa's developer docs.

## How the money flow works

- Pay: `POST /api/appointments/<id>/pay` creates a Chapa transaction (ETB, 10-digit Ethiopian phone sent only if valid) and returns the checkout link.
- Confirm: the browser return (`/?ref=...`) and the signed webhook both end in the same server check: Chapa's `transaction/verify`, amount and `ETB` must match. The browser is never proof.
- Webhook URL to enter in Chapa: `https://<your-backend>/api/payments/chapa/webhook`. A localhost address cannot receive it, so locally the return-to-site check does the job. Chapa's docs are unclear about which header and key sign webhooks, so the server accepts `x-chapa-signature` / `Chapa-Signature` made with either your webhook secret or API key, and still re-verifies with Chapa.
- Late payment (hold already expired): the booking is kept if the slot is still free, otherwise the payment is refunded automatically.
- Cancel early enough (default 12 h) or clinician cancels: refund requested at `POST /refund/<tx_ref>`. It shows as *refund pending* until Chapa reports it finished (`refund/<ref_id>/verify`). Finance sees pending refunds under Reports and can check, retry, or mark done after confirming in the Chapa dashboard.
- Chapa's own fees are not refundable (their rule), so refunds can cost you the fee.

## Going live

Swap in the **live** key, set `PRODUCTION=1`, `ENABLE_DEV_PAYMENTS=0`, keep `APP_URL` as your https address, and complete a small real payment plus a refund yourself before opening to patients. Prices are whole ETB amounts (database columns keep the old `*_kes` names, values are ETB).
