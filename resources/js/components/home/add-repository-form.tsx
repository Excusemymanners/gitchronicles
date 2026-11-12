import { Form, usePage } from '@inertiajs/react';
import PrimaryButton from '@/components/primary-button';
import { GitHubIcon } from '@/components/workspace/icons';
import { useI18n } from '@/hooks/use-i18n';

/**
 * Formularul principal al primei pagini: lipești linkul unui repository public de pe GitHub.
 * Oricine îl poate trimite; serverul decide ce urmează. Dacă repository-ul există deja, îi deschide pagina.
 * Un vizitator fără cont ajunge la autentificare, iar după ce intră în cont repository-ul se adaugă automat.
 * resetOnSuccess golește câmpul după un răspuns reușit; noValidate lasă validarea pe seama serverului,
 * care răspunde în limba aleasă.
 *
 * Câmpul și butonul stau în aceeași ramă, ca o singură comandă; rama primește conturul de focus al câmpului.
 */
export default function AddRepositoryForm() {
    const { auth, app_host } = usePage<{ app_host: string }>().props;
    const { t, rich } = useI18n();

    return (
        <Form action="/repositories" method="post" resetOnSuccess noValidate>
            {({ errors, processing }) => (
                <>
                    <label htmlFor="url" className="text-sm font-medium text-ink">
                        {t('home.form.label')}
                    </label>

                    <div
                        className={`mt-2 flex flex-col gap-2 rounded-xl border bg-surface p-2 shadow-card transition-colors has-[input:focus-visible]:outline-2 has-[input:focus-visible]:-outline-offset-1 sm:flex-row sm:items-center ${
                            errors.url
                                ? 'border-danger has-[input:focus-visible]:outline-danger'
                                : 'border-line-strong has-[input:focus-visible]:outline-accent'
                        }`}
                    >
                        <div className="flex min-w-0 flex-1 items-center gap-3 pl-3">
                            <GitHubIcon className="size-5 shrink-0 text-ink-subtle" />
                            <input
                                id="url"
                                name="url"
                                type="url"
                                inputMode="url"
                                placeholder={t('home.form.placeholder')}
                                autoComplete="off"
                                autoCapitalize="none"
                                spellCheck={false}
                                aria-invalid={errors.url ? true : undefined}
                                aria-describedby={errors.url ? 'url-error url-hint' : 'url-hint'}
                                // Conturul de focus îl desenează rama din jur (has-[input:focus-visible]).
                                className="block w-full min-w-0 bg-transparent py-2.5 text-base text-ink placeholder:text-ink-subtle focus:outline-none"
                            />
                        </div>
                        <PrimaryButton size="lg" disabled={processing}>
                            {processing ? t('home.form.submitting') : t('home.form.submit')}
                        </PrimaryButton>
                    </div>

                    {errors.url && (
                        <p id="url-error" className="mt-2 animate-fade-in text-sm text-danger">
                            {errors.url}
                        </p>
                    )}

                    <p id="url-hint" className="mt-3 text-sm text-ink-subtle">
                        {auth.user ? t('home.form.hintUser') : t('home.form.hintGuest')}
                    </p>

                    {/* Scurtătura: schimbi github.com cu adresa aplicației în orice link (vezi FrontDoorController). */}
                    <p className="mt-1 text-sm text-ink-subtle">
                        {rich('home.form.replaceHost', { host: <strong className="font-medium text-ink-muted">{app_host}</strong> })}
                    </p>
                </>
            )}
        </Form>
    );
}
