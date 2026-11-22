<?php

declare(strict_types=1);

namespace DJGear\Core\I18n;

/**
 * Text writing direction for DJGear UI and emails.
 */
enum Direction: string
{
    case LTR = 'ltr';
    case RTL = 'rtl';
}
