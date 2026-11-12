<?php

namespace Tests\Unit\Documentation;

use App\Documentation\ReadmeSections;
use PHPUnit\Framework\TestCase;

/**
 * What is taken from a README: the problem it explains and the commands of its setup sections.
 */
class ReadmeSectionsTest extends TestCase
{
    private const README = <<<'MD'
        # Shop

        A small shop.

        ## Why

        Shops lose carts when the session ends.

        Customers then start again.

        ```
        not part of the problem
        ```

        ## Installation

        ```bash
        $ cp .env.example .env
        # install the packages
        composer install
        ```

        ### Run it

        ```sh
        php artisan serve
        ```

        ```json
        {"not": "a command"}
        ```

        ## License

        ```bash
        echo "not setup"
        ```
        MD;

    public function test_the_problem_is_the_text_under_a_why_heading_without_code_blocks(): void
    {
        $this->assertSame("Shops lose carts when the session ends.\n\nCustomers then start again.", ReadmeSections::problem(self::README));
    }

    public function test_there_is_no_problem_when_the_readme_has_no_such_heading(): void
    {
        $this->assertNull(ReadmeSections::problem("# Shop\n\nA small shop.\n\n## Installation\n\ncomposer install\n"));
    }

    public function test_the_setup_commands_are_the_shell_lines_of_the_setup_sections_in_order(): void
    {
        $this->assertSame(['cp .env.example .env', 'composer install', 'php artisan serve'], ReadmeSections::setupCommands(self::README));
    }

    public function test_there_are_no_setup_commands_without_a_setup_section(): void
    {
        $this->assertSame([], ReadmeSections::setupCommands("# Shop\n\n## License\n\n```bash\nmake\n```\n"));
    }
}
