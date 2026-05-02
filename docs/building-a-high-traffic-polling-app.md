# How to Create a High-Traffic Laravel App (Practical Guide)

Building a Laravel app is easy. Building one that still feels fast when real users panic-click vote like it is an elevator button—that is where the real game starts 😄 This guide ties good habits (and a little paranoia) to **this polling app codebase**: admin poll CRUD, guest-friendly “one ballot” rules, cached poll feeds, and **Echo + `VoteCountUpdated`** so bars move on **both** the public poll page **and** the admin results screen—because “live counts” stops being fun when only half the tabs believe you.

Concrete entry points worth bookmarking (**Ctrl+click responsibly** 🙌):

- Public UI and JSON feed: [`app/Http/Controllers/Polls/PublicPollController.php`](app/Http/Controllers/Polls/PublicPollController.php)
- Casting votes: [`app/Actions/Polls/CastPollVoteAction.php`](app/Actions/Polls/CastPollVoteAction.php)
- Admin JSON for create/update/delete: [`app/Http/Controllers/Polls/Admin/PollApiController.php`](app/Http/Controllers/Polls/Admin/PollApiController.php)
- Routing and rate limit name: [`routes/web.php`](routes/web.php)
- Limits and observers: [`app/Providers/AppServiceProvider.php`](app/Providers/AppServiceProvider.php)

## Start simple, but think ahead 🚀

The hottest path here is **`POST /polls/{poll}/vote`**: validation, locking, transactional insert + counter bump, broadcast, optional feed-cache patch. The next hottest is **`GET /polls/feed`** and the homepage index—both funnel through **`PublicPollController::pollFeed`** and cache for early pages (`cachedPollIds` + per-poll keys). Design those first; nobody’s portfolio got famous because the settings page shaved 8 ms ✅

---

## Protect the app from repeated actions 🛡️

Humans spam buttons. Bots spam endpoints. Laravel rate limiters quietly flex “not today”—use them 👀

The vote route registers a named limiter **`poll-vote`**:

```19:21:routes/web.php
Route::post('/polls/{poll}/vote', [PublicPollController::class, 'vote'])->name(
    'polls.vote',
)->middleware('throttle:poll-vote');
```

The limit itself keys off a **stable poll device cookie** (`PollDeviceId::get`), falling back to **IP** if the cookie is empty—so testers on the same LAN are not artificially merged:

```49:53:app/Providers/AppServiceProvider.php
        RateLimiter::for("poll-vote", function (Request $request) {
            $deviceId = PollDeviceId::get($request);

            return Limit::perSecond(3)->by($deviceId !== "" ? $deviceId : $request->ip());
        });
```

The device id cookie name is **`poll_device_id`**; it is exempted from Laravel’s encrypted-cookie middleware so SPA requests can behave predictably (`PollDeviceId::COOKIE_NAME` in [`bootstrap/app.php`](bootstrap/app.php)). Middleware [`EnsurePollDeviceId`](app/Http/Middleware/EnsurePollDeviceId.php) ensures guests get an id cookie.

Ship the same idea anywhere a single POST suddenly becomes everyone’s hobby under burst traffic.

---

## Stop duplicate votes at both levels 🔒

Controllers have dreams; databases have veto power. Never trust **only** the happy path 💡  
This repo stacks **three walls** behind the UX so “I clicked twice” does not graduate into “two rows exist” 😬

**1 — Cache lock keyed by poll and guest**, so overlapping clicks serialize before touching the DB (wait **3** seconds before `LockTimeoutException`):

```46:52:app/Actions/Polls/CastPollVoteAction.php
        $lockKey = "poll:{$poll->id}:guest:{$guest->id}:vote";
        $lockTtlSeconds = 10;
        $waitSeconds = 3;

        try {
            $result = Cache::lock($lockKey, $lockTtlSeconds)->block(
                $waitSeconds,
```

Inside the closure: **`already_voted`** gated on **`votes.deleted_at`** (soft deletes).

