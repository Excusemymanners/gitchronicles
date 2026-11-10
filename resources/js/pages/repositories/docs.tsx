import { Head, usePoll } from '@inertiajs/react';
import { useEffect } from 'react';
import DocsContent from '@/components/docs/docs-content';
import DocsPager from '@/components/docs/docs-pager';
import RepositoryLayout from '@/layouts/repository-layout';
import type { RepositoryItem, RepositoryPageProps } from '@/types';
import type { DocumentLink, DocumentPage } from '@/types/docs';

type Props = RepositoryPageProps & {
    /** Capitolele documentației, în ordinea cuprinsului (gol până se scrie prima pagină). */
    navigation: DocumentLink[];
    /** Pagina deschisă: cea din adresă sau, fără slug, „Despre proiect”; `null` cât nu există. */
    document: DocumentPage | null;
};

/**
 * Capitolele „Documentație”: o pagină scrisă de GitChronicle (despre proiect, README-ul sau un modul).
 * Cuprinsul e în coloana din stânga a spațiului de lucru, așa că aici e doar pagina, cu legături spre
 * pagina anterioară și următoare la final, ca pe un site de documentație.
 * Cât timp repository-ul se procesează, pagina se reîmprospătează singură, ca textele să apară pe măsură ce sunt scrise.
 */
export default function RepositoriesDocs({ repository, navigation, document }: Props) {
    const { start, stop } = usePoll(4000, { only: ['navigation', 'document'] }, { autoStart: false });

    useEffect(() => {
        if (repository.is_processing) {
            start();
        } else {
            stop();
        }
    }, [repository.is_processing, start, stop]);

    const title = document ? `${document.title} · ${repository.full_name}` : `Documentație · ${repository.full_name}`;

    return (
        <RepositoryLayout>
            <Head title={title} />

            <div className="mt-6">
                {navigation.length === 0 ? (
                    <EmptyState repository={repository} />
                ) : document ? (
                    <>
                        <DocsContent document={document} repositoryFullName={repository.full_name} />
                        <DocsPager items={navigation} currentSlug={document.slug} />
                    </>
                ) : (
                    <p className="rounded-lg border border-dashed border-line-strong px-6 py-10 text-center text-sm text-ink-muted">
                        Pagina „Despre proiect” nu e gata încă. Celelalte pagini sunt în cuprins.
                    </p>
                )}
            </div>
        </RepositoryLayout>
    );
}

/**
 * Ce arată pagina înainte să existe vreo pagină de documentație: se scrie acum, procesarea a eșuat
 * (motivul e deja deasupra) sau repository-ul a fost procesat înainte să existe documentația.
 */
function EmptyState({ repository }: { repository: RepositoryItem }) {
    if (repository.is_processing) {
        return (
            <p
                role="status"
                className="flex items-center justify-center gap-2 rounded-lg border border-dashed border-line-strong px-6 py-10 text-center text-sm text-ink-muted"
            >
                <span aria-hidden="true" className="size-2 shrink-0 rounded-full bg-info motion-safe:animate-pulse" />
                {repository.status === 'documenting'
                    ? 'Se scrie documentația. Paginile apar aici pe măsură ce sunt gata.'
                    : 'Documentația se scrie după ce se termină clonarea și analiza codului.'}
            </p>
        );
    }

    return (
        <p className="rounded-lg border border-dashed border-line-strong px-6 py-10 text-center text-sm text-ink-muted">
            {repository.status === 'failed'
                ? 'Procesarea s-a oprit înainte să fie scrisă documentația.'
                : 'Documentația acestui repository nu a fost scrisă încă. Apare după următoarea procesare.'}
        </p>
    );
}
