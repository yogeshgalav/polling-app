<script setup>
import { computed } from 'vue';

const props = defineProps({
    poll: {
        type: Object,
        required: true,
    },
    voting: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['vote']);

function voteCount(option) {
    return Number(option.votes_count ?? 0);
}

const hasVoteCounts = computed(() =>
    props.poll.options.every((option) => option.votes_count !== undefined && option.votes_count !== null),
);

/** Distinct voters for this poll; always present from API and kept in sync via broadcast on Show/Index. */
const totalPeopleVoted = computed(() => Number(props.poll.total_votes) || 0);

function percent(option, poll) {
    const totalVotes = Number(poll.total_votes) || 0;
    if (!totalVotes) {
        return 0;
    }
    return Math.round((voteCount(option) / totalVotes) * 100);
}

function formatDate(iso) {
    if (!iso) {
        return null;
    }
    try {
        return new Date(iso).toLocaleString();
    } catch {
        return null;
    }
}
</script>

<template>
    <article class="border-b border-slate-100 p-4 sm:p-5">
        <div class="flex items-start justify-between gap-3">
            <h2 class="text-lg font-semibold leading-snug text-slate-900">
                {{ poll.title }}
            </h2>
            <span
                v-if="!poll.is_open"
                class="shrink-0 rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600"
            >
                Closed
            </span>
        </div>
        <p v-if="formatDate(poll.expires_at)" class="mt-1 text-xs text-slate-500">
            <template v-if="poll.is_open"
                >Ends {{ formatDate(poll.expires_at) }}
            </template>
            <template v-else>Ended {{ formatDate(poll.expires_at) }}</template>
        </p>

        <ul class="mt-4 space-y-2">
            <li v-for="opt in poll.options" :key="opt.id">
                <button
                    type="button"
                    class="flex w-full flex-col gap-1 rounded-lg border px-3 py-2.5 text-left transition"
                    :class="[
                        poll.voted_option_id === opt.id
                            ? 'border-indigo-500 bg-indigo-50/80 ring-1 ring-indigo-500'
                            : 'border-slate-200 hover:border-slate-300 hover:bg-slate-50',
                        !poll.is_open || poll.voted_option_id
                            ? 'cursor-default'
                            : 'cursor-pointer',
                    ]"
                    :disabled="
                        !poll.is_open ||
                        !!poll.voted_option_id ||
                        voting
                    "
                    @click="emit('vote', opt.id)"
                >
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-sm font-medium text-slate-800">{{
                            opt.label
                        }}</span>
                        <span
                            v-if="hasVoteCounts"
                            class="text-xs tabular-nums text-slate-500"
                        >
                            {{ percent(opt, poll) }}% · {{ voteCount(opt) }}
                        </span>
                    </div>
                    <div
                        v-if="hasVoteCounts"
                        class="h-1.5 w-full overflow-hidden rounded-full bg-slate-100"
                    >
                        <div
                            class="h-full rounded-full bg-indigo-500 transition-[width]"
                            :style="{ width: percent(opt, poll) + '%' }"
                        />
                    </div>
                </button>
            </li>
        </ul>

        <p class="mt-3 text-xs text-slate-500 tabular-nums">
            <template v-if="totalPeopleVoted === 0">No votes yet.</template>
            <template v-else-if="totalPeopleVoted === 1">1 person has voted.</template>
            <template v-else>{{ totalPeopleVoted }} people have voted.</template>
        </p>
    </article>
</template>
