import { EditorSelection, type Text } from '@codemirror/state';
import { EditorView } from '@codemirror/view';

function getMainPos(view: EditorView): number {
    return view.state.selection.main.from;
}

function getLineInfo(doc: Text, pos: number) {
    const l = doc.lineAt(pos);
    return { from: l.from, to: l.to, text: l.text };
}

function stripAtxHeading(text: string): string {
    return text.replace(/^#{1,6}\s+/, '');
}

const leadingIndentRe = /^(\s*)/;

/**
 * Replaces the current line with ATX heading of the given level.
 */
export function setHeadingLevel(view: EditorView, level: 1 | 2 | 3): void {
    const { state } = view;
    const { from, to, text } = getLineInfo(state.doc, getMainPos(view));
    const rest = stripAtxHeading(text);
    const next = `${'#'.repeat(level)} ${rest}`;
    view.dispatch({ changes: { from, to, insert: next } });
    view.focus();
}

/**
 * Toggles a bullet on the current line: `- ` prefix, or convert numbered/quote, or remove.
 */
export function toggleBulletList(view: EditorView): void {
    const { state } = view;
    const { from, to, text: raw } = getLineInfo(state.doc, getMainPos(view));
    const m = raw.match(leadingIndentRe);
    const indent = m?.[1] ?? '';
    const t = raw.slice(indent.length);
    if (/^[-*+]\s+/.test(t)) {
        const rest = t.replace(/^[-*+]\s+/, '');
        view.dispatch({ changes: { from, to, insert: indent + rest } });
        view.focus();
        return;
    }
    if (/^\d+\.\s+/.test(t)) {
        const rest = t.replace(/^\d+\.\s+/, '');
        view.dispatch({ changes: { from, to, insert: indent + '- ' + rest } });
        view.focus();
        return;
    }
    if (t.startsWith('> ')) {
        const rest = t.slice(2);
        view.dispatch({ changes: { from, to, insert: indent + '- ' + rest } });
        view.focus();
        return;
    }
    const body = stripAtxHeading(t);
    view.dispatch({ changes: { from, to, insert: indent + '- ' + body } });
    view.focus();
}

export function toggleOrderedList(view: EditorView): void {
    const { state } = view;
    const { from, to, text: raw } = getLineInfo(state.doc, getMainPos(view));
    const m = raw.match(leadingIndentRe);
    const indent = m?.[1] ?? '';
    const t = raw.slice(indent.length);
    if (/^\d+\.\s+/.test(t)) {
        const rest = t.replace(/^\d+\.\s+/, '');
        view.dispatch({ changes: { from, to, insert: indent + rest } });
        view.focus();
        return;
    }
    if (/^[-*+]\s+/.test(t)) {
        const rest = t.replace(/^[-*+]\s+/, '');
        view.dispatch({ changes: { from, to, insert: `${indent}1. ${rest}` } });
        view.focus();
        return;
    }
    if (/^>[\t ]*/.test(t)) {
        const rest = t.replace(/^>[\t ]*/, '');
        view.dispatch({ changes: { from, to, insert: `${indent}1. ${rest}` } });
        view.focus();
        return;
    }
    const body = stripAtxHeading(t);
    view.dispatch({ changes: { from, to, insert: `${indent}1. ${body}` } });
    view.focus();
}

export function toggleBlockquote(view: EditorView): void {
    const { state } = view;
    const { from, to, text: raw } = getLineInfo(state.doc, getMainPos(view));
    const m = raw.match(leadingIndentRe);
    const indent = m?.[1] ?? '';
    const t = raw.slice(indent.length);
    if (t.startsWith('> ')) {
        const rest = t.slice(2);
        view.dispatch({ changes: { from, to, insert: indent + rest } });
        view.focus();
        return;
    }
    if (t.startsWith('>') && !t.startsWith('> ')) {
        const rest = t.replace(/^>[\s]*/, '');
        view.dispatch({ changes: { from, to, insert: indent + rest } });
        view.focus();
        return;
    }
    const body = stripAtxHeading(t);
    view.dispatch({ changes: { from, to, insert: `${indent}> ${body}` } });
    view.focus();
}

export function wrapRange(
    view: EditorView,
    before: string,
    after: string,
    emptyPlaceholder: string
): void {
    const { state } = view;
    const { from, to } = state.selection.main;
    if (from === to) {
        const ph = emptyPlaceholder;
        const insert = before + ph + after;
        view.dispatch({
            changes: { from, to, insert },
            selection: EditorSelection.range(
                from + before.length,
                from + before.length + ph.length
            ),
        });
    } else {
        const selected = state.sliceDoc(from, to);
        if (selected.startsWith(before) && selected.endsWith(after) && selected.length > before.length + after.length) {
            const inner = selected.slice(before.length, -after.length);
            view.dispatch({ changes: { from, to, insert: inner } });
        } else {
            view.dispatch({ changes: { from, to, insert: before + selected + after } });
        }
    }
    view.focus();
}

export function insertInlineCode(view: EditorView): void {
    wrapRange(view, '`', '`', 'code');
}

export function insertBold(view: EditorView): void {
    wrapRange(view, '**', '**', 'bold text');
}

export function insertItalic(view: EditorView): void {
    wrapRange(view, '_', '_', 'italic');
}

export function insertStrikethrough(view: EditorView): void {
    wrapRange(view, '~~', '~~', 'text');
}

export function insertFencedCodeBlock(view: EditorView): void {
    const { state } = view;
    const { from, to, empty } = state.selection.main;
    const atDocStart = from === 0;
    const atLineStart = from === state.doc.lineAt(from).from;
    const prefix = atDocStart ? '' : atLineStart && !empty ? '\n' : '\n\n';
    const ins = prefix + '```\n\n```\n';
    const cursor = from + prefix.length + 4 + 1;
    view.dispatch({
        changes: { from, to, insert: ins },
        selection: EditorSelection.cursor(cursor),
    });
    view.focus();
}

export function insertHorizontalRule(view: EditorView): void {
    const { state } = view;
    const { from, to, empty } = state.selection.main;
    const atDocStart = from === 0;
    const atLineStart = from === state.doc.lineAt(from).from;
    const prefix = atDocStart ? '' : atLineStart && !empty ? '\n' : '\n\n';
    const ins = prefix + '---\n';
    const cursor = from + ins.length;
    view.dispatch({
        changes: { from, to, insert: ins },
        selection: EditorSelection.cursor(cursor),
    });
    view.focus();
}

export function insertLink(view: EditorView): void {
    const { state } = view;
    const { from, to } = state.selection.main;
    const selected = from === to ? '' : state.sliceDoc(from, to);
    const linkMatch = selected.match(/^\[([^\]]*)\]\(([^)]*)\)$/);
    const defaultUrl = linkMatch && linkMatch[2] ? linkMatch[2] : 'https://';
    const url = window.prompt('Link URL', defaultUrl);
    if (url === null) {
        return;
    }
    if (from === to) {
        const ph = 'link text';
        const insert = `[${ph}](${url})`;
        view.dispatch({
            changes: { from, to, insert },
            selection: EditorSelection.range(from + 1, from + 1 + ph.length),
        });
    } else if (linkMatch) {
        const next = linkMatch[1];
        view.dispatch({ changes: { from, to, insert: next } });
    } else {
        const label = selected;
        const insert = `[${label}](${url})`;
        view.dispatch({ changes: { from, to, insert } });
    }
    view.focus();
}
