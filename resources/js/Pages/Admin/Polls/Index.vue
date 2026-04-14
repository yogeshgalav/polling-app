<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    polls: {
        type: Array,
        required: true,
    },
});

async function copyShareUrl(poll) {
    await navigator.clipboard.writeText(poll.share_url);
}

function createdLabel(iso) {
    if (!iso) {
        return '';
    }
    try {
        return new Date(iso).toLocaleString();
    } catch {
        return iso;
    }
}
</script>

<template>
    <Head title="Polls" />

    <AuthenticatedLayout>
        <template #header>
            <div
                class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
            >
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    Your polls
                </h2>
                <Link
                        :href="route('admin.polls.create')"
                        class="btn-primary"
                    >
                      create poll
                    </Link>
            </div>
        </template>

        <div class="py-10">
            <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                <div
                    v-if="!polls.length"
                    class="rounded-lg border border-dashed border-gray-300 bg-white p-8 text-center text-gray-600"
                >
                    <p>No polls found.</p>
                </div>
                <ul class="divide-y divide-gray-200 rounded-lg border border-gray-200 bg-white">
                    <li
                        v-for="poll in polls"
                        :key="poll.id"
                        class="px-4 py-4 sm:px-5"
                    >
                        <p class="font-medium text-gray-900">
                            {{ poll.title }}
                        </p>
                        <p class="mt-1 text-sm text-gray-500">
                            {{ poll.options_count }} options · created
                            {{ createdLabel(poll.created_at) }}
                        </p>
                        <div
                            class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center"
                        >
                            <label class="sr-only" :for="'share-' + poll.id"
                                >Share link</label
                            >
                            <input
                                :id="'share-' + poll.id"
                                type="text"
                                readonly
                                class="min-w-0 flex-1 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-800"
                                :value="poll.share_url"
                                @focus="$event.target.select()"
                            />
                            <button
                                type="button"
                                class="btn-secondary shrink-0"
                                @click="copyShareUrl(poll)"
                            >
                                Copy link
                            </button>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
