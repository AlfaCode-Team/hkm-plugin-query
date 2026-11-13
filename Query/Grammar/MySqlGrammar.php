<?php

declare(strict_types=1);

namespace Plugins\Query\Query\Grammar;

/** MySQL / MariaDB: backtick identifiers, LIMIT … OFFSET …. */
final class MySqlGrammar extends Grammar
{
    public function name(): string { return 'mysql'; }

    protected function quotes(): array { return ['`', '`']; }
}
