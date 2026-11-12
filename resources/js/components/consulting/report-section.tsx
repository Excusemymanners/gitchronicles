import type { ReactNode } from 'react';

type Props = {
    /** Ancora secțiunii: capitolele din cuprins duc la ea (de exemplu „zone-de-risc”). */
    id: string;
    title: string;
    /** O propoziție despre ce arată secțiunea. */
    description?: ReactNode;
    children: ReactNode;
};

/** O secțiune a raportului: titlu, o propoziție de explicație și conținutul, fără cutie în jur. */
export default function ReportSection({ id, title, description, children }: Props) {
    return (
        <section id={id} aria-labelledby={`${id}-heading`} className="scroll-mt-6">
            <h2 id={`${id}-heading`} className="text-lg font-semibold tracking-tight text-ink">
                {title}
            </h2>
            {description && <p className="mt-1 text-sm text-ink-muted">{description}</p>}
            <div className="mt-4">{children}</div>
        </section>
    );
}
