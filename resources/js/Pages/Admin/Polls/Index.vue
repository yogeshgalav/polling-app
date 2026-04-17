<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    polls: {
        type: Array,
        required: true,
    },
});

const polls = ref([...props.polls]);
const deletingSlug = ref(null);
const deleteError = ref('');

async function copyShareUrl(poll) {
    await navigator.clipboard.writeText(poll.share_url);
}

function confirmDelete(poll) {
    return window.confirm(`Delete "${poll.title}"? This cannot be undone.`);
}

async function destroyPoll(poll) {
    deleteError.value = '';
    if (!confirmDelete(poll)) {
        return;
    }

    deletingSlug.value = poll.slug;
    try {
        await window.axios.delete(route('admin.api.polls.destroy', poll.slug));
        polls.value = polls.value.filter((p) => p.slug !== poll.slug);
    } catch (e) {
        deleteError.value =
            e.response?.data?.message ?? 'Could not delete poll. Try again.';
    } finally {
        deletingSlug.value = null;
    }
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
                <p
                    v-if="deleteError"
                    class="mb-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900"
                >
                    {{ deleteError }}
                </p>
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

                            <div class="flex flex-wrap gap-2 sm:ml-auto">
                                <Link
                                    :href="route('admin.polls.results', poll.slug)"
                                    class="btn-secondary"
                                >
                                    Results
                                </Link>
                                <Link
                                    v-if="!poll.has_votes"
                                    :href="route('admin.polls.edit', poll.slug)"
                                    class="btn-secondary"
                                >
                                    Edit
                                </Link>
                                <button
                                    v-else
                                    type="button"
                                    class="btn-secondary opacity-50 cursor-not-allowed"
                                    disabled
                                    title="This poll already has votes and can't be edited."
                                >
                                    Edit
                                </button>
                                <button
                                    type="button"
                                    class="btn-danger"
                                    :disabled="deletingSlug === poll.slug"
                                    @click="destroyPoll(poll)"
                                >
                                    Delete
                                </button>
                            </div>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
