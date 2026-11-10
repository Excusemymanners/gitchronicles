<?php

namespace App\Documentation;

use App\Ai\AiException;

/**
 * Writes the Markdown of the documentation pages from their context. Two implementations:
 *
 * - AiDocumentationWriter: Claude writes the text (through App\Ai\TextGenerator);
 * - TemplateDocumentationWriter: the text is assembled from the analysis, without AI (offline mode,
 *   and the fallback when Claude fails).
 *
 * The SourceLinks are separate from the context: they hold the commit, which is not part of the input hash.
 */
interface DocumentationWriter
{
    /**
     * The page of one module.
     *
     * @throws AiException when Claude could not write it (AiDocumentationWriter only)
     */
    public function module(ModuleContext $context, SourceLinks $links): WrittenPage;

    /**
     * The overview page („Despre proiect”).
     *
     * @throws AiException when Claude could not write it (AiDocumentationWriter only)
     */
    public function overview(OverviewContext $context, SourceLinks $links): WrittenPage;

    /**
     * The generated README of the project.
     *
     * @throws AiException when Claude could not write it (AiDocumentationWriter only)
     */
    public function readme(OverviewContext $context, SourceLinks $links): WrittenPage;
}
