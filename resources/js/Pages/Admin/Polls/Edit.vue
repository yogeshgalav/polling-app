<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    poll: { type: Object, required: true },
});

const form = ref({
    title: props.poll.title ?? '',
    options: (props.poll.options ?? []).map((o) => ({
        id: o.id,
        label: o.label ?? '',
        votes_count: Number(o.votes_count ?? 0),
    })),
});

const clientError = ref('');
const processing = ref(false);
const errors = ref({});

const canRemoveAny = computed(() => form.value.options.length > 2);

function addOption() {
    form.value.options.push({ id: null, label: '', votes_count: 0 });
}

function removeOption(index) {
    if (form.value.options.length <= 2) {
        return;
    }
    if (Number(form.value.options[index]?.votes_count ?? 0) > 0) {
        return;
    }
    form.value.options.splice(index, 1);
}

function setErrors(nextErrors) {
    errors.value = nextErrors ?? {};
}

async function submit() {
    clientError.value = '';
    setErrors({});

    const normalized = form.value.options
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

    const payload = {
        title: String(form.value.title ?? '').trim(),
        options: normalized,
    };

    processing.value = true;
    try {
        const { data } = await window.axios.put(
            route('admin.api.polls.update', props.poll.slug),
            payload,
        );
        router.visit(data?.redirect ?? route('admin.polls.index'));
    } catch (e) {
        if (e.response?.status === 422) {
            setErrors(e.response?.data?.errors ?? {});
            clientError.value = e.response?.data?.message ?? '';
            return;
        }
        clientError.value = e.response?.data?.message ?? 'Could not save poll. Try again.';
    } finally {
        processing.value = false;
    }
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
                        <InputError class="mt-2" :message="errors.title" />
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

                        <InputError class="mt-2" :message="errors.options" />
                        <InputError
                            v-for="(_, i) in form.options"
                            :key="'opt-err-' + i"
                            class="mt-1"
                            :message="errors['options.' + i + '.label']"
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
                        <PrimaryButton :disabled="processing">
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

