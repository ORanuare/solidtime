<script setup lang="ts">
import { basicSetup, EditorView } from 'codemirror';
import { EditorState, type Extension } from '@codemirror/state';
import { markdown } from '@codemirror/lang-markdown';
import { placeholder } from '@codemirror/view';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import {
    Bold,
    Code,
    Heading1,
    Heading2,
    Heading3,
    Italic,
    Link,
    List,
    ListOrdered,
    Minus,
    Quote,
    SquareCode,
    Strikethrough,
} from 'lucide-vue-next';
import {
    insertBold,
    insertFencedCodeBlock,
    insertHorizontalRule,
    insertInlineCode,
    insertItalic,
    insertLink,
    insertStrikethrough,
    setHeadingLevel,
    toggleBlockquote,
    toggleBulletList,
    toggleOrderedList,
} from '@/utils/noteMarkdownEditorActions';

const model = defineModel<string>({ default: '' });

const props = withDefaults(
    defineProps<{
        /** Shown when the document is empty. */
        placeholderText?: string;
        /** When false, hide the formatting toolbar (e.g. inline note rows). */
        showToolbar?: boolean;
        /** Shorter editor height for inline cards. */
        compact?: boolean;
    }>(),
    {
        placeholderText: 'Use the toolbar for headings, lists, and formatting…',
        showToolbar: true,
        compact: false,
    }
);

const host = ref<HTMLDivElement | null>(null);
let view: EditorView | null = null;

function run(fn: (v: EditorView) => void) {
    if (!view) {
        return;
    }
    fn(view);
}

const appTheme: Extension = EditorView.theme({
    '&': {
        fontSize: '0.875rem',
    },
    '.cm-scroller': {
        minHeight: '240px',
    },
    '.cm-content': {
        color: 'var(--foreground)',
        caretColor: 'var(--foreground)',
    },
    '.cm-gutters': {
        backgroundColor: 'transparent',
        color: 'var(--muted-foreground)',
        borderRight: '1px solid var(--border)',
    },
    '.cm-activeLineGutter': {
        backgroundColor: 'transparent',
    },
    '&.cm-editor.cm-focused': {
        outline: '2px solid var(--ring)',
        outlineOffset: '2px',
    },
    '&.cm-editor.cm-focused .cm-cursor': {
        borderLeftColor: 'var(--foreground)',
    },
    '.cm-activeLine': {
        backgroundColor: 'color-mix(in oklab, var(--muted) 50%, transparent)',
    },
    '.cm-placeholder': {
        color: 'var(--muted-foreground)',
    },
    '.cm-selectionBackground, ::selection': {
        backgroundColor: 'color-mix(in oklab, var(--ring) 35%, transparent) !important',
    },
    '&.cm-editor': {
        backgroundColor: 'var(--card)',
    },
});

const compactTheme: Extension = EditorView.theme({
    '.cm-scroller': {
        minHeight: '120px',
    },
});

function buildExtensions(): Extension[] {
    const list: Extension[] = [
        basicSetup,
        markdown(),
        appTheme,
        ...(props.compact ? [compactTheme] : []),
        EditorView.lineWrapping,
    ];
    if (props.placeholderText) {
        list.push(placeholder(props.placeholderText));
    }
    list.push(
        EditorView.updateListener.of((update) => {
            if (update.docChanged) {
                model.value = update.state.doc.toString();
            }
        })
    );
    return list;
}

onMounted(() => {
    if (!host.value) {
        return;
    }
    const state = EditorState.create({
        doc: model.value,
        extensions: buildExtensions(),
    });
    view = new EditorView({
        state,
        parent: host.value,
    });
    view.contentDOM.id = 'noteBody';
});

onBeforeUnmount(() => {
    view?.destroy();
    view = null;
});

watch(
    () => model.value,
    (v) => {
        if (!view) {
            return;
        }
        const cur = view.state.doc.toString();
        if (v === cur) {
            return;
        }
        view.dispatch({
            changes: { from: 0, to: view.state.doc.length, insert: v },
        });
    }
);

const toolBtnClass =
    'inline-flex h-8 min-w-8 items-center justify-center rounded-md border border-transparent text-text-secondary transition-colors hover:bg-white/5 hover:text-text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none';

function focusEditor() {
    view?.focus();
}

