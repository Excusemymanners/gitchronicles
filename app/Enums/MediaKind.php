<?php

namespace App\Enums;

/**
 * What GitChronicle makes for the presentation of a repository: the vertical video (MP4) and the slides
 * (PDF and PPTX). Each kind has its own row in media_assets and its own job on the "media" queue.
 */
enum MediaKind: string
{
    case Video = 'video';
    case Slides = 'slides';
}
