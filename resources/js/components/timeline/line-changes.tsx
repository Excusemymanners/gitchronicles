import { formatNumber } from '@/lib/format';

type Props = {
    insertions: number;
    deletions: number;
};

/**
 * Liniile adăugate și șterse de un commit: „+12 −3” și o bară mică împărțită în verde (adăugate)
 * și roșu (șterse), în proporția lor. Cititoarele de ecran primesc propoziția întreagă.
 */
export default function LineChanges({ insertions, deletions }: Props) {
    const total = insertions + deletions;
    const insertedShare = total === 0 ? 0 : (insertions / total) * 100;

    return (
        <span className="inline-flex items-center gap-1.5 tabular-nums">
            <span aria-hidden="true" className="text-success-ink">
                +{formatNumber(insertions)}
            </span>
            <span aria-hidden="true" className="text-danger-ink">
                −{formatNumber(deletions)}
            </span>
            <span aria-hidden="true" className="flex h-1.5 w-10 gap-px overflow-hidden rounded-full bg-surface-2">
                {insertions > 0 && <span className="bg-success" style={{ width: `${insertedShare}%` }} />}
                {deletions > 0 && <span className="flex-1 bg-danger" />}
            </span>
            <span className="sr-only">
                {formatNumber(insertions)} linii adăugate, {formatNumber(deletions)} linii șterse
            </span>
        </span>
    );
}
