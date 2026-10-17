"""Chapa flow against a pretend Chapa: no network and no real key."""
import json, hmac, hashlib, pytest
import app as A
from helpers import *

KEY, WH = "CHASECK_TEST_fake", "webhook-secret"

@pytest.fixture(autouse=True)
def clean(): A.HITS.clear()

@pytest.fixture
def chapa(monkeypatch):
    st = {"init": None, "verify": {}, "refund_calls": [], "refund_verify": {"status": "processing"}, "refund_fail": False}
    monkeypatch.setattr(A, "CHAPA_KEY", KEY); monkeypatch.setattr(A, "CHAPA_WEBHOOK_SECRET", WH)
    def post(url, **kw):
        if "/refund/" in url:
            st["refund_calls"].append((url, kw["json"]))
            return Reply({"status": "failed"}, 500) if st["refund_fail"] else Reply({"status": "success", "data": {"ref_id": "REFID1"}})
        st["init"] = (url, kw); return Reply({"status": "success", "data": {"checkout_url": "https://checkout.chapa.co/pay/t"}})
    def get(url, **kw):
        if "/refund/" in url: return Reply({"status": "success", "data": st["refund_verify"]})
        return Reply({"status": "success", "data": st["verify"]})
    monkeypatch.setattr(A.requests, "post", post); monkeypatch.setattr(A.requests, "get", get)
    return st

def pay(c, t, aid): return c.post(f"/api/appointments/{aid}/pay", headers=hdr(t))

def signed(c, event, header="x-chapa-signature", secret=WH):
    raw = json.dumps(event).encode()
    return c.post("/api/payments/chapa/webhook", data=raw, content_type="application/json",
                  headers={header: hmac.new(secret.encode(), raw, hashlib.sha256).hexdigest()})

def test_initialize_sends_etb_and_clean_phone_and_return_url(chapa):
    c, t, aid, *_ = booked(250)
    out = pay(c, t, aid).get_json()
    assert out["checkout_url"] == "https://checkout.chapa.co/pay/t"
    url, kw = chapa["init"]; body = kw["json"]
    assert url.endswith("/transaction/initialize") and body["amount"] == "250" and body["currency"] == "ETB"
    assert body["tx_ref"] == ref_of(aid) and body["return_url"].endswith("/?ref=" + body["tx_ref"])
    assert "phone_number" not in body   # this user registered without one

def test_phone_numbers_are_converted_to_chapa_format():
    assert A.chapa_phone("+251 911 234 567") == "0911234567"
    assert A.chapa_phone("251711234567") == "0711234567"
    assert A.chapa_phone("0911234567") == "0911234567"
    assert A.chapa_phone("911234567") == "0911234567"
    assert A.chapa_phone("12345") is None and A.chapa_phone(None) is None

def test_verify_confirms_only_matching_etb(chapa):
    c, t, aid, *_ = booked(250); pay(c, t, aid); ref = ref_of(aid)
    chapa["verify"] = {"status": "success", "amount": "250.00", "currency": "ETB"}
    assert c.get(f"/api/payments/verify/{ref}", headers=hdr(t)).get_json()["status"] == "paid"
    assert states(aid) == ("confirmed", "paid")

@pytest.mark.parametrize("v", [{"status": "success", "amount": "25", "currency": "ETB"}, {"status": "success", "amount": "250", "currency": "USD"}])
def test_wrong_amount_or_currency_never_confirms(chapa, v):
    c, t, aid, *_ = booked(250); pay(c, t, aid); chapa["verify"] = v
    c.get(f"/api/payments/verify/{ref_of(aid)}", headers=hdr(t))
    assert states(aid) == ("pending_payment", "failed")

def test_webhook_rejects_unsigned_and_wrongly_signed(chapa):
    c = A.app.test_client(); raw = json.dumps({"event": "charge.success", "tx_ref": "x"}).encode()
    assert c.post("/api/payments/chapa/webhook", data=raw, content_type="application/json").status_code == 403
    assert signed(c, {"event": "charge.success", "tx_ref": "x"}, secret="wrong").status_code == 403

@pytest.mark.parametrize("header,secret", [("x-chapa-signature", WH), ("x-chapa-signature", KEY), ("Chapa-Signature", WH)])
def test_signed_webhook_is_verified_with_chapa_then_confirms(chapa, header, secret):
    c, t, aid, *_ = booked(250); pay(c, t, aid); ref = ref_of(aid)
    chapa["verify"] = {"status": "success", "amount": "250", "currency": "ETB"}
    assert signed(A.app.test_client(), {"event": "charge.success", "tx_ref": ref, "status": "success"}, header, secret).status_code == 200
    assert states(aid) == ("confirmed", "paid")

def test_webhook_claiming_success_is_ignored_if_chapa_disagrees(chapa):
    c, t, aid, *_ = booked(250); pay(c, t, aid); ref = ref_of(aid)
    chapa["verify"] = {"status": "pending", "amount": "250", "currency": "ETB"}
    signed(A.app.test_client(), {"event": "charge.success", "tx_ref": ref, "status": "success"})
    assert states(aid) == ("pending_payment", "initiated")

