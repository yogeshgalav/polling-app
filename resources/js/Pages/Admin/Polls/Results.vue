<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    poll: { type: Object, required: true },
});

function percent(votes, total) {
    const t = Number(total) || 0;
    if (!t) return 0;
    return Math.round((Number(votes) / t) * 100);
}
</script>

<template>
    <Head :title="`Results · ${poll.title}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-xl font-semibold leading-tight text-gray-800">
                        Results
                    </h2>
                    <p class="mt-1 text-sm text-gray-600">
                        {{ poll.title }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link :href="route('admin.polls.edit', poll.slug)" class="btn-secondary">
                        Edit
                    </Link>
                    <a
                        :href="poll.share_url"
                        class="btn-secondary"
                        target="_blank"
                        rel="noreferrer"
                    >
                        Open public link
                    </a>
                </div>
            </div>
        </template>

        <div class="py-10">
            <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                <div class="rounded-lg border border-gray-200 bg-white p-5">
                    <p class="text-sm text-gray-600 tabular-nums">
                        <template v-if="poll.total_votes === 0">No votes yet.</template>
                        <template v-else-if="poll.total_votes === 1">1 person has voted.</template>
                        <template v-else>{{ poll.total_votes }} people have voted.</template>
                    </p>

                    <ul class="mt-4 space-y-3">
                        <li
                            v-for="opt in poll.options"
                            :key="opt.id"
                            class="rounded-lg border border-gray-200 p-4"
                        >
                            <div class="flex items-center justify-between gap-3">
                                <p class="text-sm font-medium text-gray-900">
                                    {{ opt.label }}
                                </p>
                                <p class="text-xs text-gray-600 tabular-nums">
                                    {{ percent(opt.votes_count, poll.total_votes) }}% ·
                                    {{ opt.votes_count }}
                                </p>
                            </div>
                            <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-gray-100">
                                <div
                                    class="h-full rounded-full bg-indigo-600 transition-[width]"
                                    :style="{
                                        width:
                                            percent(opt.votes_count, poll.total_votes) + '%',
                                    }"
                                />
                            </div>
                        </li>
                    </ul>

                    <div class="mt-5">
                        <Link
                            :href="route('admin.polls.index')"
                            class="text-sm text-gray-600 hover:text-gray-900"
                        >
                            Back to polls
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

