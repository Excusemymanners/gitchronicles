import { Link } from '@inertiajs/react';
import { useEffect, useId, useRef } from 'react';
import type { RefObject } from 'react';
import AssistantBar from '@/components/assistant/assistant-bar';
import { useAssistantConversation } from '@/components/assistant/assistant-provider';
import AssistantThread from '@/components/assistant/assistant-thread';
import { AskIcon, CloseIcon } from '@/components/workspace/icons';
import { shortcutLabel } from '@/components/workspace/shortcuts';
import { useI18n } from '@/hooks/use-i18n';
import type { RepositoryItem, Workspace } from '@/types';

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

const headerButtonClasses = `cursor-pointer rounded-md px-2 py-1 text-xs font-medium text-ink-muted hover:bg-surface-2 hover:text-ink ${focusRing}`;

type ButtonProps = {
    onClick: () => void;
    buttonRef: RefObject<HTMLButtonElement | null>;
};

/**
 * The floating "Ask" button, bottom right: it opens the assistant panel. From 768 px it is a pill with the
 * Ctrl+I shortcut; on a phone a round button with only the icon (its name is in aria-label).
 */
export function AskButton({ onClick, buttonRef }: ButtonProps) {
    const { t } = useI18n();

    return (
        <button
            ref={buttonRef}
            type="button"
            onClick={onClick}
            aria-label={t('workspace.askPanel.open')}
            aria-keyshortcuts="Control+I Meta+I"
            className={`pressable inline-flex xl:absolute xl:right-4 xl:bottom-[max(1rem,env(safe-area-inset-bottom))] z-20 cursor-pointer items-center gap-2 rounded-full bg-accent-strong p-3.5 text-on-accent shadow-float hover:bg-accent-strong-hover md:py-2.5 md:pr-3 md:pl-4 ${focusRing}`}
        >
            <AskIcon className="size-5" />
            <span className="text-sm font-semibold max-md:sr-only">{t('workspace.askPanel.button')}</span>
            <kbd aria-hidden="true" className="rounded-sm bg-on-accent/15 px-1.5 py-0.5 font-sans text-[0.6875rem] font-medium max-md:hidden">
                {shortcutLabel('I')}
            </kbd>
        </button>
    );
}

type PanelProps = {
    repository: RepositoryItem;
    assistant: Workspace['assistant'];
    isOpen: boolean;
    onClose: () => void;
};

/**
 * The assistant as a panel beside the page instead of a band over its text: a drawer on the right (26 rem) from
 * 768 px, a bottom sheet on a phone. It shows the same conversation as the Ask page (AssistantProvider), so a
 * question asked here is still there on any other page. Esc (handled by WorkspaceLayout) and the close button
 * close it; on a phone a tap on the dimmed page too. It slides in once (@starting-style); reduced motion makes the slide instant.
 */
export function AskPanel({ repository, assistant, isOpen, onClose }: PanelProps) {
    const { t } = useI18n();
    const conversation = useAssistantConversation();
    const titleId = useId();
    const panelRef = useRef<HTMLElement>(null);
    const hasConversation = conversation.messages.length > 0;

    // On opening, the focus goes to the question field, ready to type.
    useEffect(() => {
        if (isOpen) {
            panelRef.current?.querySelector<HTMLTextAreaElement>('textarea')?.focus();
        }
    }, [isOpen]);

    return (
        <>
            {/* On a phone the sheet covers most of the screen: the page behind is dimmed and a tap on it closes the sheet. */}
            <div aria-hidden="true" hidden={!isOpen} onClick={onClose} className="absolute inset-0 z-20 bg-canvas/70 backdrop-blur-[2px] md:hidden" />

            <section
                ref={panelRef}
                role="dialog"
                aria-labelledby={titleId}
                hidden={!isOpen}
                className="absolute inset-x-0 bottom-0 z-30 flex max-h-[85%] min-h-[60%] flex-col rounded-t-2xl border-t border-line bg-surface-raised shadow-float transition-transform duration-(--motion-slow) ease-out starting:translate-y-full md:inset-y-0 md:right-0 md:left-auto md:max-h-none md:min-h-0 md:w-[26rem] md:rounded-none md:border-t-0 md:border-l md:starting:translate-x-full md:starting:translate-y-0"
            >
                <div className="flex shrink-0 items-center gap-2 border-b border-line py-2 pr-2 pl-4">
                    <AskIcon className="size-4 text-accent" />
                    <h2 id={titleId} className="min-w-0 flex-1 truncate font-sans text-sm font-semibold text-ink">
                        {t('workspace.askPanel.title')}
                    </h2>
                    {hasConversation && (
                        <button type="button" onClick={conversation.clear} className={headerButtonClasses}>
                            {t('workspace.dock.newConversation')}
                        </button>
                    )}
                    <Link href={`/repositories/${repository.id}/assistant`} className={headerButtonClasses}>
                        {t('workspace.dock.fullPage')}
                    </Link>
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label={t('workspace.askPanel.close')}
                        className={`inline-flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-md text-ink-muted hover:bg-surface-2 hover:text-ink ${focusRing}`}
                    >
                        <CloseIcon className="size-4" />
                    </button>
                </div>

                <div className="relative min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 py-4">
                    {hasConversation ? (
                        <AssistantThread assistant={conversation} />
                    ) : (
                        <p className="text-[0.8125rem] leading-relaxed text-ink-muted">{t('workspace.askPanel.empty', { repository: repository.full_name })}</p>
                    )}
                </div>

                <div className="shrink-0 border-t border-line px-3 pt-3 pb-[max(0.5rem,env(safe-area-inset-bottom))]">
                    <AssistantBar assistant={conversation} repository={repository} isIndexed={assistant.is_indexed} starters={assistant.starters} />
                </div>
            </section>
        </>
    );
}
