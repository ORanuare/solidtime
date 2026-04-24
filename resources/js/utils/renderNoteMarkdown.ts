import DOMPurify from 'dompurify';
import MarkdownIt from 'markdown-it';

const md = new MarkdownIt({
    html: false,
    linkify: true,
    breaks: true,
});

/**
 * Renders user-authored Markdown to sanitized HTML for display (no raw HTML in source).
 */
export function renderNoteMarkdownToHtml(source: string): string {
    const raw = md.render(source);
    return DOMPurify.sanitize(raw, { USE_PROFILES: { html: true } });
}
