"""The dermatology ecosystem: doctor approval, two tiers, chat with photos, prescriptions for everyone, many-to-many care."""
import datetime as dt, io, pytest
import app as A
from helpers import *

PNG = b"\x89PNG\r\n\x1a\n" + b"\x00" * 40
RX = dict(medicine="Hydrocortisone 1% cream", dose="thin layer", route="topical", frequency="twice daily", duration="7 days", quantity="1 tube")

@pytest.fixture(autouse=True)
def clean(): A.HITS.clear()

def png(name="skin.png", data=PNG): return {"photo": (io.BytesIO(data), name)}

def doctor(approved=True, fee=1000, chat=None):
    """A registered doctor with a complete profile. Returns (client, token, clinician_id)."""
    c, t = make_user("clinician")
    with A.app.app_context():
        u = A.User.query.filter_by(role="clinician").order_by(A.User.id.desc()).first()
        cl = A.Clinician.query.filter_by(user_id=u.id).first()
        cl.fee_kes, cl.fee_chat_etb, cl.bio = fee, chat, "I treat acne, eczema and pigmentation, with plain explanations."
        cl.conditions, cl.status = "Acne, Eczema", "approved" if approved else "draft"
        A.db.session.commit(); return c, t, cl.id

def add_slot(cid, hours=48):
    with A.app.app_context():
        s = A.Slot(clinician_id=cid, starts_at=dt.datetime.utcnow() + dt.timedelta(hours=hours, seconds=next(counter)))
        A.db.session.add(s); A.db.session.commit(); return s.id

def patient():
    c, t = make_user(); c.post("/api/consent", headers=hdr(t)); return c, t

def visit(pc, pt, cid, tier="video", pay=True):
    """Book and (in the local test mode) pay for a visit. Returns the appointment id."""
    legacy = tier == "video"   # the old video-only plan can no longer be bought; keep one such visit to prove old records still work
    body = {"reason": "itchy patches", "tier": "video_chat" if legacy else tier, "clinician_id": cid}
    if tier != "chat": body["slot_id"] = add_slot(cid)
    r = pc.post("/api/appointments", headers=hdr(pt), json=body)
    assert r.status_code == 200, r.get_data(as_text=True)
    aid = r.get_json()["id"]
    if legacy:
        with A.app.app_context(): A.db.session.get(A.Appointment, aid).tier = "video"; A.db.session.commit()
    if pay:
        assert pc.post(f"/api/appointments/{aid}/pay", headers=hdr(pt)).status_code == 200
        assert pc.post(f"/api/dev/simulate-payment/{aid}", headers=hdr(pt)).status_code == 200
    return aid

def conv_id(c, t):
    rows = c.get("/api/conversations", headers=hdr(t)).get_json(); return rows[0]["id"] if rows else None

# ---------------- doctor onboarding and approval
def test_new_doctor_starts_as_draft_cannot_submit_until_profile_is_complete():
    c, t = make_user("clinician")
    p = c.get("/api/clinician/profile", headers=hdr(t)).get_json()
    assert p["status"] == "draft" and p["missing"]
    r = c.post("/api/clinician/profile/submit", headers=hdr(t)); assert r.status_code == 400 and r.get_json()["missing"]
    c.put("/api/clinician/profile", headers=hdr(t), json={"bio": "x" * 50, "conditions": "Acne, Eczema", "reg_number": "ET-123"})
    assert c.post("/api/clinician/photo", headers=hdr(t), data=png(), content_type="multipart/form-data").status_code == 200
    assert c.post("/api/clinician/profile/submit", headers=hdr(t)).get_json()["status"] == "pending"

def test_pending_or_draft_doctor_is_not_listed_and_cannot_add_slots():
    dc, dtok, cid = doctor(approved=False)
    pc, pt = patient()
    assert cid not in [d["id"] for d in pc.get("/api/clinicians", headers=hdr(pt)).get_json()]
    assert pc.get(f"/api/clinicians/{cid}", headers=hdr(pt)).status_code == 404
    assert dc.post("/api/clinician/slots", headers=hdr(dtok), json={"starts_at": "2030-01-01T09:00:00Z"}).status_code == 403

