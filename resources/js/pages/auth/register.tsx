import { Form, Head, Link } from '@inertiajs/react';
import AuthShell from '@/components/auth/auth-shell';
import PrimaryButton from '@/components/primary-button';
import TextField from '@/components/text-field';
import { useI18n } from '@/hooks/use-i18n';
import AppLayout from '@/layouts/app-layout';

type Props = {
    /** Linkul repository-ului cerut de vizitator înainte să-și creeze contul (sau null dacă nu a cerut niciunul). */
    pending_repository: string | null;
};

const linkClasses =
    'rounded-sm font-medium text-accent hover:text-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

export default function Register({ pending_repository }: Props) {
    const { t } = useI18n();

    return (
        <AppLayout>
            <Head title={t('auth.register.title')} />

            <AuthShell
                title={t('auth.register.heading')}
                lead={t('auth.register.lead')}
                pendingRepository={pending_repository}
                pendingText={t('auth.pending.register')}
                footer={
                    <>
                        {t('auth.register.haveAccount')}{' '}
                        <Link href="/login" className={linkClasses}>
                            {t('auth.register.logIn')}
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
                            <TextField label={t('auth.fields.name')} name="name" autoComplete="name" autoFocus error={errors.name} />
                            <TextField label={t('auth.fields.email')} name="email" type="email" autoComplete="email" error={errors.email} />
                            <TextField
                                label={t('auth.fields.password')}
                                name="password"
                                type="password"
                                autoComplete="new-password"
                                hint={t('auth.fields.passwordHint')}
                                error={errors.password}
                            />
                            <TextField
                                label={t('auth.fields.passwordConfirmation')}
                                name="password_confirmation"
                                type="password"
                                autoComplete="new-password"
                                error={errors.password_confirmation}
                            />

                            <PrimaryButton size="lg" disabled={processing}>
                                {processing ? t('auth.register.submitting') : t('auth.register.submit')}
                            </PrimaryButton>
                        </>
                    )}
                </Form>
            </AuthShell>
        </AppLayout>
    );
}
