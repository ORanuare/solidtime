import { ref, watch } from 'vue';

const isTimerFocusOpen = ref(false);
/** Pixel values for transform-origin (growing animation from the trigger control). */
const transformOrigin = ref('50% 45%');

watch(
    isTimerFocusOpen,
    (open) => {
        document.body.style.overflow = open ? 'hidden' : '';
    },
    { flush: 'post' }
);

export function useTimerFocus() {
    function open(anchor?: HTMLElement | null) {
        if (anchor) {
            const r = anchor.getBoundingClientRect();
            transformOrigin.value = `${r.left + r.width / 2}px ${r.top + r.height / 2}px`;
        } else {
            transformOrigin.value = '50% 45%';
        }
        isTimerFocusOpen.value = true;
    }

    function close() {
        isTimerFocusOpen.value = false;
    }

    return {
        isTimerFocusOpen,
        transformOrigin,
        open,
        close,
    };
}