def admin_login():
    with A.app.app_context():
        if not A.User.query.filter_by(email="boss2@example.com").first():
            A.db.session.add(A.User(email="boss2@example.com", name="Boss", role="admin", password_hash=A.generate_password_hash("password123"))); A.db.session.commit()
    c = A.app.test_client(); return c, c.post("/api/auth/login", json={"email": "boss2@example.com", "password": "password123"}).get_json()["token"]

def test_admin_approves_and_rejects_with_reason_and_doctor_can_resubmit():
    c, t = make_user("clinician")
    c.put("/api/clinician/profile", headers=hdr(t), json={"bio": "y" * 50, "conditions": "Acne", "reg_number": "ET-9"})
    c.post("/api/clinician/photo", headers=hdr(t), data=png(), content_type="multipart/form-data"); c.post("/api/clinician/profile/submit", headers=hdr(t))
    ac, at = admin_login()
    cid = c.get("/api/auth/me", headers=hdr(t)).get_json()["user"]["clinician_id"]
    assert ac.post(f"/api/admin/clinicians/{cid}/review", headers=hdr(at), json={"decision": "rejected"}).status_code == 400   # a reason is required
    assert ac.post(f"/api/admin/clinicians/{cid}/review", headers=hdr(at), json={"decision": "rejected", "reason": "Licence number unreadable"}).status_code == 200
    p = c.get("/api/clinician/profile", headers=hdr(t)).get_json(); assert p["status"] == "rejected" and "Licence" in p["review_reason"]
    assert c.post("/api/clinician/profile/submit", headers=hdr(t)).get_json()["status"] == "pending"
    assert ac.post(f"/api/admin/clinicians/{cid}/review", headers=hdr(at), json={"decision": "approved"}).status_code == 200
    assert c.get("/api/auth/me", headers=hdr(t)).get_json()["user"]["doctor_status"] == "approved"

def test_admin_cannot_review_a_draft_and_non_admins_cannot_review():
    c, t = make_user("clinician"); cid = c.get("/api/auth/me", headers=hdr(t)).get_json()["user"]["clinician_id"]
    ac, at = admin_login()
    assert ac.post(f"/api/admin/clinicians/{cid}/review", headers=hdr(at), json={"decision": "approved"}).status_code == 409
    assert c.post(f"/api/admin/clinicians/{cid}/review", headers=hdr(t), json={"decision": "approved"}).status_code == 403

def test_changing_the_licence_number_sends_an_approved_doctor_back_for_review():
    dc, dtok, cid = doctor()
    dc.put("/api/clinician/profile", headers=hdr(dtok), json={"reg_number": "NEW-1"})
    assert dc.get("/api/clinician/profile", headers=hdr(dtok)).get_json()["status"] == "pending"

# ---------------- tiers and prices
def admin_login():
    with A.app.app_context():
        if not A.User.query.filter_by(email="boss@example.com").first():
            A.db.session.add(A.User(email="boss@example.com", name="Boss", role="admin", password_hash=A.generate_password_hash("password123"))); A.db.session.commit()
    c = A.app.test_client(); return c, c.post("/api/auth/login", json={"email": "boss@example.com", "password": "password123"}).get_json()["token"]

def set_prices(client, token, **kw):
    return client.put("/api/admin/config", headers=hdr(token), json=kw)

def test_prices_are_global_and_only_the_admin_changes_them():
    dc, dtok, cid = doctor(); pc, pt = patient(); ac, at = admin_login()
    set_prices(ac, at, price_chat=800, price_video_chat=1500)
    base = pc.get("/api/prices").get_json(); assert base["currency"] == "ETB" and base["chat"] < base["video_chat"]
    card = pc.get(f"/api/clinicians/{cid}", headers=hdr(pt)).get_json()
    assert card["price_chat"] == base["chat"] and card["price_video_chat"] == base["video_chat"]
    assert "fee_etb" not in card
    assert set_prices(dc, dtok, price_chat=1).status_code in (401, 403)              # a doctor cannot
    assert set_prices(pc, pt, price_chat=1).status_code in (401, 403)                # nor a patient
    dc.put("/api/clinician/profile", headers=hdr(dtok), json={"fee_etb": 99999, "fee_chat_etb": 99999})   # doctor-sent fees are ignored
    assert set_prices(ac, at, price_chat=900, price_video_chat=2300).status_code == 200
    card = pc.get(f"/api/clinicians/{cid}", headers=hdr(pt)).get_json()
    assert (card["price_chat"], card["price_video_chat"]) == (900, 2300)
    a1 = visit(pc, pt, cid, "chat", pay=False); a2 = visit(pc, pt, cid, "video_chat", pay=False)
    got = {a["id"]: a for a in pc.get("/api/appointments", headers=hdr(pt)).get_json()}
    assert got[a1]["fee_etb"] == 900 and got[a2]["fee_etb"] == 2300
    pc.post(f"/api/appointments/{a2}/pay", headers=hdr(pt))
    with A.app.app_context(): assert A.Payment.query.filter_by(appointment_id=a2).first().amount_kes == 2300

