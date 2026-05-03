<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { twMerge } from 'tailwind-merge';

defineProps<{
    href?: string;
}>();
</script>

<template>
    <!--
        Use Link as="div": rows contain buttons/dropdowns. An <a> wrapping interactive
        controls is invalid HTML and can still navigate when clicking those controls.
        Inertia Link still performs visits from the root click handler on the div.
    -->
    <Link
        v-if="href"
        as="div"
        :href="href"
        role="row"
        :class="
            twMerge(
                'contents group [&>*]:transition [&>*]:border-row-separator [&>*]:bg-row-background [&>*]:border-b',
                '[&>*]:cursor-pointer [&>*]:hover:bg-white/5'
            )
        ">
        <slot></slot>
    </Link>
    <div
        v-else
        role="row"
        :class="
            twMerge(
                'contents group [&>*]:transition [&>*]:border-row-separator [&>*]:bg-row-background [&>*]:border-b'
            )
        ">
        <slot></slot>
    </div>
</template>

<style scoped></style>
