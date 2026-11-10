import { Form, Head, Link } from '@inertiajs/react';
import PrimaryButton from '@/components/primary-button';
import TextField from '@/components/text-field';
import AppLayout from '@/layouts/app-layout';

type Props = {
    /** Linkul repository-ului cerut de vizitator înainte să-și creeze contul (sau null dacă nu a cerut niciunul). */
    pending_repository: string | null;
};

export default function Register({ pending_repository }: Props) {
    return (
        <AppLayout>
            <Head title="Cont nou" />

            <div className="mx-auto w-full max-w-sm">
                <h1 className="text-2xl font-semibold tracking-tight">Cont nou</h1>
                <p className="mt-1 text-sm text-ink-muted">Creează un cont ca să adaugi repository-uri de pe GitHub.</p>

                {pending_repository && (
                    <p className="mt-6 rounded-lg border border-info-line bg-info-soft p-4 text-sm text-info-ink">
                        După ce îți creezi contul, adăugăm automat <span className="font-medium break-all">{pending_repository}</span>.
                    </p>
                )}

                {/* După o încercare eșuată, cele două parole se golesc; numele și emailul rămân. */}
                <Form
                    action="/register"
                    method="post"
                    resetOnError={['password', 'password_confirmation']}
                    noValidate
                    className="mt-6 flex flex-col gap-4 rounded-lg border border-line bg-surface p-6 shadow-xs"
                >
                    {({ errors, processing }) => (
                        <>
                            <TextField label="Nume" name="name" autoComplete="name" autoFocus error={errors.name} />
                            <TextField label="Email" name="email" type="email" autoComplete="email" error={errors.email} />
                            <TextField
                                label="Parolă"
                                name="password"
                                type="password"
                                autoComplete="new-password"
                                error={errors.password}
                            />
                            <TextField
                                label="Confirmă parola"
                                name="password_confirmation"
                                type="password"
                                autoComplete="new-password"
                                error={errors.password_confirmation}
                            />

                            <PrimaryButton disabled={processing}>{processing ? 'Se creează contul…' : 'Creează cont'}</PrimaryButton>
                        </>
                    )}
                </Form>

                <p className="mt-6 text-center text-sm text-ink-muted">
                    Ai deja cont?{' '}
                    <Link
                        href="/login"
                        className="rounded-sm font-medium text-accent hover:text-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                    >
                        Autentifică-te
                    </Link>
                </p>
            </div>
        </AppLayout>
    );
}