**2 — Transaction plus `lockForUpdate()` on the poll option**, then **`Vote::create(...)`** and **`$option->increment('votes_count')`** so the tally and row stay coherent:

```65:79:app/Actions/Polls/CastPollVoteAction.php
                        DB::transaction(function () use ($poll, $guest, $pollOptionId): void {
                            $option = PollOption::query()
                                ->whereKey($pollOptionId)
                                ->where('poll_id', $poll->id)
                                ->lockForUpdate()
                                ->firstOrFail();

                            Vote::query()->create([
                                'poll_id' => $poll->id,
                                'poll_option_id' => $option->id,
                                'guest_id' => $guest->id,
                            ]);

                            $option->increment('votes_count');
                        });
```

**3 — Unique index on `(poll_id, guest_id)`** plus mapping **`23000`** to **already voted** if two workers still collide:

```10:27:database/migrations/2026_04_13_104152_create_votes_table.php
        Schema::create("votes", function (Blueprint $table) {
            $table->id();
            $table
                ->foreignId("poll_id")
                ->constrained("polls")
                ->cascadeOnDelete();
            // ...
            $table->unique(["poll_id", "guest_id"]);
        });
```

`PublicPollController::vote` translates outcomes into chill **201** energy, grouchy-but-fair **403** duplicates, or **429** “breathe—someone beat you to that lock”—see [`PublicPollController.php`](app/Http/Controllers/Polls/PublicPollController.php) for the blunt HTTP poetry ✍️

---

## Use database transactions for related writes 🧾

Half-created polls age like milk; users only notice when the percentages look like astrology ✨  

Votes: covered above (`DB::transaction` inside the lock closure).

Poll **create** and **update** also run in transactions in [`PollApiController`](app/Http/Controllers/Polls/Admin/PollApiController.php)—slug derivation, **`$poll->options()->create(...)`**, and update paths that reconcile options and votes (`store` / `update` both wrap **`DB::transaction(...)`**). **All-or-nothing** beats “poll exists but options went on strike.”

---

## Real-time is nice—but correctness comes first ⚡

“Live” stops being delightful when admins refresh like it is still 2009 🔁 **`ShouldBroadcastNow`** means Pusher earns its lunch inside the PHP request—which is totally fine until your workers start giving you emotional damage.

The event broadcasts on **`polls.{pollId}`** as **`votes.updated`** and implements **`ShouldBroadcastNow`** (broadcast runs inline with the HTTP request unless you refactor to queued broadcasts):

```11:32:app/Events/VoteCountUpdated.php
class VoteCountUpdated implements ShouldBroadcastNow
{
    // ...
    public function broadcastOn(): Channel
    {
        return new Channel("polls.{$this->pollId}");
    }

    public function broadcastAs(): string
    {
        return "votes.updated";
    }
}
```

Echo is wired from [`resources/js/bootstrap.js`](resources/js/bootstrap.js) (Pusher driver + env keys). **`CastPollVoteAction`** ends with **`broadcast(new VoteCountUpdated(...))`** after **`refreshCachedPollAfterVote`**.

Clients apply the payload the same way in two places—the **public poll page**:

```14:34:resources/js/Pages/Polls/Show.vue
const channelName = `polls.${props.poll.id}`;

function applyVoteCountUpdate(payload) {
    // ... maps payload.totalVotes and payload.options into poll.value ...
}

onMounted(() => {
    window.Echo?.channel(channelName).listen('.votes.updated', applyVoteCountUpdate);
});

onBeforeUnmount(() => {
    window.Echo?.leave(channelName);
});
```

…and the **admin results** screen:

```11:37:resources/js/Pages/Admin/Polls/Results.vue
const channelName = `polls.${props.poll.id}`;
// ...
onMounted(() => {
    window.Echo?.channel(channelName).listen('.votes.updated', applyVoteCountUpdate);
});
```