def test_duplicate_webhook_does_not_notify_twice(chapa):
    c, t, aid, *_ = booked(250); pay(c, t, aid); ref = ref_of(aid)
    chapa["verify"] = {"status": "success", "amount": "250", "currency": "ETB"}
    ev = {"event": "charge.success", "tx_ref": ref}
    signed(A.app.test_client(), ev)
    with A.app.app_context(): n = A.Notification.query.count()
    signed(A.app.test_client(), ev)
    with A.app.app_context(): assert A.Notification.query.count() == n

def test_dev_simulation_is_off_when_a_chapa_key_is_set(chapa):
    c, t, aid, *_ = booked(250); pay(c, t, aid)
    assert c.post(f"/api/dev/simulate-payment/{aid}", headers=hdr(t)).status_code == 404

def test_without_a_key_local_test_mode_works_end_to_end(monkeypatch):
    monkeypatch.setattr(A, "CHAPA_KEY", "")
    c, t, aid, *_ = booked(250)
    assert "Development test mode" in pay(c, t, aid).get_json()["message"]
    assert c.post(f"/api/dev/simulate-payment/{aid}", headers=hdr(t)).status_code == 200
    assert states(aid) == ("confirmed", "paid")
    r = c.post(f"/api/appointments/{aid}/cancel", headers=hdr(t)).get_json()   # test payments refund locally
    assert r["refund"] == "refunded" and states(aid) == ("cancelled", "refunded")

def test_another_patient_cannot_verify_my_payment(chapa):
    c, t, aid, *_ = booked(250); pay(c, t, aid)
    oc, ot = make_user()
    assert oc.get(f"/api/payments/verify/{ref_of(aid)}", headers=hdr(ot)).status_code == 404

# ----- refunds
def paid_via_chapa(chapa, fee=250):
    c, t, aid, dc, dtok = booked(fee); pay(c, t, aid); ref = ref_of(aid)
    chapa["verify"] = {"status": "success", "amount": str(fee), "currency": "ETB"}
    c.get(f"/api/payments/verify/{ref}", headers=hdr(t)); return c, t, aid, ref, dc, dtok

def test_cancel_calls_chapa_refund_and_stays_pending_until_chapa_confirms(chapa):
    c, t, aid, ref, *_ = paid_via_chapa(chapa)
    out = c.post(f"/api/appointments/{aid}/cancel", headers=hdr(t)).get_json()
    assert out["refund"] == "refund_pending" and states(aid) == ("cancelled", "refund_pending")
    url, body = chapa["refund_calls"][0]
    assert url.endswith(f"/refund/{ref}") and body["reference"].startswith("RF")
    chapa["refund_verify"] = {"status": "refunded"}
    signed(A.app.test_client(), {"event": "payment.refund", "tx_ref": ref})     # any signed event for this payment re-checks the refund
    assert states(aid) == ("cancelled", "refunded")

def test_refund_failure_still_cancels_and_finance_can_retry(chapa):
    c, t, aid, ref, *_ = paid_via_chapa(chapa)
    chapa["refund_fail"] = True
    out = c.post(f"/api/appointments/{aid}/cancel", headers=hdr(t)).get_json()
    assert out["ok"] and states(aid) == ("cancelled", "refund_pending")
    A.app.test_client()
    fc, ft = make_user("patient")  # a non-finance user must not reach finance routes
    with A.app.app_context():
        pid = A.Payment.query.filter_by(provider_ref=ref).first().id
        A.db.session.add(A.User(email="fin@example.com", name="Fin", role="finance", password_hash=A.generate_password_hash("password123"))); A.db.session.commit()
    assert fc.post(f"/api/finance/refunds/{pid}/retry", headers=hdr(ft)).status_code == 403
    fin = A.app.test_client(); tok = fin.post("/api/auth/login", json={"email": "fin@example.com", "password": "password123"}).get_json()["token"]
    assert [x["id"] for x in fin.get("/api/finance/refunds", headers=hdr(tok)).get_json()] == [pid]
    chapa["refund_fail"] = False
    assert fin.post(f"/api/finance/refunds/{pid}/retry", headers=hdr(tok)).get_json()["status"] == "refund_pending"
    chapa["refund_verify"] = {"status": "refunded"}
    assert fin.post(f"/api/finance/refunds/{pid}/retry", headers=hdr(tok)).get_json()["status"] == "refunded"

def test_late_cancel_inside_window_gets_no_refund(chapa):
    c, t, aid, ref, *_ = paid_via_chapa(chapa)
    with A.app.app_context():
        import datetime as dt
        A.db.session.get(A.Appointment, aid).slot.starts_at = dt.datetime.utcnow() + dt.timedelta(hours=2); A.db.session.commit()
    out = c.post(f"/api/appointments/{aid}/cancel", headers=hdr(t)).get_json()
    assert out["refund"] is None and states(aid) == ("cancelled", "paid") and chapa["refund_calls"] == []
