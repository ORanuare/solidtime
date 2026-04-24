<script setup lang="ts">
import TableHeading from '@/Components/Common/TableHeading.vue';
import { ClipboardDocumentListIcon } from '@heroicons/vue/20/solid';
</script>

<template>
    <TableHeading>
        <div class="py-1.5 pr-3 text-left text-text-tertiary pl-4 sm:pl-6 lg:pl-8 3xl:pl-12">
            Task Name
        </div>
        <div
            class="px-1 py-1.5 text-center text-text-tertiary w-full min-w-16 flex items-center justify-center gap-0.5">
            <ClipboardDocumentListIcon class="h-4 w-4 text-icon-default opacity-70" aria-hidden="true" />
            <span class="sr-only">Notes, with count in each row</span>
        </div>
        <div class="px-3 py-1.5 text-left text-text-tertiary">Total Time</div>
        <div class="px-3 py-1.5 text-left text-text-tertiary">Progress</div>
        <div class="px-3 py-1.5 text-left text-text-tertiary">Status</div>
        <div class="relative py-1.5 pl-3 pr-4 sm:pr-6 lg:pr-8 3xl:pr-12">
            <span class="sr-only">Edit</span>
        </div>
    </TableHeading>
</template>

<style scoped></style>
