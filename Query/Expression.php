<?php

declare(strict_types=1);

namespace Plugins\Query\Query;

/**
 * Expression — raw SQL the builder passes through verbatim.
 *
 * WHY IT IS A CLASS AND NOT A STRING FLAG
 * ---------------------------------------
 * Every other string handed to the builder is treated as an IDENTIFIER and is
 * validated and quoted. Raw SQL has to bypass that, and the only safe way to
 * offer an escape hatch is to make using it visible: `raw('COUNT(*)')` is
 * greppable, reviewable, and impossible to reach by accident from a variable
 * that merely happened to contain SQL.
 *
 * A string arriving from a request can never become an Expression without
 * somebody typing `raw(` on that line. That is the whole design.
 *
 * BINDINGS STILL WORK. `raw('price * ?', [1.2])` keeps the parameter binding —
 * an escape hatch from identifier quoting must not also be an escape hatch from
 * parameterisation.
 */
final readonly class Expression
{
    /** @param list<mixed> $bindings */
    public function __construct(
        public string $sql,
        public array $bindings = [],
    ) {}

    public function __toString(): string
    {
        return $this->sql;
    }
}
