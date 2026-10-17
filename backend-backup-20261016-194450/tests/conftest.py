import os, sys
# The app reads its settings when it is imported, so set them first.
os.environ.update(DATABASE_URL="sqlite:///:memory:", SEED_DEMO="0", RUN_REMINDERS="0", ENABLE_DEV_PAYMENTS="1",
                  SECRET_KEY="test-secret", DATA_KEY="")
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
