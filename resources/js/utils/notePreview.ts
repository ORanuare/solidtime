/**
 * First non-empty line of note body (after skipping leading blank lines), for list previews.
 */
export function getNotePreviewLine(body: string): string {
    for (const line of body.split('\n')) {
        const t = line.trim();
        if (t.length > 0) {
            return t;
        }
    }

    return '—';
}
