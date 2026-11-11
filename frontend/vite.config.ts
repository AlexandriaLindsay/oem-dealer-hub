import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// In development the API runs on Laravel's server (php artisan serve
// --port=8010 - 8000 is often taken) and Vite proxies to it, so the session
// cookie is same-origin.
export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    strictPort: true,
    proxy: {
      '/api': 'http://localhost:8010',
      '/sanctum': 'http://localhost:8010',
    },
  },
})
