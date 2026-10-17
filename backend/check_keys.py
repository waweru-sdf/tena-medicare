"""Run after filling backend/.env:   python check_keys.py
Checks your Chapa and AI keys against the providers and prints what to set in the Chapa dashboard.
Keys never leave your computer except to talk to api.chapa.co / api.openai.com / api.anthropic.com."""
import os, sys, requests
from dotenv import load_dotenv
load_dotenv()
ok = True
def line(good, text):
    global ok; ok = ok and good; print(("OK   " if good else "FIX  ") + text)

chapa = os.getenv("CHAPA_SECRET_KEY", "").strip()
if not chapa: line(False, "CHAPA_SECRET_KEY is empty (fake local payments only)")
else:
    try:
        r = requests.get("https://api.chapa.co/v1/banks", headers={"Authorization": "Bearer " + chapa}, timeout=20)
        mode = "TEST (no real money)" if "TEST" in chapa.upper() else "LIVE (real money)"
        line(r.status_code == 200, f"Chapa key accepted, mode {mode}" if r.status_code == 200 else f"Chapa rejected the key (HTTP {r.status_code}). Copy the secret key again from Settings > API.")
    except requests.RequestException as e: line(False, f"Could not reach Chapa: {e}")
    app_url = os.getenv("APP_URL", "http://127.0.0.1:5000").rstrip("/")
    line(bool(os.getenv("CHAPA_WEBHOOK_SECRET")), "CHAPA_WEBHOOK_SECRET set" if os.getenv("CHAPA_WEBHOOK_SECRET") else "CHAPA_WEBHOOK_SECRET empty: invent a long random string and put the same one in Chapa's webhook settings")
    print(f"     In Chapa > Settings > Webhooks use:  {app_url}/api/payments/chapa/webhook")
    if "127.0.0.1" in app_url or "localhost" in app_url: print("     (localhost cannot receive webhooks; payments still confirm when the patient returns to the site)")

prov = (os.getenv("AI_PROVIDER") or "openai").lower()
if prov == "openai":
    key = os.getenv("OPENAI_API_KEY", "").strip()
    if not key: line(False, "OPENAI_API_KEY is empty: the chatbot will show 'unavailable'")
    else:
        try:
            r = requests.get("https://api.openai.com/v1/models", headers={"Authorization": "Bearer " + key}, timeout=20)
            line(r.status_code == 200, "OpenAI key accepted" if r.status_code == 200 else f"OpenAI rejected the key (HTTP {r.status_code})")
        except requests.RequestException as e: line(False, f"Could not reach OpenAI: {e}")
else:
    key = os.getenv("ANTHROPIC_API_KEY", "").strip()
    if not key: line(False, "ANTHROPIC_API_KEY is empty")
    else:
        try:
            h = {"x-api-key": key, "anthropic-version": "2023-06-01"}
            if os.getenv("ANTHROPIC_WORKSPACE_ID"): h["anthropic-workspace-id"] = os.getenv("ANTHROPIC_WORKSPACE_ID").strip()
            r = requests.get("https://api.anthropic.com/v1/models", headers=h, timeout=20)
            try: why = r.json()["error"]["message"]
            except Exception: why = ""
            line(r.status_code == 200, "Anthropic key accepted" if r.status_code == 200 else f"Anthropic said HTTP {r.status_code}: {why}")
        except requests.RequestException as e: line(False, f"Could not reach Anthropic: {e}")
sys.exit(0 if ok else 1)
