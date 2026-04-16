<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    poll: { type: Object, required: true },
});

const form = useForm({
    title: props.poll.title ?? '',
    options: (props.poll.options ?? []).map((o) => ({
        id: o.id,
        label: o.label ?? '',
        votes_count: Number(o.votes_count ?? 0),
    })),
});

const clientError = ref('');

const canRemoveAny = computed(() => form.options.length > 2);

function addOption() {
    form.options.push({ id: null, label: '', votes_count: 0 });
}

function removeOption(index) {
    if (form.options.length <= 2) {
        return;
    }
    if (Number(form.options[index]?.votes_count ?? 0) > 0) {
        return;
    }
    form.options.splice(index, 1);
}

function submit() {
    clientError.value = '';

    const normalized = form.options
        .map((o) => ({
            id: o.id ?? null,
            label: String(o.label ?? '').trim(),
        }))
        .filter((o) => o.label.length > 0);

    if (normalized.length < 2) {
        clientError.value = 'Enter at least two different choices.';
        return;
    }

    const labels = normalized.map((o) => o.label.toLowerCase());
    if (new Set(labels).size !== labels.length) {
        clientError.value = 'Choices must be distinct.';
        return;
    }

    form.title = String(form.title ?? '').trim();

    form.transform((data) => ({
        ...data,
        options: normalized,
    }));

    form.put(route('admin.polls.update', props.poll.slug));
}
</script>

<template>
    <Head title="Edit poll" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-xl font-semibold leading-tight text-gray-800">
                        Edit poll
                    </h2>
                    <p class="mt-1 text-sm text-gray-600">
                        {{ poll.title }}
                    </p>
                </div>
                <a
                    :href="poll.share_url"
                    class="btn-secondary"
                    target="_blank"
                    rel="noreferrer"
                >
                    Open public link
                </a>
            </div>
        </template>

        <div class="py-10">
            <div class="mx-auto max-w-lg px-4 sm:px-6 lg:px-8">
                <form class="space-y-6" @submit.prevent="submit">
                    <div>
                        <InputLabel for="title" value="Question" />
                        <TextInput
                            id="title"
                            type="text"
                            class="mt-1 block w-full"
                            v-model="form.title"
                            required
                            autofocus
                            placeholder="What do you want to ask?"
                            autocomplete="off"
                        />
                        <InputError class="mt-2" :message="form.errors.title" />
                    </div>

                    <div>
                        <InputLabel value="Choices" />
                        <p class="mt-1 text-sm text-gray-500">
                            You can rename choices freely. You can only remove a choice if it has
                            0 votes.
                        </p>

                        <ul class="mt-3 space-y-2">
                            <li
                                v-for="(opt, i) in form.options"
                                :key="opt.id ?? `new-${i}`"
                                class="flex gap-2"
                            >
                                <div class="flex-1">
                                    <TextInput
                                        :id="'opt-' + i"
                                        type="text"
                                        class="block w-full"
                                        v-model="form.options[i].label"
                                        :placeholder="'Option ' + (i + 1)"
                                        autocomplete="off"
                                    />
                                    <p class="mt-1 text-xs text-gray-500 tabular-nums">
                                        Votes: {{ Number(opt.votes_count ?? 0) }}
                                    </p>
                                </div>

                                <SecondaryButton
                                    v-if="canRemoveAny"
                                    type="button"
                                    class="shrink-0"
                                    :disabled="Number(opt.votes_count ?? 0) > 0"
                                    @click="removeOption(i)"
                                >
                                    Remove
                                </SecondaryButton>
                            </li>
                        </ul>

                        <InputError class="mt-2" :message="form.errors.options" />
                        <InputError
                            v-for="(_, i) in form.options"
                            :key="'opt-err-' + i"
                            class="mt-1"
                            :message="form.errors['options.' + i + '.label']"
                        />
                        <p v-if="clientError" class="mt-2 text-sm text-red-600">
                            {{ clientError }}
                        </p>

                        <div class="mt-3">
                            <SecondaryButton type="button" @click="addOption">
                                Add another choice
                            </SecondaryButton>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <PrimaryButton :disabled="form.processing">
                            Save changes
                        </PrimaryButton>
                        <Link
                            :href="route('admin.polls.index')"
                            class="text-sm text-gray-600 hover:text-gray-900"
                        >
                            Cancel
                        </Link>
                        <Link
                            :href="route('admin.polls.results', poll.slug)"
                            class="text-sm text-indigo-700 hover:text-indigo-900"
                        >
                            View results
                        </Link>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

