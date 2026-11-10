import { useId } from 'react';
import type { ReactNode } from 'react';
import ChapterLink from '@/components/workspace/chapter-link';
import { ArrowRightIcon } from '@/components/workspace/icons';

type Props = {
    title: string;
    /** Ce este elementul, pe scurt (nu date despre repository). */
    description: string;
    /** Capitolul elementului; `null` cât timp datele lui nu există: atunci cardul nu duce nicăieri. */
    href: string | null;
    /** Textul legăturii, de exemplu „Deschide diagramele”. */
    actionLabel: string;
    /** Ce scrie pe card cât timp elementul lipsește, de exemplu „Încă nu a fost generat.”. */
    emptyText: string;
    /** Conținutul cardului când datele există (o previzualizare, cifre, o listă scurtă). */
    children?: ReactNode;
};

/**
 * Un card din panoul „Elemente”. Când elementul există, tot cardul e o legătură spre capitolul lui
 * (legătura se întinde peste card cu ::after, iar conturul de focus apare pe card). Când nu există,
 * cardul rămâne pe fundalul panoului, fără legătură, cu o etichetă care spune că nu a fost generat încă.
 *
 * Pe fundalul surface-2 al cardului textul mic e ink-muted, nu ink-subtle: în tema luminoasă
 * ink-subtle n-ar avea contrastul minim (DARK_THEME.md).
 */
export default function ElementCard({ title, description, href, actionLabel, emptyText, children }: Props) {
    const titleId = useId();

    if (href === null) {
        return (
            <li>
                <article aria-labelledby={titleId} className="rounded-2xl border border-line p-4">
                    <h3 id={titleId} className="text-sm font-semibold text-ink">
                        {title}
                    </h3>
                    <p className="mt-1 text-[0.8125rem] leading-relaxed text-ink-muted">{description}</p>
                    <p className="mt-3 inline-flex items-center gap-1.5 rounded-full bg-surface-2 px-2.5 py-0.5 text-[0.6875rem] text-ink-muted">
                        <span aria-hidden="true" className="size-1.5 shrink-0 rounded-full border border-ink-muted" />
                        {emptyText}
                    </p>
                </article>
            </li>
        );
    }

    return (
        <li>
            <article
                aria-labelledby={titleId}
                className="relative rounded-2xl border border-line bg-surface-2 p-4 hover:border-line-strong has-[a:focus-visible]:outline-2 has-[a:focus-visible]:outline-offset-2 has-[a:focus-visible]:outline-accent motion-safe:transition-colors"
            >
                <h3 id={titleId} className="text-sm font-semibold text-ink">
                    {title}
                </h3>
                <p className="mt-1 text-[0.8125rem] leading-relaxed text-ink-muted">{description}</p>

                {children && <div className="mt-3">{children}</div>}

                <ChapterLink
                    href={href}
                    className="mt-3 inline-flex items-center gap-1.5 text-[0.8125rem] font-medium text-accent outline-none after:absolute after:inset-0 after:rounded-2xl hover:text-accent-hover"
                >
                    {actionLabel}
                    <ArrowRightIcon />
                </ChapterLink>
            </article>
        </li>
    );
}