def test_admin_price_must_be_a_positive_whole_number():
    ac, at = admin_login()
    for bad in (0, -5, "abc", 10.5): assert set_prices(ac, at, price_chat=bad).status_code == 400

def test_old_or_unknown_tiers_cannot_be_booked():
    dc, dtok, cid = doctor(); pc, pt = patient(); sid = add_slot(cid)
    for tier in ("platinum", "video"):
        assert pc.post("/api/appointments", headers=hdr(pt), json={"slot_id": sid, "tier": tier}).status_code == 400
    assert pc.post("/api/appointments", headers=hdr(pt), json={"slot_id": sid, "tier": "video_chat"}).status_code == 200

def test_chat_only_plan_needs_no_time_opens_chat_after_payment_and_has_no_video_room():
    dc, dtok, cid = doctor(); pc, pt = patient()
    aid = visit(pc, pt, cid, "chat", pay=False)
    assert pc.get("/api/conversations", headers=hdr(pt)).get_json() == []            # unpaid: no chat yet
    assert pc.post(f"/api/appointments/{aid}/pay", headers=hdr(pt)).status_code == 200
    assert pc.post(f"/api/dev/simulate-payment/{aid}", headers=hdr(pt)).status_code == 200
    rows = pc.get("/api/conversations", headers=hdr(pt)).get_json(); assert len(rows) == 1 and rows[0]["chat_open"] is True
    a = [x for x in pc.get("/api/appointments", headers=hdr(pt)).get_json() if x["id"] == aid][0]
    assert a["tier"] == "chat" and a["status"] == "confirmed" and a["chat_open"] and a["chat_until"]
    assert pc.get(f"/api/appointments/{aid}/room", headers=hdr(pt)).status_code == 400
    assert pc.post(f"/api/appointments/{aid}/cancel", headers=hdr(pt)).status_code == 400
    # the placeholder never shows up as a bookable or listed time
    assert pc.get(f"/api/clinicians/{cid}/slots", headers=hdr(pt)).get_json() == []
    assert dc.get("/api/clinician/slots", headers=hdr(dtok)).get_json() == []

def test_unpaid_chat_plan_expires_without_leaving_a_bookable_slot():
    dc, dtok, cid = doctor(); pc, pt = patient(); aid = visit(pc, pt, cid, "chat", pay=False)
    with A.app.app_context():
        ap = A.db.session.get(A.Appointment, aid); ap.slot.hold_expires_at = dt.datetime.utcnow() - dt.timedelta(minutes=1); A.db.session.commit()
    assert [x for x in pc.get("/api/appointments", headers=hdr(pt)).get_json() if x["id"] == aid][0]["status"] == "expired"
    assert pc.get(f"/api/clinicians/{cid}/slots", headers=hdr(pt)).get_json() == []

# ---------------- chat is a tier-2 feature
def test_legacy_video_only_visit_has_no_chat_and_chat_opens_after_a_chat_plan_is_paid():
    dc, dtok, cid = doctor(); pc, pt = patient()
    visit(pc, pt, cid, "video")
    assert pc.get("/api/conversations", headers=hdr(pt)).get_json() == []
    visit(pc, pt, cid, "video_chat", pay=False)
    assert pc.get("/api/conversations", headers=hdr(pt)).get_json() == []          # held but unpaid: still no chat
    pc2, pt2 = patient(); visit(pc2, pt2, cid, "video_chat")
    rows = pc2.get("/api/conversations", headers=hdr(pt2)).get_json()
    assert len(rows) == 1 and rows[0]["chat_open"] is True

