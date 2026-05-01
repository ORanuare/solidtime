<script setup lang="ts">
import { buttonVariants } from '@/packages/ui/src';
import { AlertDialogCancel, type AlertDialogCancelProps } from 'reka-ui';
import { computed, type HTMLAttributes } from 'vue';
import { twMerge, type ClassNameValue } from 'tailwind-merge';

const props = defineProps<AlertDialogCancelProps & { class?: HTMLAttributes['class'] }>();

const delegatedProps = computed(() => {
    const { class: _, ...delegated } = props;

    return delegated;
});
</script>

<template>
    <AlertDialogCancel
        v-bind="delegatedProps"
        :class="
            twMerge(buttonVariants({ variant: 'outline' }), 'mt-2 sm:mt-0', props.class as ClassNameValue)
        ">
        <slot />
    </AlertDialogCancel>
</template>
