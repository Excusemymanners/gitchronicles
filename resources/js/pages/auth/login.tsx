import { Form, Head, Link } from '@inertiajs/react';
import PrimaryButton from '@/components/primary-button';
import TextField from '@/components/text-field';
import AppLayout from '@/layouts/app-layout';

type Props = {
    /** Linkul repository-ului cerut de vizitator înainte să intre în cont (sau null dacă nu a cerut niciunul). */
    pending_repository: string | null;
};

export default function Login({ pending_repository }: Props) {
    return (
        <AppLayout>
            <Head title="Autentificare" />

            <div className="mx-auto w-full max-w-sm">
                <h1 className="text-2xl font-semibold tracking-tight">Autentificare</h1>
                <p className="mt-1 text-sm text-gray-600">Intră în cont ca să adaugi repository-uri noi.</p>

                {pending_repository && (
                    <p className="mt-6 rounded-lg border border-indigo-200 bg-indigo-50 p-4 text-sm text-indigo-800">
                        După ce intri în cont, adăugăm automat <span className="font-medium break-all">{pending_repository}</span>.
                    </p>
                )}

                {/*
                  <Form> trimite câmpurile prin Inertia (POST /login), fără reîncărcarea paginii.
                  resetOnError golește parola după o încercare eșuată; emailul rămâne completat.
                  noValidate: validarea o face serverul, cu mesaje în română.
                */}
                <Form
                    action="/login"
                    method="post"
                    resetOnError={['password']}
                    noValidate
                    className="mt-6 flex flex-col gap-4 rounded-lg border border-gray-200 bg-white p-6 shadow-xs"
                >
                    {({ errors, processing }) => (
                        <>
                            <TextField
                                label="Email"
                                name="email"
                                type="email"
                                autoComplete="username"
                                autoFocus
                                error={errors.email}
                            />
                            <TextField
                                label="Parolă"
                                name="password"
                                type="password"
                                autoComplete="current-password"
                                error={errors.password}
                            />

                            <div className="flex items-center gap-2">
                                <input
                                    id="remember"
                                    name="remember"
                                    type="checkbox"
                                    value="1"
                                    className="size-4 cursor-pointer accent-indigo-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                                />
                                <label htmlFor="remember" className="cursor-pointer text-sm text-gray-700">
                                    Ține-mă minte
                                </label>
                            </div>

                            <PrimaryButton disabled={processing}>{processing ? 'Se verifică…' : 'Intră în cont'}</PrimaryButton>
                        </>
                    )}
                </Form>

                <p className="mt-6 text-center text-sm text-gray-600">
                    Nu ai cont?{' '}
                    <Link
                        href="/register"
                        className="rounded-sm font-medium text-indigo-600 hover:text-indigo-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                    >
                        Creează unul
                    </Link>
                </p>
            </div>
        </AppLayout>
    );
}
