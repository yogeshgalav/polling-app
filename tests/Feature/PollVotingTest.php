<?php

namespace Tests\Feature;

use App\Events\VoteCountUpdated;
use App\Models\Guest;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\User;
use App\Models\Vote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PollVotingTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_vote_successfully(): void
    {
        [$poll, $firstOption, $secondOption] = $this->createPollWithOptions();
        /** @var User $user */
        $user = User::factory()->createOne();
        $deviceId = (string) Str::uuid();
        Event::fake([VoteCountUpdated::class]);

        $response = $this
            ->actingAs($user)
            ->withServerVariables(["HTTP_COOKIE" => "poll_device_id={$deviceId}"])
            ->postJson(route("polls.vote", $poll), [
                "poll_option_id" => $firstOption->id,
            ]);

        $response->assertCreated();
        $response->assertJsonPath("voted_option_id", $firstOption->id);
        $response->assertJsonPath("total_votes", 1);
        $response->assertJsonFragment([
            "id" => $firstOption->id,
            "votes_count" => 1,
        ]);
        $response->assertJsonFragment([
            "id" => $secondOption->id,
            "votes_count" => 0,
        ]);
        Event::assertDispatched(VoteCountUpdated::class, function (
            VoteCountUpdated $event,
        ) use ($poll, $firstOption, $secondOption): bool {
            return $event->pollId === $poll->id &&
                $event->totalVotes === 1 &&
                collect($event->options)->contains(
                    fn (array $row) => $row["id"] === $firstOption->id &&
                        $row["votes_count"] === 1,
                ) &&
                collect($event->options)->contains(
                    fn (array $row) => $row["id"] === $secondOption->id &&
                        $row["votes_count"] === 0,
                );
        });
    }

    public function test_user_duplicate_vote_requests_record_only_one_vote(): void
    {
        [$poll, $firstOption, $secondOption] = $this->createPollWithOptions();
        /** @var User $user */
        $user = User::factory()->createOne();
        $deviceId = (string) Str::uuid();
        Event::fake([VoteCountUpdated::class]);

        $this
            ->actingAs($user)
            ->withServerVariables(["HTTP_COOKIE" => "poll_device_id={$deviceId}"])
            ->postJson(route("polls.vote", $poll), [
                "poll_option_id" => $firstOption->id,
            ])
            ->assertCreated();

        $response = $this
            ->actingAs($user)
            ->withServerVariables(["HTTP_COOKIE" => "poll_device_id={$deviceId}"])
            ->postJson(route("polls.vote", $poll), [
                "poll_option_id" => $secondOption->id,
            ]);

        $response->assertForbidden();
        $response->assertJsonPath("message", "Already voted");
        $this->assertSame(1, Vote::query()->count());
        Event::assertDispatched(VoteCountUpdated::class, 1);
    }

    public function test_show_returns_expected_poll_response_format(): void
    {
        [$poll, $firstOption, $secondOption] = $this->createPollWithOptions();
        /** @var User $user */
        $user = User::factory()->createOne();
        $guest = Guest::query()->create([
            "user_id" => $user->id,
            "ip" => "127.0.0.1",
            "user_agent" => "PHPUnit",
        ]);

        Vote::query()->create([
            "poll_id" => $poll->id,
            "poll_option_id" => $firstOption->id,
            "guest_id" => $guest->id,
        ]);

        PollOption::query()->where("id", $firstOption->id)->update(["votes_count" => 1]);
        PollOption::query()->where("id", $secondOption->id)->update(["votes_count" => 2]);
        $poll->refresh()->load("options");

        $this->actingAs($user)
            ->get(route("polls.show", $poll))
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component("Polls/Show")
                    ->where("poll.id", $poll->id)
                    ->where("poll.title", $poll->title)
                    ->where("poll.slug", $poll->slug)
                    ->where("poll.voted_option_id", $firstOption->id)
                    ->where("poll.total_votes", 3)
                    ->where("poll.options.0.id", $firstOption->id)
                    ->where("poll.options.0.label", $firstOption->label)
                    ->where("poll.options.0.votes_count", 1)
                    ->where("poll.options.1.id", $secondOption->id)
                    ->where("poll.options.1.label", $secondOption->label)
                    ->where("poll.options.1.votes_count", 2),
            );
    }

    public function test_authenticated_vote_without_cookie_still_records_vote(): void
    {
        [$poll, $firstOption] = $this->createPollWithOptions();
        /** @var User $user */
        $user = User::factory()->createOne();
        Event::fake([VoteCountUpdated::class]);

        $this->actingAs($user)
            ->postJson(route("polls.vote", $poll), [
                "poll_option_id" => $firstOption->id,
            ])
            ->assertCreated();

        Event::assertDispatched(VoteCountUpdated::class);
        $this->assertSame(1, Vote::query()->count());
    }

    public function test_guest_can_vote_with_device_cookie(): void
    {
        [$poll, $firstOption] = $this->createPollWithOptions();
        $deviceId = (string) Str::uuid();
        Event::fake([VoteCountUpdated::class]);

        $this->withServerVariables(["HTTP_COOKIE" => "poll_device_id={$deviceId}"])
            ->postJson(route("polls.vote", $poll), ["poll_option_id" => $firstOption->id])
            ->assertCreated();

        Event::assertDispatched(VoteCountUpdated::class);
        $guest = Guest::query()
            ->where("device_id", $deviceId)
            ->whereNull("user_id")
            ->first();
        $this->assertNotNull($guest);
        $this->assertTrue(
            Vote::query()
                ->where("poll_id", $poll->id)
                ->where("guest_id", $guest->id)
                ->where("poll_option_id", $firstOption->id)
                ->exists(),
        );
    }

    public function test_feed_cache_is_written_forever(): void
    {
        $this->app["env"] = "production";

        /** @var User $creator */
        $creator = User::factory()->createOne();
        Poll::query()->create([
            "title" => "Cached poll",
            "slug" => Str::slug("cached-poll-" . Str::random(8)),
            "created_by" => $creator->id,
            "expires_at" => now()->addDay(),
        ]);

        Cache::shouldReceive("rememberForever")
            ->once()
            ->withArgs(function (string $key, callable $callback): bool {
                return $key === "polls:1" && is_callable($callback);
            })
            ->andReturnUsing(fn (string $key, callable $callback) => $callback());

        $this->getJson(route("polls.feed", ["page" => 1]))->assertOk();
    }

    public function test_feed_returns_total_vote_count_for_each_poll(): void
    {
        [$poll, $firstOption, $secondOption] = $this->createPollWithOptions();

        PollOption::query()->where("id", $firstOption->id)->update(["votes_count" => 2]);
        PollOption::query()->where("id", $secondOption->id)->update(["votes_count" => 3]);

        $response = $this->getJson(route("polls.feed"));

        $response->assertOk();
        $response->assertJsonPath("data.0.id", $poll->id);
        $response->assertJsonPath("data.0.total_votes", 5);
    }

    public function test_feed_returns_voted_option_id_for_existing_guest_vote(): void
    {
        [$poll, $firstOption] = $this->createPollWithOptions();
        $deviceId = (string) Str::uuid();
        $guest = Guest::query()->create([
            "user_id" => null,
            "device_id" => $deviceId,
            "ip" => "127.0.0.1",
            "user_agent" => "PHPUnit",
        ]);

        Vote::query()->create([
            "poll_id" => $poll->id,
            "poll_option_id" => $firstOption->id,
            "guest_id" => $guest->id,
        ]);

        $this->withServerVariables(["HTTP_COOKIE" => "poll_device_id={$deviceId}"])
            ->getJson(route("polls.feed"))
            ->assertOk()
            ->assertJsonPath("data.0.id", $poll->id)
            ->assertJsonPath("data.0.voted_option_id", $firstOption->id);
    }

    public function test_vote_requests_are_rate_limited_per_device_id(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();
        $deviceId = (string) Str::uuid();

        $polls = collect(range(1, 4))->map(function () use ($user) {
            $poll = Poll::query()->create([
                "title" => "Favorite language?",
                "slug" => Str::slug("favorite-language-" . Str::random(8)),
                "created_by" => $user->id,
                "expires_at" => now()->addDay(),
            ]);

            $option = PollOption::query()->create([
                "poll_id" => $poll->id,
                "label" => "PHP",
                "votes_count" => 0,
            ]);

            return [$poll, $option];
        });

        $this->actingAs($user)
            ->withServerVariables(["HTTP_COOKIE" => "poll_device_id={$deviceId}"])
            ->postJson(route("polls.vote", $polls[0][0]), [
                "poll_option_id" => $polls[0][1]->id,
            ])
            ->assertCreated();

        $this->actingAs($user)
            ->withServerVariables(["HTTP_COOKIE" => "poll_device_id={$deviceId}"])
            ->postJson(route("polls.vote", $polls[1][0]), [
                "poll_option_id" => $polls[1][1]->id,
            ])
            ->assertCreated();

        $this->actingAs($user)
            ->withServerVariables(["HTTP_COOKIE" => "poll_device_id={$deviceId}"])
            ->postJson(route("polls.vote", $polls[2][0]), [
                "poll_option_id" => $polls[2][1]->id,
            ])
            ->assertCreated();

        $this->actingAs($user)
            ->withServerVariables(["HTTP_COOKIE" => "poll_device_id={$deviceId}"])
            ->postJson(route("polls.vote", $polls[3][0]), [
                "poll_option_id" => $polls[3][1]->id,
            ])
            ->assertStatus(429);
    }

    public function test_guest_vote_is_linked_to_user_after_login(): void
    {
        [$poll, $firstOption] = $this->createPollWithOptions();
        /** @var User $user */
        $user = User::factory()->createOne();
        $deviceId = (string) Str::uuid();

        $this->withServerVariables(["HTTP_COOKIE" => "poll_device_id={$deviceId}"])
            ->postJson(route("polls.vote", $poll), [
                "poll_option_id" => $firstOption->id,
            ])
            ->assertCreated();

        $guestBefore = Guest::query()
            ->where("device_id", $deviceId)
            ->whereNull("user_id")
            ->first();
        $this->assertNotNull($guestBefore);

        $this->withServerVariables(["HTTP_COOKIE" => "poll_device_id={$deviceId}"])
            ->postJson("/api/auth/login", [
                "email" => $user->email,
                "password" => "password",
            ])
            ->assertOk();

        $guestAfter = Guest::query()->find($guestBefore->id);
        $this->assertNotNull($guestAfter);
        $this->assertSame($user->id, $guestAfter->user_id);

        $this->actingAs($user)
            ->withServerVariables(["HTTP_COOKIE" => "poll_device_id={$deviceId}"])
            ->getJson(route("polls.feed"))
            ->assertOk()
            ->assertJsonPath("data.0.voted_option_id", $firstOption->id);
    }

    public function test_guest_vote_is_linked_to_user_after_register(): void
    {
        [$poll, $firstOption] = $this->createPollWithOptions();
        $deviceId = (string) Str::uuid();

        $this->withServerVariables(["HTTP_COOKIE" => "poll_device_id={$deviceId}"])
            ->postJson(route("polls.vote", $poll), [
                "poll_option_id" => $firstOption->id,
            ])
            ->assertCreated();

        $guestBefore = Guest::query()
            ->where("device_id", $deviceId)
            ->whereNull("user_id")
            ->first();
        $this->assertNotNull($guestBefore);

        $email = "new-voter-" . strtolower(Str::random(8)) . "@example.com";
        $response = $this->withServerVariables([
            "HTTP_COOKIE" => "poll_device_id={$deviceId}",
        ])->postJson("/api/auth/register", [
            "name" => "New Voter",
            "email" => $email,
            "password" => "password",
            "password_confirmation" => "password",
        ]);

        $response->assertOk();

        $user = User::query()->where("email", $email)->first();
        $this->assertNotNull($user);

        $guestAfter = Guest::query()->find($guestBefore->id);
        $this->assertNotNull($guestAfter);
        $this->assertSame($user->id, $guestAfter->user_id);

        $this->actingAs($user)
            ->withServerVariables(["HTTP_COOKIE" => "poll_device_id={$deviceId}"])
            ->getJson(route("polls.feed"))
            ->assertOk()
            ->assertJsonPath("data.0.voted_option_id", $firstOption->id);
    }

    private function createPollWithOptions(): array
    {
        /** @var User $creator */
        $creator = User::factory()->createOne();
        $poll = Poll::query()->create([
            "title" => "Favorite language?",
            "slug" => Str::slug("favorite-language-" . Str::random(8)),
            "created_by" => $creator->id,
            "expires_at" => now()->addDay(),
        ]);

        $firstOption = PollOption::query()->create([
            "poll_id" => $poll->id,
            "label" => "PHP",
            "votes_count" => 0,
        ]);

        $secondOption = PollOption::query()->create([
            "poll_id" => $poll->id,
            "label" => "JavaScript",
            "votes_count" => 0,
        ]);

        return [$poll, $firstOption, $secondOption];
    }
}
