import { Form, Head, Link } from '@inertiajs/react';
import AuthShell from '@/components/auth/auth-shell';
import PrimaryButton from '@/components/primary-button';
import TextField from '@/components/text-field';
import AppLayout from '@/layouts/app-layout';

type Props = {
    /** Linkul repository-ului cerut de vizitator înainte să intre în cont (sau null dacă nu a cerut niciunul). */
    pending_repository: string | null;
};

const linkClasses =
    'rounded-sm font-medium text-accent hover:text-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

export default function Login({ pending_repository }: Props) {
    return (
        <AppLayout>
            <Head title="Autentificare" />

            <AuthShell
                title="Intră în cont"
                lead="Contul e necesar doar ca să adaugi repository-uri. Documentația, diagramele și asistentul se pot folosi fără el."
                pendingRepository={pending_repository}
                pendingText="După ce intri în cont, adăugăm automat"
                footer={
                    <>
                        Nu ai cont?{' '}
                        <Link href="/register" className={linkClasses}>
                            Creează unul
                        </Link>
                    </>
                }
            >
                {/*
                  <Form> trimite câmpurile prin Inertia (POST /login), fără reîncărcarea paginii.
                  resetOnError golește parola după o încercare eșuată; emailul rămâne completat.
                  noValidate: validarea o face serverul, cu mesaje în română.
                */}
                <Form action="/login" method="post" resetOnError={['password']} noValidate className="flex flex-col gap-5">
                    {({ errors, processing }) => (
                        <>
                            <TextField label="Email" name="email" type="email" autoComplete="username" autoFocus error={errors.email} />
                            <TextField label="Parolă" name="password" type="password" autoComplete="current-password" error={errors.password} />

                            <div className="flex items-center gap-2">
                                <input
                                    id="remember"
                                    name="remember"
                                    type="checkbox"
                                    value="1"
                                    className="size-4 cursor-pointer accent-accent-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                                />
                                <label htmlFor="remember" className="cursor-pointer text-sm text-ink-muted">
                                    Ține-mă minte pe acest dispozitiv
                                </label>
                            </div>

                            <PrimaryButton size="lg" disabled={processing}>
                                {processing ? 'Se verifică…' : 'Intră în cont'}
                            </PrimaryButton>
                        </>
                    )}
                </Form>
            </AuthShell>
        </AppLayout>
    );
}
