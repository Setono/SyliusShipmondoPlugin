<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;

return (new Configuration())
    ->addPathToExclude(__DIR__ . '/tests')
    // #[\SensitiveParameter] is a native PHP 8.2 attribute. On the PHP 8.1 analysis run the class cannot be
    // autoloaded; on 8.2+ it exists, so the ignore is matched only on 8.1.
    ->ignoreUnknownClasses(['SensitiveParameter'])
    ->disableReportingUnmatchedIgnores()
;
