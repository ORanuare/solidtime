import { cva, type VariantProps } from 'class-variance-authority';

export const timeTrackerButtonVariants = cva(
    'flex items-center justify-center transition focus:outline-0 rounded-full',
    {
        variants: {
            variant: {
                primary:
                    'text-white ring-accent-200/10 focus-visible:ring-ring focus-visible:ring-2 ring-4 sm:ring-[6px]',
                /**
                 * Same ring/halo as the red stop, emerald fill; main tracker only (contrast with stop).
                 */
                complete:
                    'text-white ring-accent-200/10 focus-visible:ring-ring focus-visible:ring-2 ring-4 sm:ring-[6px] bg-emerald-500/80 hover:bg-emerald-500/95 focus:bg-emerald-600/90 dark:bg-emerald-600/80 dark:hover:bg-emerald-500/90 dark:focus:bg-emerald-500/90',
                secondary:
                    'bg-quaternary text-text-tertiary hover:text-text-primary focus:ring-2 focus:ring-border-tertiary',
            },
            size: {
                small: 'w-6 h-6',
                base: 'w-8 h-8',
                large: 'w-11 h-11 hover:scale-110',
            },
            active: {
                true: '',
                false: '',
            },
        },
        compoundVariants: [
            {
                variant: 'primary',
                active: true,
                class: 'bg-red-400/80 hover:bg-red-500/80 focus:bg-red-500/80',
            },
            {
                variant: 'primary',
                active: false,
                class: 'bg-accent-300/70 hover:bg-accent-400/70 focus:bg-accent-700',
            },
        ],
        defaultVariants: {
            variant: 'primary',
            size: 'base',
            active: false,
        },
    }
);

export type TimeTrackerButtonVariantProps = VariantProps<typeof timeTrackerButtonVariants>;

export const timeTrackerButtonIconSizeClass: Record<NonNullable<TimeTrackerButtonVariantProps['size']>, string> =
    {
        small: 'w-2.5 h-2.5',
        base: 'w-3 h-3',
        large: 'w-4 h-4',
    };
