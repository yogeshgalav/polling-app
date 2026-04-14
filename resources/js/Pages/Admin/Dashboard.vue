<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Admin Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Admin Dashboard
            </h2>
        </template>
        <div class="py-12">
                <div class="mx-auto  max-w-7xl sm:px-6 lg:px-8">
                    <Link
                        :href="route('admin.polls.index')"
                        class="btn-primary"
                    >
                        Polls ->
                    </Link>
                </div>
        </div>
    </AuthenticatedLayout>
</template>
