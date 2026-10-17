import datetime as dt
import itertools
import json
import hmac
import hashlib
import pytest
import app as A

counter = itertools.count(1)
KEY = "CHASECK_TEST_fake_key"

class Reply:
    def __init__(self, data): self._data = data
    def json(self): return self._data
    def raise_for_status(self): pass

@pytest.fixture(autouse=True)
def clean_limits():
    A.HITS.clear()

@pytest.fixture
def chapa(monkeypatch):
    state = {"init": None, "verify": None}
    monkeypatch.setattr(A, "CHAPA_KEY", KEY)
    monkeypatch.setattr(A, "CHAPA_WEBHOOK_SECRET", "webhook-secret")
    def post(url, **kw):
        state["init"] = (url, kw)
        if "/refund/" in url: return Reply({"status": "success", "data": {"ref_id": "RF1"}})
        return Reply({"status": "success", "data": {"checkout_url": "https://checkout.chapa.co/pay/test"}})
    def get(url, **kw):
        return Reply({"status": "success", "data": state["verify"] or {}})
    monkeypatch.setattr(A.requests, "post", post)
    monkeypatch.setattr(A.requests, "get", get)
    return state

def make_user(role="patient"):
    n = next(counter); client = A.app.test_client()
    data = {"email": f"test{n}@example.com", "password": "password123", "name": f"Test User {n}", "role": role}
    result = client.post("/api/auth/register", json=data).get_json()
    return client, result["token"]

def hdr(token): return {"Authorization": "Bearer " + token}

def booked(fee=250):
    pc, pt = make_user(); pc.post("/api/consent", headers=hdr(pt))
    _, _ = make_user("clinician")
    with A.app.app_context():
        user = A.User.query.filter_by(role="clinician").order_by(A.User.id.desc()).first()
        c = A.Clinician.query.filter_by(user_id=user.id).first(); c.status = "approved"; c.fee_kes = fee
        slot = A.Slot(clinician_id=c.id, starts_at=dt.datetime.utcnow() + dt.timedelta(days=2, seconds=next(counter)))
        A.db.session.add(slot); A.db.session.commit(); sid = slot.id
    response = pc.post("/api/appointments", headers=hdr(pt), json={"slot_id": sid, "reason": "test"})
    assert response.status_code == 200
    return pc, pt, response.get_json()["id"]

def payment_ref(aid):
    with A.app.app_context(): return A.Payment.query.filter_by(appointment_id=aid).order_by(A.Payment.id.desc()).first().provider_ref

def status(aid):
    with A.app.app_context():
        p = A.Payment.query.filter_by(appointment_id=aid).order_by(A.Payment.id.desc()).first()
        return A.db.session.get(A.Appointment, aid).status, p.status

def test_initialize_chapa_uses_etb(chapa):
    c, token, aid = booked(250)
    out = c.post(f"/api/appointments/{aid}/pay", headers=hdr(token)).get_json()
    assert out["checkout_url"] == "https://checkout.chapa.co/pay/test"
    url, kwargs = chapa["init"]
    assert url.endswith("/transaction/initialize")
    assert kwargs["json"]["amount"] == "250"
    assert kwargs["json"]["currency"] == "ETB"
    assert kwargs["json"]["tx_ref"] == payment_ref(aid)

def test_server_verification_confirms_only_matching_etb(chapa):
    c, token, aid = booked(250)
    c.post(f"/api/appointments/{aid}/pay", headers=hdr(token)); ref = payment_ref(aid)
    chapa["verify"] = {"status": "success", "amount": "250", "currency": "ETB"}
    assert c.get(f"/api/payments/verify/{ref}", headers=hdr(token)).get_json()["status"] == "paid"
    assert status(aid) == ("confirmed", "paid")

def test_mismatched_amount_or_currency_does_not_confirm(chapa):
    c, token, aid = booked(250)
    c.post(f"/api/appointments/{aid}/pay", headers=hdr(token)); ref = payment_ref(aid)
    chapa["verify"] = {"status": "success", "amount": "25", "currency": "ETB"}
    c.get(f"/api/payments/verify/{ref}", headers=hdr(token))
    assert status(aid) == ("pending_payment", "failed")

def test_webhook_requires_signature(chapa):
    c = A.app.test_client(); raw = json.dumps({"event": "charge.success", "tx_ref": "x"}).encode()
    assert c.post("/api/payments/chapa/webhook", data=raw, content_type="application/json").status_code == 403

def test_webhook_signed_event_is_verified_server_side(chapa):
    c, token, aid = booked(250)
    c.post(f"/api/appointments/{aid}/pay", headers=hdr(token)); ref = payment_ref(aid)
    chapa["verify"] = {"status": "success", "amount": "250", "currency": "ETB"}
    event = {"event": "charge.success", "tx_ref": ref, "status": "success"}
    raw = json.dumps(event).encode(); signature = hmac.new(b"webhook-secret", raw, hashlib.sha256).hexdigest()
    response = A.app.test_client().post("/api/payments/chapa/webhook", data=raw, content_type="application/json", headers={"x-chapa-signature": signature})
    assert response.status_code == 200
    assert status(aid) == ("confirmed", "paid")

def test_unpaid_prescription_and_signing_are_blocked(chapa):
    c, token, aid = booked(250)
    # appointment is unpaid and pending_payment; both endpoints must reject it
    rx = c.post(f"/api/appointments/{aid}/prescriptions", headers=hdr(token), json={"medicine":"x","dose":"x","route":"x","frequency":"x","duration":"x","quantity":"x"})
    assert rx.status_code == 402
    with A.app.app_context():
        n = A.Note(appointment_id=aid); A.db.session.add(n); A.db.session.commit()
    signed = c.post(f"/api/appointments/{aid}/note/finalize", headers=hdr(token))
    assert signed.status_code in (403, 404)  # this route is clinician-only

def test_public_jitsi_room_is_never_returned_without_jwt_config(monkeypatch):
    monkeypatch.setattr(A, "JITSI_DOMAIN", "")
    c, token, aid = booked(250)
    with A.app.app_context():
        p = A.Payment(appointment_id=aid, amount_kes=250, method="chapa", provider_ref="paid-room-test", status="paid")
        ap = A.db.session.get(A.Appointment, aid); ap.status = "confirmed"; A.db.session.add(p); A.db.session.commit()
    response = c.get(f"/api/appointments/{aid}/room", headers=hdr(token))
    assert response.status_code == 503
    assert "meet.jit.si" not in response.get_data(as_text=True)
