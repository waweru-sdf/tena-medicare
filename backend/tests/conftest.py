import os, sys
# The app reads its settings when it is imported, so set them first.
os.environ.update(DATABASE_URL=os.environ.get("TEST_DATABASE_URL", "sqlite:///:memory:"), SEED_DEMO="0", RUN_REMINDERS="0", ENABLE_DEV_PAYMENTS="1",
                  SECRET_KEY="test-secret", DATA_KEY="", PRODUCTION="0", AI_PROVIDER="openai",
                  OPENAI_API_KEY="", ANTHROPIC_API_KEY="")
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