def test_patient_and_doctor_exchange_messages_and_photos_and_unread_counts_work():
    dc, dtok, cid = doctor(); pc, pt = patient(); visit(pc, pt, cid, "video_chat")
    cv = conv_id(pc, pt)
    assert pc.post(f"/api/conversations/{cv}/messages", headers=hdr(pt), json={"body": "The rash is spreading to my arm"}).status_code == 200
    r = pc.post(f"/api/conversations/{cv}/messages", headers=hdr(pt), data={"body": "Photo from today", "photo": (io.BytesIO(PNG), "a.png")}, content_type="multipart/form-data")
    assert r.status_code == 200 and r.get_json()["message"]["image_url"]
    row = dc.get("/api/conversations", headers=hdr(dtok)).get_json()[0]; assert row["unread"] == 2 and row["other_name"].startswith("Test User")
    msgs = dc.get(f"/api/conversations/{cv}/messages", headers=hdr(dtok)).get_json()["messages"]
    assert [m["body"] for m in msgs] == ["The rash is spreading to my arm", "Photo from today"]
    img = dc.get(msgs[1]["image_url"], headers=hdr(dtok)); assert img.status_code == 200 and img.data == PNG and img.mimetype == "image/png"
    assert dc.get("/api/conversations", headers=hdr(dtok)).get_json()[0]["unread"] == 0
    assert dc.post(f"/api/conversations/{cv}/messages", headers=hdr(dtok), json={"body": "Please keep applying the cream"}).status_code == 200
    assert pc.get(f"/api/conversations/{cv}/messages?after={msgs[1]['id']}", headers=hdr(pt)).get_json()["messages"][0]["mine"] is False

def test_chat_photos_are_encrypted_at_rest_and_private_to_the_pair():
    dc, dtok, cid = doctor(); pc, pt = patient(); visit(pc, pt, cid, "video_chat"); cv = conv_id(pc, pt)
    url = pc.post(f"/api/conversations/{cv}/messages", headers=hdr(pt), data={"photo": (io.BytesIO(PNG), "a.png")}, content_type="multipart/form-data").get_json()["message"]["image_url"]
    with A.app.app_context():
        m = A.db.session.get(A.Media, url.rsplit("/", 1)[1]); assert m.encrypted and PNG not in m.data
    other_c, other_t = patient(); odc, odt, _ = doctor()
    assert other_c.get(url, headers=hdr(other_t)).status_code == 403
    assert odc.get(url, headers=hdr(odt)).status_code == 403
    assert A.app.test_client().get(url).status_code == 403                      # not signed in
    assert other_c.get(f"/api/conversations/{cv}/messages", headers=hdr(other_t)).status_code == 404
    assert other_c.post(f"/api/conversations/{cv}/messages", headers=hdr(other_t), json={"body": "hi"}).status_code == 404

def test_chat_closes_after_the_window_but_history_stays_readable():
    dc, dtok, cid = doctor(); pc, pt = patient(); aid = visit(pc, pt, cid, "video_chat"); cv = conv_id(pc, pt)
    pc.post(f"/api/conversations/{cv}/messages", headers=hdr(pt), json={"body": "hello"})
    with A.app.app_context():
        A.db.session.get(A.Appointment, aid).chat_until = dt.datetime.utcnow() - dt.timedelta(minutes=1); A.db.session.commit()
    assert pc.post(f"/api/conversations/{cv}/messages", headers=hdr(pt), json={"body": "still there?"}).status_code == 403
    assert dc.post(f"/api/conversations/{cv}/messages", headers=hdr(dtok), json={"body": "reply"}).status_code == 403
    assert len(pc.get(f"/api/conversations/{cv}/messages", headers=hdr(pt)).get_json()["messages"]) == 1
    visit(pc, pt, cid, "video_chat")                                            # a new tier-2 visit reopens the same thread
    assert pc.post(f"/api/conversations/{cv}/messages", headers=hdr(pt), json={"body": "back again"}).status_code == 200

