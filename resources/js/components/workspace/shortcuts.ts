/**
 * The workspace's keyboard shortcuts use Ctrl on Windows and Linux and ⌘ (Meta) on Apple devices:
 * Ctrl/⌘+K opens the command palette, Ctrl/⌘+I the assistant panel.
 */

/** Whether the visitor is on a Mac, iPhone or iPad, where the shortcut key is ⌘. */
function isApplePlatform(): boolean {
    return typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform);
}

/** How a shortcut is written on a key cap: "Ctrl K" or "⌘K". */
export function shortcutLabel(key: string): string {
    return isApplePlatform() ? `⌘${key}` : `Ctrl ${key}`;
}

/** Whether a key press is the shortcut Ctrl+key (or ⌘+key on an Apple device), without Shift or Alt. */
export function isShortcut(event: KeyboardEvent, key: string): boolean {
    const modifier = isApplePlatform() ? event.metaKey : event.ctrlKey;

    return modifier && !event.shiftKey && !event.altKey && event.key.toLowerCase() === key.toLowerCase();
}
