/**
 * The facts the Overview's showcase needs beyond the other props (RepositoryShowcaseResource in Laravel).
 * A part without data is `null` (or 0) and its card is left out.
 */
export type RepositoryShowcase = {
    /** The written documentation: how many pages and the first three, in the order of the docs menu. */
    documentation: { page_count: number; pages: { title: string; href: string }[]; href: string } | null;
    /** The generated README: the download address and the file's size in bytes. */
    readme: { download: string; size_bytes: number } | null;
    diagram_count: number;
    /** What the newest successful update changed; `null` for a repository that was never updated. */
    last_change: {
        from_sha: string;
        to_sha: string;
        finished_at: string | null;
        files_changed: number | null;
        modules_affected: number | null;
        documents_regenerated: number | null;
    } | null;
    /** The newest imported commit; `null` before the history is imported. */
    latest_commit: { sha: string; subject: string; authored_at: string } | null;
};
