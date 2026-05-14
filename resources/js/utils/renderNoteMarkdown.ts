import DOMPurify from 'dompurify';
import MarkdownIt from 'markdown-it';

const md = new MarkdownIt({
    html: false,
    linkify: true,
    breaks: true,
});

const defaultLinkOpenRender =
    md.renderer.rules.link_open ??
    function (tokens, idx, options, env, self) {
        return self.renderToken(tokens, idx, options);
    };

md.renderer.rules.link_open = function (tokens, idx, options, env, self) {
    const token = tokens[idx];
    if (!token) {
        return defaultLinkOpenRender(tokens, idx, options, env, self);
    }
    const targetIdx = token.attrIndex('target');
    if (targetIdx < 0) {
        token.attrPush(['target', '_blank']);
    } else {
        const targetPair = token.attrs?.[targetIdx];
        if (targetPair) targetPair[1] = '_blank';
    }
    const relIdx = token.attrIndex('rel');
    const rel = 'noopener noreferrer';
    if (relIdx < 0) {
        token.attrPush(['rel', rel]);
    } else {
        const relPair = token.attrs?.[relIdx];
        if (relPair) relPair[1] = rel;
    }
    return defaultLinkOpenRender(tokens, idx, options, env, self);
};

/**
 * Renders user-authored Markdown to sanitized HTML for display (no raw HTML in source).
 */
export function renderNoteMarkdownToHtml(source: string): string {
    const raw = md.render(source);
    // Default DOMPurify HTML profile allows `rel` on `<a>` but not `target`, so `_blank` was stripped.
    return DOMPurify.sanitize(raw, {
        USE_PROFILES: { html: true },
        ADD_ATTR: ['target'],
    });
}
