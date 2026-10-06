<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Source;

use PhpParser\Node;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\NodeVisitorAbstract;

final class ExplicitFullyQualifiedNameVisitor extends NodeVisitorAbstract
{
    public const ATTRIBUTE = 'php_upgrade_preflight_explicit_fully_qualified';

    public function enterNode(Node $node): Node
    {
        if ($node instanceof FullyQualified) {
            $node->setAttribute(self::ATTRIBUTE, true);
        }

        return $node;
    }
}
