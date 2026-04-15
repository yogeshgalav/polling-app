# Polling App

Laravel + Inertia + Vue polling application with real-time vote count updates via WebSockets.

## Stack

- Laravel 12
- Inertia + Vue 3
- Vite
- Laravel Echo + Pusher

## Scalability and resilience

This app includes several patterns that help under load and when running multiple app servers. Use them as talking points when describing how the system behaves at scale.

### Rate limiting

- **Poll voting** (`POST /polls/{poll}/vote`): Custom limiter `poll-vote` — **3 requests per second per IP** (`AppServiceProvider`). The route uses `throttle:poll-vote` middleware.
- **Login**: **5 failed attempts** per `email|IP` key before lockout; successful login clears the counter (`LoginRequest` + `RateLimiter`).
- **Email verification**: Resend and verify routes use **`throttle:6,1`** (6 requests per minute).

### Concurrency and data integrity

- **Asynchronous vote processing on high-priority queue**: `POST /polls/{poll}/vote` now returns quickly with **202 Accepted** and dispatches `ProcessPollVote` to the **`high`** queue. This keeps web request latency low under traffic spikes.
- **Guest-scoped unique queue jobs**: vote processing reconstructs guest identity from `userId + ip + userAgent`, and `ProcessPollVote` uses `ShouldBeUnique` so only one in-flight vote job per poll+guest identity is queued for a short TTL window.
- **Database uniqueness**: `votes` has a **unique index on `(poll_id, guest_id)`** so one vote per guest per poll is enforced at the database even if application logic were bypassed.
- **Transactions**: Vote creation and `poll_options.votes_count` increment run inside a **single `DB::transaction`**.

### High-traffic vote UX optimization

- **Immediate selection on click**: frontend marks the selected option immediately (optimistic UI) before waiting for the API response.
- **Response-driven optimistic counts**: the vote API responds with optimistic `total_votes` and `options[].votes_count` (`+1` for selected option), so percentages and bars update right away.
- **Real-time reconciliation**: `votes.updated` broadcasts keep all clients in sync once the queued job commits.

### Caching

- **Poll feed (production)**: For `APP_ENV=production` and **pages 1–10 only**, the JSON feed is cached with **`Cache::rememberForever('polls:{page}', …)`** to cut repeated database work on hot list endpoints. Other environments skip this and always hit the DB.
- **Invalidation**: When an admin **creates a new poll**, cached keys **`polls:1` … `polls:10`** are cleared so fresh data appears without restarting the app.

### Real-time updates without polling the API

- **`VoteCountUpdated`** implements **`ShouldBroadcast`** and publishes to channel `polls.{pollId}` as event `votes.updated`. Clients update via **Laravel Echo + Pusher** instead of hammering HTTP for counts. In production, **queue workers** process broadcast jobs (default queue is `database` in `.env.example`; **Redis** is typical for higher throughput).

### Front-end performance

- **`Vite::prefetch(concurrency: 3)`** in `AppServiceProvider` prefetches linked assets intelligently.
- **`AddLinkHeadersForPreloadedAssets`** (web middleware) supports efficient asset preloading for Inertia/Vite builds.

### Operations

- **Health check**: `GET /up` is registered for load balancer and orchestration probes (`bootstrap/app.php`).

### Recommended production tuning

For multiple servers or heavy traffic, point **`CACHE_STORE`**, **`QUEUE_CONNECTION`**, and optionally **`SESSION_DRIVER`** at **Redis** so queue uniqueness locks, rate limiter storage, sessions, and queues stay coherent across instances. The default `.env.example` uses **database** drivers for easy local setup.

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
   BROADCAST_CONNECTION=pusher
   PUSHER_APP_ID=your-pusher-app-id
   PUSHER_APP_KEY=your-pusher-app-key
   PUSHER_APP_SECRET=your-pusher-app-secret
   PUSHER_HOST=
   PUSHER_PORT=443
   PUSHER_SCHEME=https
   PUSHER_APP_CLUSTER=mt1
   VITE_PUSHER_APP_KEY=your-pusher-app-key
   VITE_PUSHER_HOST=
   VITE_PUSHER_PORT=443
   VITE_PUSHER_SCHEME=https
   VITE_PUSHER_APP_CLUSTER=mt1
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

3. Queue worker (required for vote processing and broadcasts):

   ```bash
   php artisan queue:work --queue=high,default
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

- Confirm Pusher credentials and cluster values in `.env` are valid.
- Confirm `VITE_PUSHER_*` values in `.env` match your Pusher app settings.
- Restart `npm run dev` after changing `.env` values so Vite reloads env vars.
