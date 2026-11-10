import { Form, Head, Link, usePage, usePoll } from '@inertiajs/react';
import { useEffect } from 'react';
import { AppLogoMark } from '@/components/app-logo';
import PrimaryButton from '@/components/primary-button';
import StatusBadge from '@/components/status-badge';
import TextInput from '@/components/text-input';
import AppLayout from '@/layouts/app-layout';
import { formatDate, shortSha } from '@/lib/format';
import type { RepositoryItem } from '@/types';

type Props = {
    mesaj: string;
    /** Toate repository-urile adăugate de oricine, cele mai noi primele. */
    repositories: RepositoryItem[];
};

export default function Home({ mesaj, repositories }: Props) {
    const { auth } = usePage().props;
    const hasProcessingRepository = repositories.some((repository) => repository.is_processing);

    // Cât timp măcar un repository se mai procesează, cerem lista din nou la fiecare 2 secunde
    // (doar prop-ul „repositories”); când toate sunt gata sau au eșuat, ne oprim.
    const { start, stop } = usePoll(2000, { only: ['repositories'] }, { autoStart: false });

    useEffect(() => {
        if (hasProcessingRepository) {
            start();
        } else {
            stop();
        }
    }, [hasProcessingRepository, start, stop]);

    return (
        <AppLayout>
            <Head title="Acasă" />

            <section className="flex flex-col items-center gap-2 pb-8 text-center">
                <AppLogoMark className="mb-2 size-20" />
                <h1 className="font-brand text-3xl font-bold tracking-tight sm:text-4xl">GitChronicle</h1>
                <p className="max-w-xl text-ink-muted sm:text-lg">{mesaj}</p>
            </section>

            {/*
              Oricine poate trimite formularul; serverul decide ce urmează. Dacă repository-ul există deja,
              deschide pagina lui. Un vizitator fără cont ajunge la autentificare, iar după ce intră în cont
              repository-ul se adaugă automat. resetOnSuccess golește câmpul după un răspuns reușit.
            */}
            <Form
                action="/repositories"
                method="post"
                resetOnSuccess
                noValidate
                className="rounded-lg border border-line bg-surface p-4 shadow-xs sm:p-6"
            >
                {({ errors, processing }) => (
                    <>
                        <label htmlFor="url" className="text-sm font-medium text-ink-muted">
                            Link GitHub
                        </label>
                        <div className="mt-1.5 flex flex-col gap-2 sm:flex-row">
                            <div className="flex-1">
                                <TextInput
                                    id="url"
                                    name="url"
                                    type="url"
                                    placeholder="https://github.com/proprietar/nume"
                                    autoComplete="off"
                                    aria-invalid={errors.url ? true : undefined}
                                    aria-describedby={errors.url ? 'url-error url-hint' : 'url-hint'}
                                />
                            </div>
                            <PrimaryButton disabled={processing}>{processing ? 'Se adaugă…' : 'Adaugă'}</PrimaryButton>
                        </div>
                        {errors.url && (
                            <p id="url-error" className="mt-1.5 text-sm text-danger">
                                {errors.url}
                            </p>
                        )}
                        <p id="url-hint" className="mt-3 text-sm text-ink-subtle">
                            {auth.user
                                ? 'Lipește linkul unui repository de pe GitHub ca să-l adaugi.'
                                : 'Poți răsfoi totul fără cont. Ca să adaugi un repository nou, îți cerem să intri în cont — apoi îl adăugăm automat.'}
                        </p>
                    </>
                )}
            </Form>

            <section aria-labelledby="repositories-heading" className="mt-10">
                <h2 id="repositories-heading" className="text-lg font-semibold tracking-tight">
                    Repository-uri
                </h2>

                {repositories.length === 0 ? (
                    <p className="mt-4 rounded-lg border border-dashed border-line-strong px-6 py-10 text-center text-sm text-ink-muted">
                        Încă nu a fost adăugat niciun repository. Adaugă primul link de GitHub mai sus.
                    </p>
                ) : (
                    <ul className="mt-4 divide-y divide-line rounded-lg border border-line bg-surface shadow-xs">
                        {repositories.map((repository) => (
                            <li key={repository.id} className="px-4 py-4 sm:px-6">
                                <div className="flex flex-wrap items-center gap-2">
                                    <Link
                                        href={`/repositories/${repository.id}`}
                                        className="rounded-sm font-medium break-words text-ink hover:text-accent focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                                    >
                                        {repository.full_name}
                                    </Link>
                                    <StatusBadge status={repository.status} label={repository.status_label} />
                                </div>
                                <p className="mt-1 truncate text-sm text-ink-subtle">{repository.url}</p>
                                <p className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-ink-subtle">
                                    {repository.last_sha && (
                                        <code
                                            title={repository.last_sha}
                                            className="rounded-sm bg-surface-2 px-1.5 py-0.5 font-mono text-ink-muted"
                                        >
                                            {shortSha(repository.last_sha)}
                                        </code>
                                    )}
                                    <span>
                                        Adăugat la <time dateTime={repository.created_at}>{formatDate(repository.created_at)}</time>
                                    </span>
                                </p>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </AppLayout>
    );
}
