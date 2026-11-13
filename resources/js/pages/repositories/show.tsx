import { Head, router, usePoll } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import type { ComponentProps, CSSProperties } from 'react';
import Markdown from 'react-markdown';
import LanguageBar from '@/components/home/language-bar';
import ActivitySection from '@/components/repository/activity-section';
import AskBox from '@/components/repository/ask-box';
import ExploreCards from '@/components/repository/explore-cards';
import HeroVideo from '@/components/repository/hero-video';
import HotspotsSection from '@/components/repository/hotspots-section';
import MissingArtifactsNotice from '@/components/repository/missing-artifacts-notice';
import OverviewSection from '@/components/repository/overview-section';
import StructureSection from '@/components/repository/structure-section';
import TakeItWithYou from '@/components/repository/take-it-with-you';
import { useI18n } from '@/hooks/use-i18n';
import RepositoryLayout from '@/layouts/repository-layout';
import { formatNumber } from '@/lib/format';
import type { CodeStructure, RepositoryPageProps } from '@/types';
import type { Activity } from '@/types/history';
import type { PresentationMedia } from '@/types/media';
import type { RepositoryShowcase } from '@/types/showcase';

/**
 * Besides the props of every repository page, the server sends one prop per part of the Overview. Each type is
 * defined once, where it is used, and taken from there.
 */
type Props = RepositoryPageProps & {
    overview: ComponentProps<typeof OverviewSection>['overview'];
    /** The code analysis's result (Faza 5); `null` until the first analysis ends. */
    code_structure: CodeStructure | null;
    activity: Activity | null;
    hotspots: ComponentProps<typeof HotspotsSection>['hotspots'];
    /** The videos (one per language) and the slides, as on the "Video & slides" page. */
    media: PresentationMedia;
    showcase: RepositoryShowcase;
};

/**
 * The Overview: a showcase of everything GitChronicle made for the repository, so a first-time visitor understands
 * in a few seconds what it is. The hero holds the name, one paragraph on what the project does and its real facts,
 * the ask box and the vertical video; then "Take it with you" (README, slides, video), the "Explore" cards and the
 * rest of the overview as plain sections. On a phone the order is: title and paragraph, video, ask box, the rest.
 */
export default function RepositoriesShow({ repository, completeness, workspace, overview, code_structure, activity, hotspots, media, showcase }: Props) {
    useMediaPoll(media.is_working);

    const paragraph = overview ? firstParagraph(overview.markdown) : null;
    const summary = paragraph ?? repository.description;

    return (
        <RepositoryLayout>
            <Head title={repository.full_name} />

            {/*
              The hero: the owner in muted text over the name at display scale, one paragraph at a comfortable measure,
              the facts as one row, then the ask box as the focal control. The video sits beside it in a device-like
              frame (a thin line, a soft shadow, rounded corners). Its parts enter one after the other.
            */}
            <section
                aria-labelledby="overview-title"
                className="grid gap-x-14 gap-y-10 pt-2 pb-4 sm:pt-6 lg:grid-cols-[minmax(0,1fr)_17rem] lg:grid-rows-[auto_1fr] xl:grid-cols-[minmax(0,1fr)_19rem]"
            >
                <div className="min-w-0 lg:col-start-1 lg:row-start-1">
                    <p style={staggerAt(0)} className="stagger animate-rise text-base text-ink-muted sm:text-lg">
                        {repository.owner}
                    </p>
                    <h1
                        id="overview-title"
                        style={staggerAt(0)}
                        className="stagger mt-1 animate-rise font-display text-display font-semibold break-words text-ink"
                    >
                        {repository.name}
                    </h1>

                    {summary && (
                        <div style={staggerAt(1)} className="stagger mt-6 max-w-[62ch] animate-rise text-base leading-relaxed text-ink-muted sm:text-lg">
                            {paragraph ? <InlineMarkdown>{paragraph}</InlineMarkdown> : summary}
                        </div>
                    )}

                    <Facts structure={code_structure} commitCount={activity?.commit_count ?? null} />
                </div>

                <div style={staggerAt(2)} className="stagger animate-rise lg:col-start-2 lg:row-span-2 lg:row-start-1">
                    {/* The device frame: a bezel of surface around the player; the player itself is HeroVideo's. */}
                    <div className="mx-auto max-w-76 rounded-3xl border border-line bg-surface p-2 shadow-float lg:max-w-none">
                        <HeroVideo repository={repository} media={media} />
                    </div>
                </div>

                {workspace.assistant.is_indexed && (
                    <div style={staggerAt(3)} className="stagger min-w-0 animate-rise lg:col-start-1 lg:row-start-2 lg:self-end">
                        <AskBox repository={repository} starters={workspace.assistant.starters} />
                    </div>
                )}
            </section>

            <MissingArtifactsNotice repository={repository} completeness={completeness} />

            <div className="mt-12 flex flex-col gap-12">
                <TakeItWithYou readme={showcase.readme} media={media} />

                <ExploreCards repository={repository} showcase={showcase} elements={workspace.elements} activity={activity} />

                <OverviewSection overview={overview} shownParagraph={paragraph} />

                {(activity || hotspots) && (
                    <div className="grid gap-x-12 gap-y-12 lg:grid-cols-2">
                        <ActivitySection activity={activity} />
                        <HotspotsSection hotspots={hotspots} />
                    </div>
                )}

                <StructureSection structure={code_structure} />
            </div>
        </RepositoryLayout>
    );
}