The voter’s **`POST`** response still returns JSON counts so the UI upgrades before the websocket finishes its iced coffee ☕️—see **`PublicPollController::vote`** and the axios **`onVote`** handler in **`Show.vue`**.

---

## Be careful with queues 🤔 (`ProcessPollVote` is optional here)

Queues are heroic for mailers and thumbnails; they are chaotic for anything that wears a neon “I am the product moment” badge.

[`ProcessPollVote`](app/Jobs/ProcessPollVote.php) (**`ShouldQueue`**, **`ShouldBeUnique`**) exists as an optional spike for async processing; **`PublicPollController::vote`** currently calls **`CastPollVoteAction::execute`** and broadcasts from there—honest **`201`**, jealous queue optional 🧠  

If you enqueue votes anyway:

- Tune **retries**, **dead-letter / failed-job** visibility, **`afterCommit`** dispatch, and broadcaster timing so “live” viewers are not fooled by rollback or lag.
- Make handlers **idempotent** (your unique **`(poll_id, guest_id)`** row already helps).

Use queues aggressively for ancillary Laravel chores—in this repo the natural next flex is migrating **`VoteCountUpdated`** off **`ShouldBroadcastNow`** onto **`ShouldBroadcast`** when your PHP-FPM graphs start looking like roller coasters 🎢

---

## Caching helps, but stale data hurts 🧹

Caches are cheatsheets, not confession booths—votes move fast enough that **`Cache::rememberForever` + wishful thinking** is how trust dies 👻

Warm path here: **`CachedPollIds::LIMIT` (50)** ordered ids stored under **`CachedPollIds::KEY`**, TTL in [`CachedPollIds.php`](app/Support/CachedPollIds.php). **`cachedPoll`** payloads live at **`poll:{id}`**.

- **`PollObserver`** (registered in **`AppServiceProvider`**) pushes new/edited polls into the id list after commit and rewrites **`poll:{id}`** cache entries [`app/Observers/PollObserver.php`](app/Observers/PollObserver.php).
- **`CastPollVoteAction::refreshCachedPollAfterVote`** **increments counts inside cached `poll:{id}`** when that poll sits in **`CachedPollIds`**, avoiding a blunt “delete every poll key” on each vote [`app/Actions/Polls/CastPollVoteAction.php`](app/Actions/Polls/CastPollVoteAction.php).

**[`VoteRepo::appendGuestVotes`](app/Repositories/VoteRepo.php)** bulk-loads **`votes`** rows for **`whereIn poll_id`** and merges **`voted_option_id`** (+ reconciled counts when voted) onto feed rows—no N+1 tourism 🚌

---

## Keep controllers thin (not “empty,” just not a junk drawer) 🙂

If your controller reads like a novella starring **seven concerns**, give it therapy—split Actions, Responses, repos, literally anything with a spine 📖

**`PublicPollController`** stitches Inertia payloads, **`VoteRequest`** validation (see [`VoteRequest`](app/Http/Requests/VoteRequest.php)), and delegates voting to **`CastPollVoteAction`**. Feed assembly uses **`VoteRepo`** + **`FeedPollResponse`** / **`ShowPollResponse`**.

Admin pages use **`PollPageController`**; JSON shapes go through **`PollApiController`** with **`StorePollRequest`** / **`UpdatePollRequest`**. Policies are wired on routes with **`->can(...)`**:

```41:58:routes/web.php
        Route::get('polls', [PollPageController::class, 'index'])
            ->name('polls.index')
            ->can('viewAny', Poll::class);

        Route::get('polls/create', [PollPageController::class, 'create'])
            ->name('polls.create')
            ->can('create', Poll::class);
        // ... viewResults / update bindings ...
```

**`PollPolicy`** is registered in **`AppServiceProvider`**.

---

## Validation deserves respect ✅

Rules belong in **`FormRequest`** classes so controllers stay readable and regressions hurt your feelings in PHPUnit instead of in prod logs 🌱

