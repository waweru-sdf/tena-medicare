import datetime as dt, itertools
import app as A

counter = itertools.count(1)

class Reply:
    def __init__(self, data, code=200): self._d, self.status_code = data, code
    def json(self): return self._d
    def raise_for_status(self):
        if self.status_code >= 400: raise RuntimeError(self.status_code)

def hdr(t): return {"Authorization": "Bearer " + t}

def make_user(role="patient", phone=None):
    n = next(counter); c = A.app.test_client()
    body = {"email": f"u{n}@example.com", "password": "password123", "name": f"Test User{n}", "role": role, "phone": phone}
    if role == "clinician": body["reg_number"] = f"R{n}"
    r = c.post("/api/auth/register", json=body).get_json()
    return c, r["token"]

def booked(fee=250, days=2, minutes=0):
    """Patient with a held slot. Returns (client, token, appointment_id, clinician_client, clinician_token)."""
    pc, pt = make_user(); pc.post("/api/consent", headers=hdr(pt))
    dc, dtok = make_user("clinician")
    with A.app.app_context():
        user = A.User.query.filter_by(role="clinician").order_by(A.User.id.desc()).first()
        c = A.Clinician.query.filter_by(user_id=user.id).first(); c.status = "approved"; c.fee_kes = fee
        slot = A.Slot(clinician_id=c.id, starts_at=dt.datetime.utcnow() + dt.timedelta(days=days, minutes=minutes, seconds=next(counter)))
        A.db.session.add(slot); A.db.session.commit(); sid = slot.id
    r = pc.post("/api/appointments", headers=hdr(pt), json={"slot_id": sid, "reason": "test"})
    assert r.status_code == 200, r.get_data(as_text=True)
    return pc, pt, r.get_json()["id"], dc, dtok

def ref_of(aid):
    with A.app.app_context(): return A.Payment.query.filter_by(appointment_id=aid).order_by(A.Payment.id.desc()).first().provider_ref

def states(aid):
    with A.app.app_context():
        p = A.Payment.query.filter_by(appointment_id=aid).order_by(A.Payment.id.desc()).first()
        return A.db.session.get(A.Appointment, aid).status, p and p.status

def force_paid(aid, status="confirmed"):
    with A.app.app_context():
        A.db.session.add(A.Payment(appointment_id=aid, amount_kes=250, method="chapa", provider_ref="P" + aid[:10], status="paid"))
        A.db.session.get(A.Appointment, aid).status = status; A.db.session.commit()