defineExpose({ focus: focusEditor });
</script>

<template>
    <div class="w-full overflow-hidden rounded-md border border-default bg-card text-left text-sm">
        <div
            v-if="showToolbar"
            class="flex flex-wrap items-center gap-0.5 border-b border-default bg-muted/20 px-1.5 py-1.5"
            @mousedown.prevent>
            <button
                type="button"
                :class="toolBtnClass"
                title="Heading 1"
                aria-label="Heading 1"
                @click="run((v) => setHeadingLevel(v, 1))">
                <Heading1 class="h-4 w-4" :stroke-width="1.75" />
            </button>
            <button
                type="button"
                :class="toolBtnClass"
                title="Heading 2"
                aria-label="Heading 2"
                @click="run((v) => setHeadingLevel(v, 2))">
                <Heading2 class="h-4 w-4" :stroke-width="1.75" />
            </button>
            <button
                type="button"
                :class="toolBtnClass"
                title="Heading 3"
                aria-label="Heading 3"
                @click="run((v) => setHeadingLevel(v, 3))">
                <Heading3 class="h-4 w-4" :stroke-width="1.75" />
            </button>
            <span class="mx-0.5 h-5 w-px shrink-0 self-center bg-border" aria-hidden="true" />
            <button
                type="button"
                :class="toolBtnClass"
                title="Bold"
                aria-label="Bold"
                @click="run((v) => insertBold(v))">
                <Bold class="h-4 w-4" :stroke-width="1.75" />
            </button>
            <button
                type="button"
                :class="toolBtnClass"
                title="Italic"
                aria-label="Italic"
                @click="run((v) => insertItalic(v))">
                <Italic class="h-4 w-4" :stroke-width="1.75" />
            </button>
            <button
                type="button"
                :class="toolBtnClass"
                title="Strikethrough"
                aria-label="Strikethrough"
                @click="run((v) => insertStrikethrough(v))">
                <Strikethrough class="h-4 w-4" :stroke-width="1.75" />
            </button>
            <span class="mx-0.5 h-5 w-px shrink-0 self-center bg-border" aria-hidden="true" />
            <button
                type="button"
                :class="toolBtnClass"
                title="Inline code"
                aria-label="Inline code"
                @click="run((v) => insertInlineCode(v))">
                <Code class="h-4 w-4" :stroke-width="1.75" />
            </button>
            <button
                type="button"
                :class="toolBtnClass"
                title="Code block"
                aria-label="Code block"
                @click="run((v) => insertFencedCodeBlock(v))">
                <SquareCode class="h-4 w-4" :stroke-width="1.75" />
            </button>
            <span class="mx-0.5 h-5 w-px shrink-0 self-center bg-border" aria-hidden="true" />
            <button
                type="button"
                :class="toolBtnClass"
                title="Bullet list"
                aria-label="Bullet list"
                @click="run((v) => toggleBulletList(v))">
                <List class="h-4 w-4" :stroke-width="1.75" />
            </button>
            <button
                type="button"
                :class="toolBtnClass"
                title="Numbered list"
                aria-label="Numbered list"
                @click="run((v) => toggleOrderedList(v))">
                <ListOrdered class="h-4 w-4" :stroke-width="1.75" />
            </button>
            <button
                type="button"
                :class="toolBtnClass"
                title="Blockquote"
                aria-label="Blockquote"
                @click="run((v) => toggleBlockquote(v))">
                <Quote class="h-4 w-4" :stroke-width="1.75" />
            </button>
            <span class="mx-0.5 h-5 w-px shrink-0 self-center bg-border" aria-hidden="true" />
            <button
                type="button"
                :class="toolBtnClass"
                title="Link"
                aria-label="Link"
                @click="run((v) => insertLink(v))">
                <Link class="h-4 w-4" :stroke-width="1.75" />
            </button>
            <button
                type="button"
                :class="toolBtnClass"
                title="Horizontal rule"
                aria-label="Horizontal rule"
                @click="run((v) => insertHorizontalRule(v))">
                <Minus class="h-4 w-4" :stroke-width="1.75" />
            </button>
        </div>
        <div
            ref="host"
            class="w-full overflow-hidden font-mono"
            :class="compact ? 'min-h-24' : 'min-h-60'"
            data-testid="note-body-editor-host" />
    </div>
</template>
