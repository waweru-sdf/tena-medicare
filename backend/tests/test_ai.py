import json, pytest
import app as A
from helpers import *

@pytest.fixture(autouse=True)
def clean():
    A.HITS.clear(); A.AI_USAGE.update(day=None, n=0)

@pytest.fixture
def openai(monkeypatch):
    monkeypatch.setattr(A, "AI_PROVIDER", "openai"); monkeypatch.setenv("OPENAI_API_KEY", "sk-test"); monkeypatch.setenv("AI_MODEL", "")
    st = {"calls": [], "reply": "Hello from the bot.", "reject_new_param": False}
    def post(url, **kw):
        st["calls"].append((url, kw))
        if st["reject_new_param"] and "max_completion_tokens" in kw["json"]: return Reply({}, 400)
        return Reply({"choices": [{"message": {"content": st["reply"]}}]})
    monkeypatch.setattr(A.requests, "post", post); return st

def ask(c, q, history=None): return c.post("/api/ai/assistant", json={"question": q, "history": history or []})

def test_chatbot_works_without_signing_in_and_calls_openai(openai):
    r = ask(A.app.test_client(), "How do I book?")
    assert r.status_code == 200 and r.get_json()["answer"] == "Hello from the bot."
    url, kw = openai["calls"][0]; body = kw["json"]
    assert url == "https://api.openai.com/v1/chat/completions" and kw["headers"]["Authorization"] == "Bearer sk-test"
    assert body["model"] == "gpt-4o-mini" and body["messages"][0]["role"] == "system" and "ETB" in body["messages"][0]["content"]
    assert body["messages"][-1] == {"role": "user", "content": "How do I book?"}

def test_chatbot_sends_recent_history_in_order(openai):
    h = [{"role": "assistant", "content": "Hi!"}, {"role": "user", "content": "What are fees?"}, {"role": "assistant", "content": "800-1500 ETB."}]
    ask(A.app.test_client(), "And to cancel?", h)
    roles = [m["role"] for m in openai["calls"][0][1]["json"]["messages"]]
    assert roles == ["system", "user", "assistant", "user"]   # leading assistant greeting dropped

def test_emergency_words_never_reach_the_ai_in_english_or_amharic(openai):
    for q in ("I have chest pain", "የደረት ህመም አለኝ"):
        r = ask(A.app.test_client(), q).get_json()
        assert r["emergency"] and "907" in r["answer"]
    assert openai["calls"] == []

def test_claude_model_name_is_ignored_when_provider_is_openai(openai, monkeypatch):
    monkeypatch.setenv("AI_MODEL", "claude-sonnet-4-6"); ask(A.app.test_client(), "hi")
    assert openai["calls"][0][1]["json"]["model"] == "gpt-4o-mini"

def test_retries_with_older_token_parameter_if_model_rejects_new_one(openai):
    openai["reject_new_param"] = True
    assert ask(A.app.test_client(), "hi").status_code == 200 and "max_tokens" in openai["calls"][-1][1]["json"]

def test_switch_off_and_missing_key_degrade_gracefully(openai, monkeypatch):
    c = A.app.test_client()
    with A.app.app_context(): A.db.session.add(A.Config(key="ai_assistant", value="0")); A.db.session.commit()
    assert ask(c, "hi").status_code == 503
    with A.app.app_context(): A.db.session.get(A.Config, "ai_assistant").value = "1"; A.db.session.commit()
    monkeypatch.setenv("OPENAI_API_KEY", ""); r = ask(c, "hi")
    assert r.status_code == 503 and "still book" in r.get_json()["error"]
    with A.app.app_context(): A.db.session.delete(A.db.session.get(A.Config, "ai_assistant")); A.db.session.commit()

def test_daily_cap_stops_runaway_use(openai, monkeypatch):
    monkeypatch.setenv("AI_DAILY_CAP", "2"); c = A.app.test_client()
    assert [ask(c, "hi").status_code for _ in range(3)] == [200, 200, 503]

def test_anthropic_provider_uses_messages_api(monkeypatch):
    monkeypatch.setattr(A, "AI_PROVIDER", "anthropic"); monkeypatch.setenv("ANTHROPIC_API_KEY", "ak"); monkeypatch.setenv("AI_MODEL", "gpt-4o-mini")
    seen = {}
    def post(url, **kw): seen.update(url=url, **kw); return Reply({"content": [{"type": "text", "text": "Claude says hi"}]})
    monkeypatch.setattr(A.requests, "post", post)
    r = ask(A.app.test_client(), "hi").get_json()
    assert r["answer"] == "Claude says hi" and seen["url"].endswith("/v1/messages")
    assert seen["json"]["model"].startswith("claude") and seen["headers"]["x-api-key"] == "ak"

