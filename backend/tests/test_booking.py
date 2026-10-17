import datetime as dt, jwt, pytest
import app as A
from helpers import *

@pytest.fixture(autouse=True)
def clean(): A.HITS.clear()

RX = dict(medicine="Amoxicillin", dose="500mg", route="oral", frequency="3x/day", duration="5d", quantity="15")

def expire(aid):
    with A.app.app_context():
        A.db.session.get(A.Appointment, aid).slot.hold_expires_at = dt.datetime.utcnow() - dt.timedelta(seconds=1); A.db.session.commit()

# ---- unpaid appointments are locked
def test_unpaid_appointment_blocks_notes_prescriptions_signing_and_ai():
    c, t, aid, dc, dtok = booked()
    assert dc.put(f"/api/appointments/{aid}/note", headers=hdr(dtok), json={"plan": "x"}).status_code == 402
    assert dc.post(f"/api/appointments/{aid}/prescriptions", headers=hdr(dtok), json=RX).status_code == 402
    assert dc.post(f"/api/appointments/{aid}/note/finalize", headers=hdr(dtok)).status_code == 402
    assert dc.post(f"/api/appointments/{aid}/ai/copilot", headers=hdr(dtok), json={"patient_consent": True}).status_code == 402

def test_paid_appointment_allows_note_prescription_and_signing():
    c, t, aid, dc, dtok = booked(); force_paid(aid)
    assert dc.put(f"/api/appointments/{aid}/note", headers=hdr(dtok), json={"plan": "rest", "summary_for_patient": "ok"}).status_code == 200
    assert dc.post(f"/api/appointments/{aid}/prescriptions", headers=hdr(dtok), json=RX).status_code == 200
    assert dc.post(f"/api/appointments/{aid}/note/finalize", headers=hdr(dtok)).status_code == 200
    assert states(aid)[0] == "completed"

# ---- holds expire without anyone running a background job
def test_expired_hold_frees_slot_for_the_next_patient_and_blocks_payment():
    c, t, aid, *_ = booked(); expire(aid)
    with A.app.app_context(): sid = A.db.session.get(A.Appointment, aid).slot_id
    assert c.post(f"/api/appointments/{aid}/pay", headers=hdr(t)).status_code == 410
    assert states(aid)[0] == "expired"
    c2, t2 = make_user(); c2.post("/api/consent", headers=hdr(t2))
    assert c2.post("/api/appointments", headers=hdr(t2), json={"slot_id": sid}).status_code == 200

def test_listing_slots_releases_expired_holds():
    c, t, aid, *_ = booked(); expire(aid)
    with A.app.app_context(): ap = A.db.session.get(A.Appointment, aid); cid, sid = ap.clinician_id, ap.slot_id
    assert sid in [s["id"] for s in c.get(f"/api/clinicians/{cid}/slots", headers=hdr(t)).get_json()]

def test_hold_deadline_is_in_the_appointment_list():
    c, t, aid, *_ = booked()
    row = c.get("/api/appointments", headers=hdr(t)).get_json()[0]
    assert row["status"] == "pending_payment" and row["hold_expires_at"]

def test_paying_after_expiry_keeps_booking_if_slot_still_free(monkeypatch):
    monkeypatch.setattr(A, "CHAPA_KEY", "k")
    c, t, aid, *_ = booked()
    with A.app.app_context(): A.db.session.add(A.Payment(appointment_id=aid, amount_kes=250, method="chapa", provider_ref="LATE1")); A.db.session.commit()
    expire(aid)
    with A.app.app_context():
        A.mark_paid(A.Payment.query.filter_by(provider_ref="LATE1").first(), "250", "ETB"); A.db.session.commit()
    assert states(aid) == ("confirmed", "paid")

def test_paying_after_expiry_refunds_if_someone_else_took_the_slot(monkeypatch):
    monkeypatch.setattr(A, "CHAPA_KEY", "")   # local mode: refund is instant
    c, t, aid, *_ = booked()
    with A.app.app_context(): A.db.session.add(A.Payment(appointment_id=aid, amount_kes=250, method="mock", provider_ref="LATE2")); A.db.session.commit()
    expire(aid)
    A.app.test_client().get("/api/health")
    with A.app.app_context():
        A.expire_holds(); sid = A.db.session.get(A.Appointment, aid).slot_id
        A.db.session.get(A.Slot, sid).booked = True; A.db.session.commit()   # a second patient grabbed it
        A.mark_paid(A.Payment.query.filter_by(provider_ref="LATE2").first(), "250", "ETB"); A.db.session.commit()
    assert states(aid) == ("expired", "refunded")

