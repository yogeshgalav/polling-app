<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\User;
use App\Models\Vote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;
use Illuminate\Contracts\Cache\LockTimeoutException;

class PollVotingTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_vote_successfully(): void
    {
        [$poll, $firstOption, $secondOption] = $this->createPollWithOptions();
        /** @var User $user */
        $user = User::factory()->createOne();

        $response = $this
            ->actingAs($user)
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
        $this->assertDatabaseCount("votes", 1);
        $this->assertDatabaseHas("votes", [
            "poll_id" => $poll->id,
            "poll_option_id" => $firstOption->id,
        ]);
        $this->assertDatabaseHas("poll_options", [
            "id" => $firstOption->id,
            "votes_count" => 1,
        ]);
    }

    public function test_user_cannot_vote_more_than_once_on_the_same_poll(): void
    {
        [$poll, $firstOption, $secondOption] = $this->createPollWithOptions();
        /** @var User $user */
        $user = User::factory()->createOne();

        $this
            ->actingAs($user)
            ->postJson(route("polls.vote", $poll), [
                "poll_option_id" => $firstOption->id,
            ])
            ->assertCreated();

        $response = $this
            ->actingAs($user)
            ->postJson(route("polls.vote", $poll), [
                "poll_option_id" => $secondOption->id,
            ]);

        $response->assertStatus(403)->assertJson([
            "message" => "Already voted",
        ]);
        $this->assertEquals(1, Vote::query()->where("poll_id", $poll->id)->count());
        $this->assertDatabaseHas("poll_options", [
            "id" => $firstOption->id,
            "votes_count" => 1,
        ]);
        $this->assertDatabaseHas("poll_options", [
            "id" => $secondOption->id,
            "votes_count" => 0,
        ]);
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
            ->withServerVariables(["REMOTE_ADDR" => "127.0.0.1"])
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

    public function test_vote_returns_processing_message_when_lock_times_out_for_same_user(): void
    {
        [$poll, $firstOption] = $this->createPollWithOptions();
        /** @var User $user */
        $user = User::factory()->createOne();
        $guest = Guest::query()->create([
            "user_id" => $user->id,
            "ip" => "127.0.0.1",
            "user_agent" => "PHPUnit",
        ]);

        Cache::shouldReceive("lock")
            ->once()
            ->withArgs(function (string $key, int $seconds) use ($poll, $guest): bool {
                return $key === "poll:{$poll->id}:guest:{$guest->id}:vote" && $seconds === 10;
            })
            ->andReturn(
                Mockery::mock()->shouldReceive("block")->once()->andThrow(new LockTimeoutException())->getMock(),
            );

        $this->actingAs($user)
            ->withServerVariables(["REMOTE_ADDR" => "127.0.0.1"])
            ->postJson(route("polls.vote", $poll), [
                "poll_option_id" => $firstOption->id,
            ])
            ->assertStatus(429)
            ->assertJson([
                "message" => "Vote is being processed. Please retry.",
            ]);
    }

    public function test_vote_returns_processing_message_when_lock_times_out_for_same_ip_guest(): void
    {
        [$poll, $firstOption] = $this->createPollWithOptions();
        $guest = Guest::query()->create([
            "user_id" => null,
            "ip" => "127.0.0.9",
            "user_agent" => "PHPUnit",
        ]);

        Cache::shouldReceive("lock")
            ->once()
            ->withArgs(function (string $key, int $seconds) use ($poll, $guest): bool {
                return $key === "poll:{$poll->id}:guest:{$guest->id}:vote" && $seconds === 10;
            })
            ->andReturn(
                Mockery::mock()->shouldReceive("block")->once()->andThrow(new LockTimeoutException())->getMock(),
            );

        $this->withServerVariables(["REMOTE_ADDR" => "127.0.0.9"])
            ->postJson(route("polls.vote", $poll), [
                "poll_option_id" => $firstOption->id,
            ])
            ->assertStatus(429)
            ->assertJson([
                "message" => "Vote is being processed. Please retry.",
            ]);
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

    public function test_vote_requests_are_rate_limited_per_ip(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();

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
            ->withServerVariables(["REMOTE_ADDR" => "127.0.0.1"])
            ->postJson(route("polls.vote", $polls[0][0]), [
                "poll_option_id" => $polls[0][1]->id,
            ])
            ->assertCreated();

        $this->actingAs($user)
            ->withServerVariables(["REMOTE_ADDR" => "127.0.0.1"])
            ->postJson(route("polls.vote", $polls[1][0]), [
                "poll_option_id" => $polls[1][1]->id,
            ])
            ->assertCreated();

        $this->actingAs($user)
            ->withServerVariables(["REMOTE_ADDR" => "127.0.0.1"])
            ->postJson(route("polls.vote", $polls[2][0]), [
                "poll_option_id" => $polls[2][1]->id,
            ])
            ->assertCreated();

        $this->actingAs($user)
            ->withServerVariables(["REMOTE_ADDR" => "127.0.0.1"])
            ->postJson(route("polls.vote", $polls[3][0]), [
                "poll_option_id" => $polls[3][1]->id,
            ])
            ->assertStatus(429);
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
