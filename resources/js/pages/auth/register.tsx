import { Form, Head, Link } from '@inertiajs/react';
import AuthShell from '@/components/auth/auth-shell';
import PrimaryButton from '@/components/primary-button';
import TextField from '@/components/text-field';
import AppLayout from '@/layouts/app-layout';

type Props = {
    /** Linkul repository-ului cerut de vizitator înainte să-și creeze contul (sau null dacă nu a cerut niciunul). */
    pending_repository: string | null;
};

const linkClasses =
    'rounded-sm font-medium text-accent hover:text-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

export default function Register({ pending_repository }: Props) {
    return (
        <AppLayout>
            <Head title="Cont nou" />

            <AuthShell
                title="Creează un cont"
                lead="Cu un cont poți adăuga repository-uri publice de pe GitHub și le poți șterge pe cele adăugate de tine."
                pendingRepository={pending_repository}
                pendingText="După ce îți creezi contul, adăugăm automat"
                footer={
                    <>
                        Ai deja cont?{' '}
                        <Link href="/login" className={linkClasses}>
                            Intră în cont
                        </Link>
                    </>
                }
            >
                {/* După o încercare eșuată, cele două parole se golesc; numele și emailul rămân. */}
                <Form
                    action="/register"
                    method="post"
                    resetOnError={['password', 'password_confirmation']}
                    noValidate
                    className="flex flex-col gap-5"
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
                                hint="Cel puțin 8 caractere."
                                error={errors.password}
                            />
                            <TextField
                                label="Confirmă parola"
                                name="password_confirmation"
                                type="password"
                                autoComplete="new-password"
                                error={errors.password_confirmation}
                            />

                            <PrimaryButton size="lg" disabled={processing}>
                                {processing ? 'Se creează contul…' : 'Creează cont'}
                            </PrimaryButton>
                        </>
                    )}
                </Form>
            </AuthShell>
        </AppLayout>
    );
}
