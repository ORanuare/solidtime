<script setup lang="ts">
import TableHeading from '@/Components/Common/TableHeading.vue';
</script>

<template>
    <TableHeading>
        <div
            class="py-1.5 pr-3 text-left text-text-tertiary pl-4 sm:pl-6 lg:pl-8 3xl:pl-12 flex items-center">
            Note
        </div>
        <div class="px-3 py-1.5 text-left text-text-tertiary min-w-0 flex items-center">
            Attached to
        </div>
        <div class="px-3 py-1.5 text-left text-text-tertiary flex items-center">Visibility</div>
        <div class="pl-3 pr-2 sm:pr-3 py-1.5 text-left text-text-tertiary flex items-center">
            Last updated
        </div>
        <div
            class="relative py-1.5 pl-2 pr-4 sm:pr-6 lg:pr-8 3xl:pr-12 flex items-center justify-end">
            <span class="sr-only">Actions</span>
        </div>
    </TableHeading>
</template>
