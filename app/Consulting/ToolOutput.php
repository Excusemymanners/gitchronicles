<?php

namespace App\Consulting;

/**
 * What a tool's output says, once read: the problems it found and the details for the page.
 */
final readonly class ToolOutput
{
    /**
     * @param  list<Finding>  $findings
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public array $findings = [],
        public array $details = [],
    ) {}
}
