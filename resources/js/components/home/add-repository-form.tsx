import { Form, usePage } from '@inertiajs/react';
import { useRef } from 'react';
import PrimaryButton from '@/components/primary-button';
import { FieldError } from '@/components/text-field';
import { fieldShellClasses } from '@/components/text-input';
import { GitHubIcon } from '@/components/workspace/icons';
import { useI18n } from '@/hooks/use-i18n';

type Props = {
    /** "owner/name" of up to three repositories already documented here; a click puts its link in the field. */
    examples: string[];
};

/**
 * The home page's centrepiece: paste the link of a public GitHub repository.
 * Anyone may send it; the server decides what follows. If the repository is already here, it opens its page.
 * A guest goes to log in first, and the repository is added right after. resetOnSuccess empties the field after
 * a successful answer; noValidate leaves validation to the server, which answers in the chosen language.
 *
 * The field and the button sit in one frame, as one command (fieldShellClasses): the frame takes the field's focus
 * ring and its error state. Under it, real repositories already documented fill the field with one click.
 */
export default function AddRepositoryForm({ examples }: Props) {
    const { auth, app_host } = usePage<{ app_host: string }>().props;
    const { t, rich } = useI18n();
    const inputRef = useRef<HTMLInputElement>(null);

    /** Puts an example's link in the field and gives the field the focus, ready to send with Enter. */
    function fillWith(fullName: string) {
        if (inputRef.current) {
            inputRef.current.value = `https://github.com/${fullName}`;
            inputRef.current.focus();
        }
    }

    return (
        <Form action="/repositories" method="post" resetOnSuccess noValidate>
            {({ errors, processing }) => (
                <>
                    <label htmlFor="url" className="sr-only">
                        {t('home.form.label')}
                    </label>

                    <div className={`flex flex-col gap-1.5 rounded-xl p-1.5 sm:flex-row sm:items-center ${fieldShellClasses(Boolean(errors.url))}`}>
                        <div className="flex min-w-0 flex-1 items-center gap-3 pl-3">
                            <GitHubIcon className="size-5 shrink-0 text-ink-subtle" />
                            <input
                                ref={inputRef}
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
                                // The frame around it draws the focus ring (fieldShellClasses).
                                className="block h-11 w-full min-w-0 bg-transparent text-base text-ink placeholder:text-ink-subtle focus:outline-none sm:text-[1.0625rem]"
                            />
                        </div>
                        <PrimaryButton size="lg" loading={processing}>
                            {processing ? t('home.form.submitting') : t('home.form.submit')}
                        </PrimaryButton>
                    </div>

                    {errors.url && (
                        <FieldError id="url-error" className="mt-2.5 justify-center">
                            {errors.url}
                        </FieldError>
                    )}

                    {examples.length > 0 && (
                        <div className="mt-4 flex flex-wrap items-center justify-center gap-2 text-small text-ink-subtle">
                            <span>{t('home.form.examples')}</span>
                            {examples.map((fullName) => (
                                <button
                                    key={fullName}
                                    type="button"
                                    onClick={() => fillWith(fullName)}
                                    aria-label={t('home.form.exampleFill', { repository: fullName })}
                                    className="pressable cursor-pointer rounded-full border border-line bg-surface px-2.5 py-1 font-mono text-[0.75rem] text-ink-muted hover:border-line-strong hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                                >
                                    {fullName}
                                </button>
                            ))}
                        </div>
                    )}

                    <p id="url-hint" className="mx-auto mt-5 max-w-xl text-center text-small text-ink-subtle">
                        {auth.user ? t('home.form.hintUser') : t('home.form.hintGuest')}{' '}
                        {/* The shortcut: swap github.com for the app's address in any link (see FrontDoorController). */}
                        {rich('home.form.replaceHost', { host: <strong className="font-medium text-ink-muted">{app_host}</strong> })}
                    </p>
                </>
            )}
        </Form>
    );
}