Prefer **Form Requests** over inline validation—in this tree, voting uses **`VoteRequest`**; admin create/update uses **`StorePollRequest`** / **`UpdatePollRequest`**.

When an admin changes an existing option label, **`PollApiController::update`** soft-deletes **only** **`votes`** linked to **that option** (see **`Vote::query()->where(...)->where('poll_option_id', $existing->id)`**) so aggregates stay honest; untouched options retain their votes [`app/Http/Controllers/Polls/Admin/PollApiController.php`](app/Http/Controllers/Polls/Admin/PollApiController.php).

Product rule: freeze polls like museum exhibits **or** own the cleanup story—floating policy is how PMs haunt Slack 😐

---

## Index the queries that actually run 📚

If your migration only has vibes, MySQL spends coffee money on full scans—index with intent, not superstition 👌

The migration **`votes`** adds **`unique(['poll_id', 'guest_id'])`** implicitly indexing lookup patterns you touch every vote. FK columns (`poll_id`, `poll_option_id`, `guest_id`) piggyback InnoDB indexing for joins and deletes. **`polls.slug`** is **unique** in [`database/migrations/2026_04_13_102438_create_polls_table.php`](database/migrations/2026_04_13_102438_create_polls_table.php)—nice for **`Route::get('/polls/{poll:slug}', ...)`** and humane URLs.

Reach for `EXPLAIN` before you spray composite indexes everywhere.

---

## Plan for traffic spikes honestly 📈

If your scaling plan stops at **“horizon scales, bro”**, you owe your future self an apology 🔍 Laravel won’t politely negotiate with **`1M votes`** on a tiny host—you name the weakest layer.

Ask what hits first **for your deployment**: relational write throughput (`votes` + **`poll_options.votes_count`**), **`Cache::lock`** dogpiles, **Pusher / broadcast quotas**, **`ShouldBroadcastNow`** stretching PHP fingers, cheap CPU—the answer should come from **`artisan`/load tests**, not vibes.

**Multi-tenant SaaS** (not modeled here) stays a parallel design: tenant column vs schema vs DB-per-tenant is a compliance and ops tradeoff independent of Laravel version.

---

## Test the whole flow, not just the happy screenshot 🧪

Green CI is nice; angry QA with two browsers incognito hits different [`tests/Feature/PollVotingTest.php`](tests/Feature/PollVotingTest.php) moods—covers duplicates, broadcasts, **`Guest`** identity, **`CastPollVoteAction`** edge cases where applicable, admin option edits versus votes, CSRF/session quirks, etc. Extend it whenever you tighten locking or caching so regressions bruise **`phpunit`** first 🙏

---

## Build trust with plain decisions ❤️

Scratchpad cheat sheet 🎯  

- **`polls.vote` stays honest:** throttle 🛡️, cache lock 🔒, transaction 🧾, unique DB constraint, boring HTTP statuses [`PublicPollController`](app/Http/Controllers/Polls/PublicPollController.php) · [`CastPollVoteAction`](app/Actions/Polls/CastPollVoteAction.php).
- **Feed caches stay purposeful:** **`CachedPollIds`** + **`poll:{id}`** + **`PollObserver`** + surgical vote patches—no **`FLUSH ALL` drama** [`CachedPollIds`](app/Support/CachedPollIds.php) · [`PollObserver`](app/Observers/PollObserver.php).
- **Broadcast after reality matches:** **`Show.vue`** *and* **`Results.vue`** both subscribe—admins deserve live bars too ⚡️ [`VoteCountUpdated`](app/Events/VoteCountUpdated.php).
- **README knows your `.env` secrets** (Pusher/Vite)—future-you sends thanks [`README.md`](../README.md).

A Laravel app that *feels* high-traffic is rarely one genius shortcut—it is stacks of mildly boring safeguards that jokes cannot replace 😄 Ship the paranoia **and** ship the laughs; your queue workers cannot file HR complaints anyway 💼
