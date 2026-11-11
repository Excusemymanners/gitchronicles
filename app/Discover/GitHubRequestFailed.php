<?php

namespace App\Discover;

use RuntimeException;

/**
 * GitHub could not answer a request of `discover:refresh`: no network after the retries, a server error
 * after the retries, or an answer the command cannot use (for example an invalid search). The message
 * is in Romanian, for the terminal and the log.
 */
class GitHubRequestFailed extends RuntimeException {}
