<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { DynamicScroller, DynamicScrollerItem } from 'vue-virtual-scroller';
import 'vue-virtual-scroller/dist/vue-virtual-scroller.css';
import PollCard from '@/Components/PollCard.vue';

const props = defineProps({
    polls: { type: Object, required: true },
});

const page = usePage();

const items = ref([...props.polls.data]);
const currentPage = ref(props.polls.current_page);
const lastPage = ref(props.polls.last_page);
const loadingMore = ref(false);
const loadError = ref(null);
const votingPollId = ref(null);
const voteError = ref(null);

const scrollerRef = ref(null);
let scrollEl = null;
const subscribedPollIds = new Set();

function onScroll(e) {
    const el = e.target;
    if (el.scrollHeight - el.scrollTop - el.clientHeight > 320) return;
    loadMore();
}

function applyVoteCountUpdate(pollId, payload) {
    const i = items.value.findIndex((p) => p.id === pollId);
    if (i === -1 || !payload) {
        return;
    }

    const current = items.value[i];
    const optionsById = new Map(payload.options.map((option) => [option.id, option]));
    items.value[i] = {
        ...current,
        total_votes: payload.totalVotes,
        options: current.options.map((option) => {
            const updated = optionsById.get(option.id);
            return updated ? { ...option, votes_count: updated.votes_count } : option;
        }),
    };
}

function syncBroadcastSubscriptions() {
    if (!window.Echo) {
        return;
    }

    for (const poll of items.value) {
        if (subscribedPollIds.has(poll.id)) {
            continue;
        }

        window.Echo.channel(`polls.${poll.id}`).listen('.votes.updated', (payload) => {
            applyVoteCountUpdate(poll.id, payload);
        });
        subscribedPollIds.add(poll.id);
    }
}

async function loadMore() {
    if (loadingMore.value || currentPage.value >= lastPage.value) return;

    loadingMore.value = true;
    loadError.value = null;
    try {
        const { data } = await window.axios.get(route('polls.feed'), {
            params: { page: currentPage.value + 1 },
        });
        items.value = items.value.concat(data.data);
        currentPage.value = data.current_page;
        lastPage.value = data.last_page;
        syncBroadcastSubscriptions();
    } catch (e) {
        loadError.value =
            e.response?.data?.message ?? 'Could not load more polls. Try again.';
    } finally {
        loadingMore.value = false;
    }
}

onMounted(() => {
    nextTick(() => {
        const root = scrollerRef.value?.$el;
        if (!root) return;
        scrollEl = root;
        scrollEl.addEventListener('scroll', onScroll, { passive: true });
    });

    syncBroadcastSubscriptions();
});

onBeforeUnmount(() => {
    if (scrollEl) {
        scrollEl.removeEventListener('scroll', onScroll);
        scrollEl = null;
    }

    if (window.Echo) {
        for (const poll of items.value) {
            window.Echo.leave(`polls.${poll.id}`);
        }
    }

    subscribedPollIds.clear();
});

async function vote(poll, optionId) {
    if (!poll.is_open || poll.voted_option_id || votingPollId.value) return;

    voteError.value = null;
    const i = items.value.findIndex((p) => p.id === poll.id);
    if (i === -1) {
        return;
    }
    const current = items.value[i];
    const previousVotedOptionId = current.voted_option_id;
    items.value[i] = {
        ...current,
        voted_option_id: optionId,
    };
    votingPollId.value = poll.id;
    try {
        const { data } = await window.axios.post(
            route('polls.vote', { poll: poll.slug }),
            { poll_option_id: optionId },
        );

        const responseIndex = items.value.findIndex((p) => p.id === poll.id);
        if (responseIndex !== -1) {
            const latest = items.value[responseIndex];
            const optionsById = new Map(
                (data.options ?? []).map((option) => [option.id, option]),
            );
            items.value[responseIndex] = {
                ...latest,
                voted_option_id: data.voted_option_id ?? optionId,
                total_votes: data.total_votes ?? latest.total_votes,
                options: latest.options.map((option) => {
                    const updated = optionsById.get(option.id);
                    return updated
                        ? { ...option, votes_count: updated.votes_count }
                        : option;
                }),
            };
        }
    } catch (e) {
        const rollbackIndex = items.value.findIndex((p) => p.id === poll.id);
        if (rollbackIndex !== -1) {
            items.value[rollbackIndex] = {
                ...items.value[rollbackIndex],
                voted_option_id: previousVotedOptionId,
            };
        }
        voteError.value =
            e.response?.data?.message ?? 'Could not submit your vote. Try again.';
    } finally {
        votingPollId.value = null;
    }
}
</script>

<template>
    <Head title="Polls" />

    <div class="min-h-screen bg-slate-50 text-slate-900">
        <header class="border-b border-slate-200 bg-white">
            <div
                class="mx-auto flex max-w-3xl items-center justify-between px-4 py-4 sm:px-6"
            >
                <h1 class="text-2xl font-semibold">Polls</h1>
                <nav class="flex gap-3 text-sm">
                    <Link
                        v-if="page.props.auth.user"
                        :href="route('dashboard')"
                        class="rounded-md px-3 py-2 font-medium text-slate-700 ring-1 ring-slate-200 hover:bg-slate-50"
                    >
                        Dashboard
                    </Link>
                    <Link
                        v-else
                        :href="route('login')"
                        class="rounded-md px-3 py-2 font-medium text-slate-700 ring-1 ring-slate-200 hover:bg-slate-50"
                    >
                        Log in
                    </Link>
                </nav>
            </div>
        </header>

        <main class="mx-auto max-w-3xl px-4 pb-12 pt-6 sm:px-6">
            <p
                v-if="voteError"
                class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900"
            >
                {{ voteError }}
            </p>

            <div
                class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm"
                style="height: calc(100vh - 11rem)"
            >
                <DynamicScroller
                    ref="scrollerRef"
                    :items="items"
                    key-field="id"
                    :min-item-size="240"
                    class="h-full"
                >
                    <template #default="{ item, index, active }">
                        <DynamicScrollerItem
                            :item="item"
                            :active="active"
                            :data-index="index"
                            :size-dependencies="[
                                item.title,
                                item.options?.length,
                                item.voted_option_id,
                                item.is_open,
                            ]"
                        >
                            <PollCard
                                :poll="item"
                                :voting="votingPollId === item.id"
                                @vote="(id) => vote(item, id)"
                            />
                        </DynamicScrollerItem>
                    </template>
                </DynamicScroller>
            </div>

            <p v-if="loadingMore" class="mt-4 text-center text-sm text-slate-500">
                Loading more…
            </p>
            <p v-if="loadError" class="mt-2 text-center text-sm text-red-600">
                {{ loadError }}
            </p>
            <p
                v-if="!items.length && !loadingMore"
                class="mt-8 text-center text-slate-500"
            >
                No polls yet. Check back later.
            </p>
        </main>
    </div>
</template>
