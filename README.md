# Polling App

Laravel + Inertia + Vue polling application with real-time vote count updates via WebSockets.

## Stack

- Laravel 12
- Inertia + Vue 3
- Vite
- Laravel Echo + Reverb

## Prerequisites

- PHP 8.2+
- Composer
- Node.js 20+
- A database configured in `.env` (SQLite/MySQL/PostgreSQL)

## Local setup

1. Install backend dependencies:

   ```bash
   composer install
   ```

2. Install frontend dependencies:

   ```bash
   npm install
   ```

3. Create your environment file and app key:

   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

   On Windows PowerShell, use:

   ```powershell
   Copy-Item .env.example .env
   php artisan key:generate
   ```

4. Ensure these broadcast/WebSocket values exist in `.env`:

   ```dotenv
   BROADCAST_CONNECTION=reverb
   VITE_REVERB_APP_KEY=local-app-key
   VITE_REVERB_HOST=localhost
   VITE_REVERB_PORT=8080
   VITE_REVERB_SCHEME=http
   ```

5. Run database migrations:

   ```bash
   php artisan migrate
   ```

## Running the app

Start these processes in separate terminals:

1. Laravel HTTP server:

   ```bash
   php artisan serve
   ```

2. Vite dev server:

   ```bash
   npm run dev
   ```

3. WebSocket server (Reverb):

   ```bash
   php artisan reverb:start
   ```

Then open `http://127.0.0.1:8000/polls`.

## Testing real-time vote updates

Use two separate browser sessions so each one acts like a different voter (for example, a normal window + incognito/private window).

1. Open the same poll page in both sessions:
   - `http://127.0.0.1:8000/polls`
   - or a single poll details page at `/polls/{slug}`
2. In session A, submit a vote for an option.
3. Verify session B updates automatically without refresh:
   - Total vote count increments.
   - Option vote count increments.
4. Repeat by voting in session B on another poll and confirm session A receives updates.

If updates do not appear:

- Confirm `php artisan reverb:start` is running.
- Confirm `VITE_REVERB_*` values in `.env` match the running host/port.
- Restart `npm run dev` after changing `.env` values so Vite reloads env vars.
