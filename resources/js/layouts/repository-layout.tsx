import { Form, Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import PipelineRail from '@/components/pipeline-rail';
import { useI18n } from '@/hooks/use-i18n';
import { PIPELINE_STAGES, stageStates } from '@/lib/pipeline';
import type { RepositoryPageProps } from '@/types';

type Props = {
    children: ReactNode;
};

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

const linkClasses = `rounded-sm text-accent hover:text-accent-hover hover:underline ${focusRing}`;

/**
 * How wide each page's column is. Reading pages (Documentation, Ask) keep a line length of about 75 characters;
 * the Overview and the Diagrams use the whole width; History, Video & slides and the Report sit in between.
 */
const columnWidths: Record<string, string> = {
    'repositories/show': 'max-w-6xl',
    'repositories/diagrams': 'max-w-6xl',
    'repositories/docs': 'max-w-190',
    'repositories/assistant': 'max-w-190',
};

const DEFAULT_COLUMN_WIDTH = 'max-w-5xl';

/**
 * The frame of every repository page, inside the workspace (WorkspaceLayout): a centered column and, above the
 * page, the state of the processing — the pipeline rail while the first run works, a one-line notice while an
 * update brings new commits (so the page stays readable), or the error with "Try again" after a failure.
 */
export default function RepositoryLayout({ children }: Props) {
    const { t, rich } = useI18n();
    const { props, component } = usePage<RepositoryPageProps>();
    const { auth, repository, can_retry } = props;
    const width = columnWidths[component] ?? DEFAULT_COLUMN_WIDTH;

    return (
        <div className={`mx-auto w-full ${width} px-4 pt-6 pb-8 sm:px-8 sm:pt-8`}>
            {repository.is_processing && repository.status !== 'updating' && <ProcessingState status={repository.status} />}

            {repository.status === 'updating' && (
                <p role="status" className="mb-6 flex items-center gap-2 text-[0.8125rem] text-ink-muted">
                    <span aria-hidden="true" className="size-2 shrink-0 rounded-full bg-info motion-safe:animate-pulse" />
                    {t('workspace.updating')}
                </p>
            )}

            {repository.status === 'failed' && (
                <div
                    role="alert"
                    className="mb-8 flex flex-col gap-3 rounded-lg border border-danger-line bg-danger-soft p-4 text-sm text-danger-ink sm:flex-row sm:items-center sm:justify-between"
                >
                    <p>
                        <span className="font-semibold">{t('workspace.processingFailed')}</span> {repository.error}
                    </p>

                    {/* On a wide screen "Try again" is in the top bar; a guest is invited to log in. */}
                    {can_retry ? (
                        <Form action={`/repositories/${repository.id}/retry`} method="post" className="shrink-0 lg:hidden">
                            {({ processing }) => (
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className={`pressable inline-flex cursor-pointer items-center rounded-md bg-accent-strong px-3 py-1.5 text-sm font-semibold text-on-accent hover:bg-accent-strong-hover disabled:cursor-not-allowed disabled:opacity-60 ${focusRing}`}
                                >
                                    {processing ? t('workspace.starting') : t('workspace.retry')}
                                </button>
                            )}
                        </Form>
                    ) : (
                        !auth.user && (
                            <p className="shrink-0">
                                {rich('workspace.logInToRetry', {
                                    logIn: (
                                        <Link href="/login" className={`font-medium ${linkClasses}`}>
                                            {t('nav.logIn')}
                                        </Link>
                                    ),
                                })}
                            </p>
                        )
                    )}
                </div>
            )}

            {children}
        </div>
    );
}

/**
 * The first processing, as it happens: the rail with the seven real stages (the done ones filled, the running one
 * pulsing) and the name of the stage at work. The workspace polls the status, so the page updates by itself.
 */
function ProcessingState({ status }: { status: RepositoryPageProps['repository']['status'] }) {
    const { t } = useI18n();
    const activeIndex = stageStates(status).indexOf('active');
    const activeStage = activeIndex === -1 ? null : PIPELINE_STAGES[activeIndex];

    return (
        <section role="status" aria-live="polite" className="mb-8 rounded-lg border border-line bg-surface p-4 sm:p-5">
            <p className="text-sm font-medium text-ink">{t('workspace.processing.title')}</p>
            <p className="mt-1 text-[0.8125rem] text-ink-muted">
                {activeStage ? t('workspace.processing.stage', { stage: t(`pipeline.stages.${activeStage.key}.label`) }) : t('workspace.processing.waiting')}
            </p>
            <PipelineRail status={status} variant="compact" className="mt-4" />
        </section>
    );
}
