<?php

namespace App\Exceptions;

/**
 * The repository is over one of the limits of config gitchronicle.git (too many files, too many bytes, too much
 * disk). Unlike a network error, trying again cannot help, so the job fails at once and the clone is deleted.
 */
class RepositoryTooLarge extends RepositoryProcessingException {}