def test_status_endpoint_reports_provider(openai):
    assert A.app.test_client().get("/api/ai/status").get_json() == {"provider": "openai", "configured": True, "assistant": True}

def test_clinician_ai_is_off_until_admin_switches_it_on_and_needs_consent(openai):
    c, t, aid, dc, dtok = booked(); force_paid(aid)
    r = dc.post(f"/api/appointments/{aid}/ai/copilot", headers=hdr(dtok), json={"question": "q"})
    assert r.status_code == 400   # no patient consent
    r = dc.post(f"/api/appointments/{aid}/ai/copilot", headers=hdr(dtok), json={"question": "q", "patient_consent": True})
    assert r.status_code == 503   # ai_notes defaults to off
    with A.app.app_context(): A.db.session.add(A.Config(key="ai_notes", value="1")); A.db.session.commit()
    r = dc.post(f"/api/appointments/{aid}/ai/copilot", headers=hdr(dtok), json={"question": "q", "patient_consent": True})
    assert r.status_code == 200 and r.get_json()["requires_clinician_review"]
    with A.app.app_context(): A.db.session.delete(A.db.session.get(A.Config, "ai_notes")); A.db.session.commit()

# ---- key / workspace problems are visible, not swallowed
def test_anthropic_workspace_header_is_sent_when_configured(monkeypatch):
    monkeypatch.setattr(A, "AI_PROVIDER", "anthropic"); monkeypatch.setenv("ANTHROPIC_API_KEY", "ak"); monkeypatch.setenv("ANTHROPIC_WORKSPACE_ID", "wrkspc_123")
    seen = {}
    def post(url, **kw): seen.update(kw); return Reply({"content": [{"type": "text", "text": "ok"}]})
    monkeypatch.setattr(A.requests, "post", post)
    assert ask(A.app.test_client(), "hi").status_code == 200 and seen["headers"]["anthropic-workspace-id"] == "wrkspc_123"
    monkeypatch.delenv("ANTHROPIC_WORKSPACE_ID"); ask(A.app.test_client(), "hi"); assert "anthropic-workspace-id" not in seen["headers"]

def admin_client():
    with A.app.app_context():
        if not A.User.query.filter_by(email="boss@example.com").first():
            A.db.session.add(A.User(email="boss@example.com", name="Boss", role="admin", password_hash=A.generate_password_hash("password123"))); A.db.session.commit()
    c = A.app.test_client(); return c, c.post("/api/auth/login", json={"email": "boss@example.com", "password": "password123"}).get_json()["token"]

def test_admin_test_button_reports_the_providers_exact_error(monkeypatch):
    monkeypatch.setattr(A, "AI_PROVIDER", "anthropic"); monkeypatch.setenv("ANTHROPIC_API_KEY", "ak"); monkeypatch.setattr(A, "CHAPA_KEY", "")
    msg = "This API key is not scoped to a workspace"
    monkeypatch.setattr(A.requests, "post", lambda url, **kw: Reply({"error": {"message": msg}}, 400))
    c, t = admin_client()
    out = c.post("/api/admin/integrations-test", headers=hdr(t)).get_json()
    assert out["ai"]["ok"] is False and msg in out["ai"]["message"] and "400" in out["ai"]["message"]
    assert out["chapa"]["ok"] is False and out["webhook_url"].endswith("/api/payments/chapa/webhook")
    assert "ak" not in str(out)

def test_admin_test_button_ok_paths(monkeypatch):
    monkeypatch.setattr(A, "AI_PROVIDER", "openai"); monkeypatch.setenv("OPENAI_API_KEY", "sk"); monkeypatch.setattr(A, "CHAPA_KEY", "CHASECK_TEST-x")
    monkeypatch.setattr(A.requests, "post", lambda url, **kw: Reply({"choices": [{"message": {"content": "OK"}}]}))
    monkeypatch.setattr(A.requests, "get", lambda url, **kw: Reply({"data": []}))
    c, t = admin_client()
    out = c.post("/api/admin/integrations-test", headers=hdr(t)).get_json()
    assert out["ai"]["ok"] and out["chapa"]["ok"] and out["chapa"]["mode"] == "test"
    pc, pt = make_user()
    assert pc.post("/api/admin/integrations-test", headers=hdr(pt)).status_code == 403
