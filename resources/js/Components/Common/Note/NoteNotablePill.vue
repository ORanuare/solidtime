<script setup lang="ts">
import type { Note } from '@/packages/api/src';
import { getNoteNotableEntityName, getNoteNotableLevel, type NoteNotableLevel } from '@/utils/noteNotableLevel';
import { BuildingOffice2Icon, FolderIcon, ListBulletIcon } from '@heroicons/vue/16/solid';
import { computed } from 'vue';
import { twMerge } from 'tailwind-merge';

const props = withDefaults(
    defineProps<{
        note: Note;
        class?: string;
        /** When false, only the level chip (icon + level name) is shown. */
        showEntityName?: boolean;
        size?: 'sm' | 'md';
    }>(),
    {
        class: undefined,
        showEntityName: true,
        size: 'md',
    }
);

const level = computed(() => getNoteNotableLevel(props.note));
const entityName = computed(() => getNoteNotableEntityName(props.note));

const levelVisual: Record<
    NoteNotableLevel,
    { icon: typeof BuildingOffice2Icon; shortLabel: string; chipClass: string; iconClass: string }
> = {
    workspace: {
        icon: BuildingOffice2Icon,
        shortLabel: 'Workspace',
        chipClass:
            'border-violet-500/35 bg-violet-500/10 text-violet-800 dark:border-violet-400/30 dark:bg-violet-500/15 dark:text-violet-100',
        iconClass: 'text-violet-600 dark:text-violet-300',
    },
    project: {
        icon: FolderIcon,
        shortLabel: 'Project',
        chipClass:
            'border-sky-500/40 bg-sky-500/10 text-sky-900 dark:border-sky-400/35 dark:bg-sky-500/15 dark:text-sky-100',
        iconClass: 'text-sky-600 dark:text-sky-300',
    },
    task: {
        icon: ListBulletIcon,
        shortLabel: 'Task',
        chipClass:
            'border-emerald-500/40 bg-emerald-500/10 text-emerald-900 dark:border-emerald-400/35 dark:bg-emerald-500/15 dark:text-emerald-100',
        iconClass: 'text-emerald-600 dark:text-emerald-300',
    },
};

const levelStyle = computed(() => levelVisual[level.value]);

const sizeClasses = computed(() => {
    if (props.size === 'sm') {
        return {
            chip: 'gap-0.5 px-1.5 py-px text-[10px] leading-4',
            icon: 'h-3 w-3',
        };
    }
    return {
        chip: 'gap-1 px-2 py-0.5 text-xs',
        icon: 'h-3.5 w-3.5',
    };
});
</script>

<template>
    <span :class="twMerge('inline-flex min-w-0 max-w-full items-center gap-1.5', props.class)">
        <span
            :class="
                twMerge(
                    'inline-flex shrink-0 items-center rounded-full border font-medium',
                    sizeClasses.chip,
                    levelStyle.chipClass
                )
            "
            :title="note.notable_label">
            <component
                :is="levelStyle.icon"
                :class="twMerge(sizeClasses.icon, 'shrink-0', levelStyle.iconClass)" />
            <span class="whitespace-nowrap">{{ levelStyle.shortLabel }}</span>
        </span>
        <span
            v-if="showEntityName && entityName"
            class="min-w-0 truncate text-text-tertiary"
            :title="entityName">
            {{ entityName }}
        </span>
    </span>
</template>