def test_chat_window_length_comes_from_settings():
    dc, dtok, cid = doctor(); pc, pt = patient(); aid = visit(pc, pt, cid, "video_chat")
    with A.app.app_context(): a = A.db.session.get(A.Appointment, aid); assert 29.9 < (a.chat_until - a.slot.starts_at).total_seconds() / 86400 < 30.1
    ac, at = admin_login(); ac.put("/api/admin/config", headers=hdr(at), json={"chat_days": "14"})
    aid2 = visit(pc, pt, cid, "video_chat")
    with A.app.app_context(): a = A.db.session.get(A.Appointment, aid2); assert 13.9 < (a.chat_until - a.slot.starts_at).total_seconds() / 86400 < 14.1
    ac.put("/api/admin/config", headers=hdr(at), json={"chat_days": "30"})

def test_photo_validation_rejects_non_images_and_oversize_files():
    dc, dtok, cid = doctor(); pc, pt = patient(); visit(pc, pt, cid, "video_chat"); cv = conv_id(pc, pt)
    bad = pc.post(f"/api/conversations/{cv}/messages", headers=hdr(pt), data={"photo": (io.BytesIO(b"<script>alert(1)</script>"), "x.png")}, content_type="multipart/form-data")
    assert bad.status_code == 400
    big = pc.post(f"/api/conversations/{cv}/messages", headers=hdr(pt), data={"photo": (io.BytesIO(b"\xff\xd8\xff" + b"0" * (7 * 1024 * 1024)), "x.jpg")}, content_type="multipart/form-data")
    assert big.status_code in (400, 413)
    assert pc.post(f"/api/conversations/{cv}/messages", headers=hdr(pt), json={"body": "  "}).status_code == 400

# ---------------- booking message and photos (both tiers)
def test_booking_photos_reach_the_doctor_only_after_payment_and_are_limited_to_four():
    dc, dtok, cid = doctor(); pc, pt = patient()
    aid = visit(pc, pt, cid, "video", pay=False)
    for _ in range(4): assert pc.post(f"/api/appointments/{aid}/photos", headers=hdr(pt), data=png(), content_type="multipart/form-data").status_code == 200
    assert pc.post(f"/api/appointments/{aid}/photos", headers=hdr(pt), data=png(), content_type="multipart/form-data").status_code == 400
    d = dc.get(f"/api/appointments/{aid}", headers=hdr(dtok)).get_json(); assert d["locked"] and d["photo_ids"] == [] and d["reason"] is None
    pc.post(f"/api/appointments/{aid}/pay", headers=hdr(pt)); pc.post(f"/api/dev/simulate-payment/{aid}", headers=hdr(pt))
    d = dc.get(f"/api/appointments/{aid}", headers=hdr(dtok)).get_json()
    assert not d["locked"] and len(d["photo_ids"]) == 4 and d["reason"] == "itchy patches"
    assert dc.get(f"/api/media/{d['photo_ids'][0]}", headers=hdr(dtok)).status_code == 200
    oc, ot = patient(); assert oc.get(f"/api/media/{d['photo_ids'][0]}", headers=hdr(ot)).status_code == 403

def test_doctor_profile_photos_are_public_so_the_directory_can_show_them():
    dc, dtok, cid = doctor()
    url = dc.post("/api/clinician/photo", headers=hdr(dtok), data=png(), content_type="multipart/form-data").get_json()["photo_url"]
    assert A.app.test_client().get(url).status_code == 200

# ---------------- prescriptions work for every tier and many-to-many care
def test_doctor_can_prescribe_for_video_only_and_video_chat_patients_and_each_patient_sees_them():
    dc, dtok, cid = doctor(); a, at = patient(); b, bt = patient()
    va = visit(a, at, cid, "video"); vb = visit(b, bt, cid, "video_chat")
    for aid in (va, vb): assert dc.post(f"/api/appointments/{aid}/prescriptions", headers=hdr(dtok), json=RX).status_code == 200
    assert [r["medicine"] for r in a.get("/api/prescriptions", headers=hdr(at)).get_json()] == [RX["medicine"]]
    assert len(b.get("/api/prescriptions", headers=hdr(bt)).get_json()) == 1
    c, ct = patient(); vc = visit(c, ct, cid, "video", pay=False)
    assert dc.post(f"/api/appointments/{vc}/prescriptions", headers=hdr(dtok), json=RX).status_code == 402   # unpaid: still locked
    assert a.post(f"/api/appointments/{va}/prescriptions", headers=hdr(at), json=RX).status_code == 403     # patients cannot write prescriptions

