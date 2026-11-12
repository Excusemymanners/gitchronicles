<?php

namespace App\Enums;

/**
 * The part a sentence plays in the story of the video. The role decides how the voice delivers it
 * (App\Media\Speech\SpeechDelivery): the opening and the closing are a little slower, the turn to the
 * solution a little brighter.
 */
enum SentenceRole: string
{
    case Hook = 'hook';
    case Problem = 'problem';
    case Turn = 'turn';
    case Detail = 'detail';
    case Closing = 'closing';
}
