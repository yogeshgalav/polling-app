<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const form = useForm({
    title: '',
    options: ['', ''],
});

const clientError = ref('');

function addOption() {
    form.options.push('');
}

function removeOption(index) {
    if (form.options.length <= 2) {
        return;
    }
    form.options.splice(index, 1);
}

function submit() {
    clientError.value = '';
    const options = form.options.map((s) => s.trim()).filter(Boolean);
    if (options.length < 2) {
        clientError.value = 'Enter at least two different choices.';
        return;
    }
    form.title = form.title.trim();
    form.options = options;
    form.post(route('admin.polls.store'));
}
</script>

<template>
    <Head title="New poll" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                New poll
            </h2>
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
                            At least two. Voters will pick one.
                        </p>
                        <ul class="mt-3 space-y-2">
                            <li
                                v-for="(_, i) in form.options"
                                :key="i"
                                class="flex gap-2"
                            >
                                <TextInput
                                    :id="'opt-' + i"
                                    type="text"
                                    class="block w-full flex-1"
                                    v-model="form.options[i]"
                                    :placeholder="'Option ' + (i + 1)"
                                    autocomplete="off"
                                />
                                <SecondaryButton
                                    v-if="form.options.length > 2"
                                    type="button"
                                    class="shrink-0"
                                    @click="removeOption(i)"
                                >
                                    Remove
                                </SecondaryButton>
                            </li>
                        </ul>
                        <InputError
                            class="mt-2"
                            :message="form.errors.options"
                        />
                        <InputError
                            v-for="(_, i) in form.options"
                            :key="'opt-err-' + i"
                            class="mt-1"
                            :message="form.errors['options.' + i]"
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
                            Create poll
                        </PrimaryButton>
                        <Link
                            :href="route('admin.polls.index')"
                            class="text-sm text-gray-600 hover:text-gray-900"
                        >
                            Cancel
                        </Link>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