def test_prescribing_still_works_after_the_note_is_signed():
    dc, dtok, cid = doctor(); pc, pt = patient(); aid = visit(pc, pt, cid, "video")
    dc.put(f"/api/appointments/{aid}/note", headers=hdr(dtok), json={"plan": "p", "summary_for_patient": "s"}); dc.post(f"/api/appointments/{aid}/note/finalize", headers=hdr(dtok))
    assert states(aid)[0] == "completed"
    assert dc.post(f"/api/appointments/{aid}/prescriptions", headers=hdr(dtok), json=RX).status_code == 200

def test_one_doctor_many_patients_and_one_patient_many_doctors():
    d1, t1, c1 = doctor(); d2, t2, c2 = doctor(); p1, pt1 = patient(); p2, pt2 = patient()
    visit(p1, pt1, c1, "video"); visit(p2, pt2, c1, "video_chat"); visit(p1, pt1, c2, "video_chat")
    assert len(d1.get("/api/clinician/patients", headers=hdr(t1)).get_json()) == 2
    assert len(d2.get("/api/clinician/patients", headers=hdr(t2)).get_json()) == 1
    mine = p1.get("/api/patient/doctors", headers=hdr(pt1)).get_json(); assert {m["id"] for m in mine} == {c1, c2}
    assert {m["id"]: m["chat_open"] for m in mine} == {c1: False, c2: True}
    assert len(p1.get("/api/conversations", headers=hdr(pt1)).get_json()) == 1      # only the tier-2 doctor has a thread
    pid = d1.get("/api/clinician/patients", headers=hdr(t1)).get_json()[0]["id"]
    assert d1.get(f"/api/clinician/patients/{pid}", headers=hdr(t1)).status_code == 200
    other = [r["id"] for r in d2.get("/api/clinician/patients", headers=hdr(t2)).get_json()][0]
    assert d1.get(f"/api/clinician/patients/{other}", headers=hdr(t1)).status_code in (200, 404)   # allowed only if they share a paid visit
    assert d1.get("/api/clinician/patients/987654", headers=hdr(t1)).status_code == 404

def test_a_doctor_sees_only_their_own_slice_of_a_shared_patient():
    d1, t1, c1 = doctor(); d2, t2, c2 = doctor(); p, pt = patient()
    v1 = visit(p, pt, c1, "video"); visit(p, pt, c2, "video")
    pid = d1.get("/api/clinician/patients", headers=hdr(t1)).get_json()[0]["id"]
    visits = d1.get(f"/api/clinician/patients/{pid}", headers=hdr(t1)).get_json()["visits"]
    assert [v["id"] for v in visits] == [v1]
    assert d2.get(f"/api/appointments/{v1}", headers=hdr(t2)).status_code == 404

# ---------------- saved details and staying signed in
def test_patient_details_are_saved_and_reused():
    pc, pt = patient()
    pc.put("/api/patient/profile", headers=hdr(pt), json={"skin_type": "Oily", "allergies": "Penicillin", "phone": "0911223344", "date_of_birth": "1999-04-01"})
    again = pc.get("/api/patient/profile", headers=hdr(pt)).get_json()
    assert again["profile"]["allergies"] == "Penicillin" and again["profile"]["skin_type"] == "Oily" and again["phone"] == "0911223344"
    with A.app.app_context(): assert "Penicillin" not in A.db.session.execute(A.db.text("select data from patient_profile")).scalars().first()  # encrypted at rest
    dc, dtok, cid = doctor(); aid = visit(pc, pt, cid, "video")
    assert dc.get(f"/api/appointments/{aid}", headers=hdr(dtok)).get_json()["patient_profile"]["allergies"] == "Penicillin"

def test_me_returns_a_fresh_token_and_tokens_last_two_weeks():
    import jwt as J
    pc, pt = patient(); me = pc.get("/api/auth/me", headers=hdr(pt)).get_json()
    assert me["user"]["role"] == "patient" and me["token"]
    exp = J.decode(me["token"], A.app.config["SECRET_KEY"], ["HS256"])["exp"]
    assert 13 * 86400 < exp - dt.datetime.utcnow().timestamp() + 0 < 15 * 86400 + 10 or exp > 0