def test_reschedule_keeps_the_unpaid_deadline():
    c, t, aid, *_ = booked(days=3)
    with A.app.app_context():
        ap = A.db.session.get(A.Appointment, aid); old = ap.slot; deadline = old.hold_expires_at
        new = A.Slot(clinician_id=ap.clinician_id, starts_at=old.starts_at + dt.timedelta(days=1)); A.db.session.add(new); A.db.session.commit(); nid = new.id
    assert c.post(f"/api/appointments/{aid}/reschedule", headers=hdr(t), json={"slot_id": nid}).status_code == 200
    with A.app.app_context():
        ap = A.db.session.get(A.Appointment, aid)
        assert ap.slot_id == nid and ap.slot.hold_expires_at == deadline

# ---- video rooms are private
def room_ready(aid):
    force_paid(aid)
    with A.app.app_context():
        ap = A.db.session.get(A.Appointment, aid); ap.slot.starts_at = dt.datetime.utcnow() + dt.timedelta(minutes=2); A.db.session.commit()

def test_room_refuses_to_fall_back_to_a_public_room(monkeypatch):
    monkeypatch.setattr(A, "JITSI_DOMAIN", ""); monkeypatch.delenv("ALLOW_PUBLIC_ROOMS", raising=False)
    c, t, aid, *_ = booked(); room_ready(aid)
    r = c.get(f"/api/appointments/{aid}/room", headers=hdr(t))
    assert r.status_code == 503 and "meet.jit.si" not in r.get_data(as_text=True)

def test_self_hosted_jwt_room_marks_only_the_clinician_as_moderator(monkeypatch):
    for k, v in dict(JITSI_DOMAIN="meet.example.org", JITSI_APP_ID="telemed", JITSI_APP_SECRET="s3cret").items(): monkeypatch.setattr(A, k, v)
    c, t, aid, dc, dtok = booked(); room_ready(aid)
    pu = c.get(f"/api/appointments/{aid}/room", headers=hdr(t)).get_json()["url"]
    du = dc.get(f"/api/appointments/{aid}/room", headers=hdr(dtok)).get_json()["url"]
    claims = lambda u: jwt.decode(u.split("jwt=")[1], "s3cret", ["HS256"], audience="telemed")
    assert pu.startswith("https://meet.example.org/tm-") and claims(pu)["context"]["user"]["moderator"] == "false"
    assert claims(du)["context"]["user"]["moderator"] == "true" and claims(pu)["iss"] == "telemed"

def test_jaas_room_uses_rs256_with_kid(monkeypatch):
    from cryptography.hazmat.primitives.asymmetric import rsa
    from cryptography.hazmat.primitives import serialization as ser
    key = rsa.generate_private_key(65537, 2048)
    pem = key.private_bytes(ser.Encoding.PEM, ser.PrivateFormat.PKCS8, ser.NoEncryption()).decode()
    monkeypatch.setattr(A, "JITSI_APP_ID", "vpaas-magic-cookie-abc"); monkeypatch.setenv("JITSI_KID", "vpaas-magic-cookie-abc/k1"); monkeypatch.setenv("JITSI_PRIVATE_KEY", pem.replace("\n", "\\n"))
    c, t, aid, *_ = booked(); room_ready(aid)
    url = c.get(f"/api/appointments/{aid}/room", headers=hdr(t)).get_json()["url"]
    assert url.startswith("https://8x8.vc/vpaas-magic-cookie-abc/tm-")
    tok = url.split("jwt=")[1]
    assert jwt.get_unverified_header(tok)["kid"] == "vpaas-magic-cookie-abc/k1"
    assert jwt.decode(tok, key.public_key(), ["RS256"], audience="jitsi")["sub"] == "vpaas-magic-cookie-abc"

def test_production_never_creates_the_default_admin(monkeypatch):
    with A.app.app_context():
        for u in A.User.query.filter_by(role="admin").all(): A.db.session.delete(u)
        A.db.session.commit()
        monkeypatch.setattr(A, "IS_PROD", True); monkeypatch.delenv("ADMIN_EMAIL", raising=False); monkeypatch.delenv("ADMIN_PASSWORD", raising=False)
        A.seed(); assert A.User.query.filter_by(role="admin").count() == 0
        monkeypatch.setenv("ADMIN_EMAIL", "Boss@Clinic.et"); monkeypatch.setenv("ADMIN_PASSWORD", "a-long-passphrase-1")
        A.seed(); assert A.User.query.filter_by(role="admin").one().email == "boss@clinic.et"
