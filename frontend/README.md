# Frontend

React + Vite + Lucide React. Design uses white surfaces, a restrained blue accent, responsive cards, and a custom-generated telemedicine UI image in `public/images/`.

```bash
npm install
npm run dev
```

The Vite development server proxies `/api` to `http://127.0.0.1:5000`; start Flask first. For the single-service deployment:

```bash
npm run build
```

Flask serves `frontend/dist` after the build. Set `VITE_API_BASE_URL` only when the API is hosted on a separate origin; configure CORS on the backend accordingly. Never put API secrets in `VITE_*` variables.