/** The place of a hero part in its entrance (the stagger utility reads --i). */
function staggerAt(index: number): CSSProperties {
    return { '--i': index } as CSSProperties;
}

/**
 * The hero's row of real facts: files, lines, modules and commits (each number over its label, hairlines between
 * them), then the languages bar. A number that is not known yet is left out; with none known the row is not drawn.
 */
function Facts({ structure, commitCount }: { structure: CodeStructure | null; commitCount: number | null }) {
    const { t } = useI18n();

    const facts = [
        structure && { label: t('overview.structure.files'), value: structure.file_count },
        structure && { label: t('overview.structure.linesOfCode'), value: structure.line_count },
        structure && { label: t('overview.structure.modules'), value: structure.module_count },
        commitCount !== null && { label: t('overview.showcase.commits'), value: commitCount },
    ].filter((fact): fact is { label: string; value: number } => Boolean(fact));

    if (facts.length === 0) {
        return null;
    }

    // The named languages come first from the server; the bar shows the top three and "Other" for the rest.
    const languages =
        structure && structure.line_count > 0
            ? structure.languages.slice(0, Math.min(3, structure.language_count)).map((language) => ({ name: language.name, share: language.line_count / structure.line_count }))
            : [];

    return (
        <div style={staggerAt(1)} className="stagger mt-9 max-w-2xl animate-rise">
            <dl className="flex flex-wrap gap-y-4">
                {facts.map((fact, index) => (
                    <div key={fact.label} className={`flex flex-col-reverse pr-6 sm:pr-8 ${index > 0 ? 'border-l border-line pl-6 sm:pl-8' : ''}`}>
                        <dt className="mt-1 text-small text-ink-subtle">{fact.label}</dt>
                        <dd className="font-display text-2xl leading-none font-semibold tracking-tight text-ink tabular-nums">{formatNumber(fact.value)}</dd>
                    </div>
                ))}
            </dl>
            {languages.length > 0 && (
                <div className="mt-6 max-w-xl">
                    <LanguageBar languages={languages} />
                </div>
            )}
        </div>
    );
}

/** One paragraph of generated markdown, inline: links, emphasis and code keep their meaning; nothing else is drawn. */
function InlineMarkdown({ children }: { children: string }) {
    return (
        <Markdown
            allowedElements={['p', 'a', 'strong', 'em', 'code']}
            unwrapDisallowed
            components={{
                p: ({ children: text }) => <p>{text}</p>,
                a: ({ href, children: text }) => (
                    <a href={href} target="_blank" rel="noopener noreferrer" className="rounded-sm text-accent underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">
                        {text}
                    </a>
                ),
                code: ({ children: text }) => <code className="rounded-sm bg-surface-2 px-1 py-0.5 font-mono text-[0.875em] text-ink">{text}</code>,
            }}
        >
            {children}
        </Markdown>
    );
}

/**
 * The first real paragraph of the generated overview: the first block of text that is not a heading, a list, a
 * table, a quote or code. `null` when there is none (the hero then shows GitHub's description, or nothing).
 */
function firstParagraph(markdown: string): string | null {
    const blocks = markdown.split(/\n\s*\n/).map((block) => block.trim());
    const paragraph = blocks.find((block) => block !== '' && !/^(#|[-*+] |\d+\. |\||>|```|<)/.test(block)) ?? null;

    return paragraph ? withoutListLeadIn(paragraph) : null;
}

/**
 * A paragraph that ends with a colon introduces a list ("…you can use it in three ways:"). The hero shows the
 * paragraph without its list, so that last sentence is cut off; it stays, with its list, in "About the project".
 * `null` when the whole paragraph was only the lead-in.
 */
function withoutListLeadIn(paragraph: string): string | null {
    if (!paragraph.endsWith(':')) {
        return paragraph;
    }

    const complete = paragraph.match(/^([\s\S]*[.!?])\s+[^.!?]*:$/);

    return complete ? complete[1] : null;
}

/**
 * While a video or the slides are being made, ask the server every 3 seconds for the "media" prop only; when the
 * work ends, reload the workspace too (the tabs and the cards change).
 */
function useMediaPoll(isWorking: boolean): void {
    const { start, stop } = usePoll(3000, { only: ['media'] }, { autoStart: false });
    const wasWorking = useRef(isWorking);

    useEffect(() => {
        if (isWorking) {
            start();
        } else {
            stop();
        }
    }, [isWorking, start, stop]);

    useEffect(() => {
        if (wasWorking.current && !isWorking) {
            router.reload({ only: ['workspace', 'showcase'] });
        }

        wasWorking.current = isWorking;
    }, [isWorking]);
}
