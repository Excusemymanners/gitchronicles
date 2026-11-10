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
                <p className="mt-1 text-sm text-gray-600">Creează un cont ca să adaugi repository-uri de pe GitHub.</p>

                {pending_repository && (
                    <p className="mt-6 rounded-lg border border-indigo-200 bg-indigo-50 p-4 text-sm text-indigo-800">
                        După ce îți creezi contul, adăugăm automat <span className="font-medium break-all">{pending_repository}</span>.
                    </p>
                )}

                {/* După o încercare eșuată, cele două parole se golesc; numele și emailul rămân. */}
                <Form
                    action="/register"
                    method="post"
                    resetOnError={['password', 'password_confirmation']}
                    noValidate
                    className="mt-6 flex flex-col gap-4 rounded-lg border border-gray-200 bg-white p-6 shadow-xs"
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

                <p className="mt-6 text-center text-sm text-gray-600">
                    Ai deja cont?{' '}
                    <Link
                        href="/login"
                        className="rounded-sm font-medium text-indigo-600 hover:text-indigo-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                    >
                        Autentifică-te
                    </Link>
                </p>
            </div>
        </AppLayout>
    );
}
