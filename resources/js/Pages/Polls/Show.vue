<script setup>
import PollCard from '@/Components/PollCard.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    poll: { type: Object, required: true },
});

const page = usePage();
const poll = ref(props.poll);
const voting = ref(false);
const err = ref(null);
const channelName = `polls.${props.poll.id}`;

function applyVoteCountUpdate(payload) {
    if (!poll.value || !payload) {
        return;
    }

    poll.value.total_votes = payload.totalVotes;
    const optionsById = new Map(payload.options.map((option) => [option.id, option]));
    poll.value.options = poll.value.options.map((option) => {
        const updated = optionsById.get(option.id);
        return updated ? { ...option, votes_count: updated.votes_count } : option;
    });
}

onMounted(() => {
    window.Echo?.channel(channelName).listen('.votes.updated', applyVoteCountUpdate);
});

onBeforeUnmount(() => {
    window.Echo?.leave(channelName);
});

async function onVote(optionId) {
    const p = poll.value;
    if (!p.is_open || p.voted_option_id || voting.value) {
        return;
    }
    err.value = null;
    const previousVotedOptionId = p.voted_option_id;
    poll.value.voted_option_id = optionId;
    voting.value = true;
    try {
        const { data } = await window.axios.post(route('polls.vote', { poll: p.slug }), {
            poll_option_id: optionId,
        });

        poll.value.voted_option_id = data.voted_option_id ?? optionId;
        poll.value.total_votes = data.total_votes ?? poll.value.total_votes;
        const optionsById = new Map((data.options ?? []).map((option) => [option.id, option]));
        poll.value.options = poll.value.options.map((option) =>
            optionsById.has(option.id)
                ? { ...option, votes_count: optionsById.get(option.id).votes_count }
                : option,
        );
    } catch (e) {
        poll.value.voted_option_id = previousVotedOptionId;
        err.value =
            e.response?.data?.message || 'Could not submit your vote. Try again.';
    } finally {
        voting.value = false;
    }
}
</script>

<template>
    <Head :title="poll.title" />

    <div class="page">
        <header class="top">
            <Link :href="route('polls.index')" class="back">← All polls</Link>
            <nav v-if="page.props.auth.user">
                <Link :href="route('profile.edit')" class="navbtn">Profile</Link>
                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    class="navbtn"
                >
                    Log out
                </Link>
            </nav>
            <nav v-else>
                <Link :href="route('login')" class="navbtn">Log in</Link>
                <Link :href="route('register')" class="navbtn">Register</Link>
            </nav>
        </header>

        <main>
            <p v-if="err" class="warn">{{ err }}</p>

            <div class="card-wrap">
                <PollCard :poll="poll" :voting="voting" @vote="onVote" />
            </div>
        </main>
    </div>
</template>

<style scoped>
.page {
    min-height: 100vh;
    background: #f8fafc;
    color: #0f172a;
}
.top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid #e2e8f0;
    background: #fff;
}
.top nav {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
}
.back,
.top nav a,
.top nav .navbtn {
    font-size: 0.875rem;
    color: #64748b;
    text-decoration: none;
}
.back:hover,
.top nav a:hover,
.top nav .navbtn:hover {
    color: #0f172a;
}
.top nav a,
.top nav .navbtn {
    display: inline-block;
    padding: 0.35rem 0.75rem;
    border: 1px solid #e2e8f0;
    border-radius: 0.35rem;
    background: transparent;
}
main {
    max-width: 48rem;
    margin: 0 auto;
    padding: 1.5rem 1.25rem 3rem;
}
.warn {
    margin-bottom: 1rem;
    padding: 0.5rem 0.75rem;
    font-size: 0.875rem;
    color: #78350f;
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 0.35rem;
}
.card-wrap {
    overflow: hidden;
    border-radius: 0.75rem;
    border: 1px solid #e2e8f0;
    background: #fff;
    box-shadow: 0 1px 2px rgb(0 0 0 / 0.05);
}
</style>
