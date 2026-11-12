<?php

namespace App\Support;

/**
 * The language of everything GitChronicle generates for a repository: the documentation pages, the
 * labels, titles and captions of the diagrams, the flows and the assistant's answers. It is set by
 * config('gitchronicle.content_language') (env CONTENT_LANGUAGE, "en" by default) and is independent
 * of the interface language, which the visitor switches.
 *
 * The AI prompts are written in English (the instructions models follow best) and ask for the answer
 * in name(); the deterministic texts (the template documentation, the diagram labels, the answer without
 * AI) are written in English. The code is part of the input hash of every documentation page and flow,
 * so changing the language writes them again instead of keeping pages in the old language.
 *
 * Adding a second content language is a contained change: add its code and name to NAMES (the prompts
 * then ask for it), and give the deterministic writers its sentences (TemplateDocumentationWriter,
 * ArchitectureDiagramBuilder with LayerClassifier, the retrieval-only answer of AnswerQuestion and the
 * starter questions), chosen by code(). Until then a code that is not in NAMES falls back to English,
 * so a typo in .env cannot stop the pipeline or mix two languages on one page.
 */
final class ContentLanguage
{
    /**
     * The code of the default language.
     */
    public const DEFAULT = 'en';

    /**
     * The supported languages: code => the name used in the AI prompts.
     */
    public const NAMES = [
        'en' => 'English',
    ];

    /**
     * The configured language code, or the default when the configured one is not supported.
     */
    public static function code(): string
    {
        $code = strtolower(trim((string) config('gitchronicle.content_language', self::DEFAULT)));

        return array_key_exists($code, self::NAMES) ? $code : self::DEFAULT;
    }

    /**
     * The language's name in English, as the prompts say it ("Write in English").
     */
    public static function name(): string
    {
        return self::NAMES[self::code()];
    }
}
