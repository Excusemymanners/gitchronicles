import { Form, Head, Link, usePage } from '@inertiajs/react';
import AuthShell from '@/components/auth/auth-shell';
import PrimaryButton from '@/components/primary-button';
import TextField from '@/components/text-field';
import { useI18n } from '@/hooks/use-i18n';
import AppLayout from '@/layouts/app-layout';

type Props = {
    /** Linkul repository-ului cerut de vizitator înainte să intre în cont (sau null dacă nu a cerut niciunul). */
    pending_repository: string | null;
};

const linkClasses =
    'rounded-sm font-medium text-accent hover:text-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

export default function Login({ pending_repository }: Props) {
    const { t } = useI18n();
    const { registration_open } = usePage().props;

    return (
        <AppLayout>
            <Head title={t('auth.login.title')} />

            <AuthShell
                title={t('auth.login.heading')}
                lead={t('auth.login.lead')}
                pendingRepository={pending_repository}
                pendingText={t('auth.pending.login')}
                footer={
                    registration_open ? (
                        <>
                            {t('auth.login.noAccount')}{' '}
                            <Link href="/register" className={linkClasses}>
                                {t('auth.login.createOne')}
                            </Link>
                        </>
                    ) : undefined
                }
            >
                {/*
                  <Form> trimite câmpurile prin Inertia (POST /login), fără reîncărcarea paginii.
                  resetOnError golește parola după o încercare eșuată; emailul rămâne completat.
                  noValidate: validarea o face serverul, cu mesaje în limba aleasă.
                */}
                <Form action="/login" method="post" resetOnError={['password']} noValidate className="flex flex-col gap-5">
                    {({ errors, processing }) => (
                        <>
                            <TextField label={t('auth.fields.email')} name="email" type="email" autoComplete="username" autoFocus error={errors.email} size="lg" />
                            <TextField
                                label={t('auth.fields.password')}
                                name="password"
                                type="password"
                                autoComplete="current-password"
                                error={errors.password}
                                size="lg"
                            />

                            <div className="flex items-center gap-2">
                                <input
                                    id="remember"
                                    name="remember"
                                    type="checkbox"
                                    value="1"
                                    className="size-4 cursor-pointer accent-accent-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                                />
                                <label htmlFor="remember" className="cursor-pointer text-sm text-ink-muted">
                                    {t('auth.fields.remember')}
                                </label>
                            </div>

                            <PrimaryButton size="lg" loading={processing}>
                                {processing ? t('auth.login.submitting') : t('auth.login.submit')}
                            </PrimaryButton>
                        </>
                    )}
                </Form>
            </AuthShell>
        </AppLayout>
    );
}
