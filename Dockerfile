# One container: builds the React site, then serves site + API with gunicorn.
# Works on Hugging Face Spaces (Docker), Koyeb, Fly.io, Railway or any Docker host.
FROM node:20-slim AS web
WORKDIR /web
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY frontend/ ./
RUN npm run build

FROM python:3.12-slim
ENV PYTHONUNBUFFERED=1 PORT=7860 PRODUCTION=1 SEED_DEMO=0 ENABLE_DEV_PAYMENTS=0
RUN useradd -m -u 1000 user
WORKDIR /app
COPY backend/requirements.txt backend/requirements.txt
RUN pip install --no-cache-dir -r backend/requirements.txt
COPY backend/ backend/
COPY --from=web /web/dist frontend/dist
RUN chown -R user /app
USER user
EXPOSE 7860
# One worker on purpose: rate limits and the reminder job live in memory.
CMD ["sh", "-c", "cd backend && exec gunicorn app:app --worker-class gthread --workers 1 --threads 4 --timeout 60 --bind 0.0.0.0:${PORT}"]
