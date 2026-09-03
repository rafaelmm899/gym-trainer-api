<?php

namespace App\Enums\Session;

/**
 * The lifecycle of a training session: `in_progress` while the user is logging
 * sets, `completed` once they close it (a later story). A session is born
 * `in_progress`.
 */
enum SessionStatus: string
{
    case InProgress = 'in_progress';
    case Completed = 'completed';
}