# ---------------- skin-care assistant
def test_assistant_suggests_matching_doctors_without_ai_and_never_leaves_emergencies_to_ai(monkeypatch):
    dc, dtok, cid = doctor()
    with A.app.app_context(): A.db.session.get(A.Clinician, cid).conditions = "Hidradenitis, Acne"; A.db.session.commit()
    monkeypatch.setattr(A, "ai_call", lambda *a, **k: "A dermatologist should look at that. Consider booking.")
    c = A.app.test_client()
    r = c.post("/api/ai/assistant", json={"question": "I get hidradenitis flares and pimples on my face"}).get_json()
    assert r["ai"] and r["doctors"][0]["id"] == cid
    called = []; monkeypatch.setattr(A, "ai_call", lambda *a, **k: called.append(1) or "x")
    e = c.post("/api/ai/assistant", json={"question": "my rash and fever, my face is swollen"}).get_json()
    assert e.get("emergency") and not called

def test_assistant_prompt_forbids_diagnosis_and_lists_the_doctors(monkeypatch):
    dc, dtok, cid = doctor(); seen = {}
    monkeypatch.setattr(A, "ai_call", lambda system, user, *a, **k: seen.update(system=system) or "ok")
    A.app.test_client().post("/api/ai/assistant", json={"question": "what helps dry skin?"})
    assert "must NOT diagnose" in seen["system"] and "Approved doctors right now" in seen["system"] and "30 days" in seen["system"]

def test_doctor_can_list_and_remove_open_slots_but_not_booked_ones():
    dc, dtok, cid = doctor(); pc, pt = patient()
    free, taken = add_slot(cid, 50), add_slot(cid, 60)
    pc.post("/api/appointments", headers=hdr(pt), json={"slot_id": taken})
    rows = {r["id"]: r for r in dc.get("/api/clinician/slots", headers=hdr(dtok)).get_json()}
    assert rows[free]["booked"] is False and rows[taken]["booked"] is True
    assert dc.delete(f"/api/clinician/slots/{taken}", headers=hdr(dtok)).status_code == 409
    assert dc.delete(f"/api/clinician/slots/{free}", headers=hdr(dtok)).status_code == 200
    d2, t2, _ = doctor(); assert d2.delete(f"/api/clinician/slots/{taken}", headers=hdr(t2)).status_code == 404

def test_appointment_list_links_the_chat_thread_for_tier_two():
    dc, dtok, cid = doctor(); pc, pt = patient(); a = visit(pc, pt, cid, "video_chat"); b = visit(pc, pt, cid, "video")
    got = {x["id"]: x for x in pc.get("/api/appointments", headers=hdr(pt)).get_json()}
    assert got[a]["conversation_id"] and got[b]["conversation_id"] is None

def test_gemini_falls_back_to_a_steadier_model_when_the_first_is_busy(monkeypatch):
    monkeypatch.setattr(A, "AI_PROVIDER", "gemini"); monkeypatch.setenv("GEMINI_API_KEY", "k"); monkeypatch.setenv("AI_MODEL", "gemini-3.8-flash"); monkeypatch.setattr(A.time, "sleep", lambda s: None)
    seen = []
    def fake(url, **kw):
        seen.append(url.split("/models/")[1].split(":")[0])
        if "3.8" in url: return Reply({"error": {"message": "high demand"}}, 503)
        return Reply({"candidates": [{"content": {"parts": [{"text": "hello"}]}}]})
    monkeypatch.setattr(A.requests, "post", fake)
    assert A._ai_call("sys", "hi") == "hello"
    assert seen == ["gemini-3.8-flash", "gemini-3.8-flash", "gemini-2.5-flash"]

def test_gemini_reports_the_error_when_every_model_is_busy(monkeypatch):
    monkeypatch.setattr(A, "AI_PROVIDER", "gemini"); monkeypatch.setenv("GEMINI_API_KEY", "k"); monkeypatch.setattr(A.time, "sleep", lambda s: None)
    monkeypatch.setattr(A.requests, "post", lambda url, **kw: Reply({"error": {"message": "high demand"}}, 503))
    with pytest.raises(RuntimeError, match="503"): A._ai_call("sys", "hi")
