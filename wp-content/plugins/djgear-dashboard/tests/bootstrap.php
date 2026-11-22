<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

$coreAutoload = dirname(__DIR__, 2) . '/djgear-core/vendor/autoload.php';
if (is_readable($coreAutoload)) {
    require_once $coreAutoload;
}
