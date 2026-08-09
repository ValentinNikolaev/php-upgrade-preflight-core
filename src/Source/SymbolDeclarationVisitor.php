<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Source;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

final class SymbolDeclarationVisitor extends NodeVisitorAbstract
{
    /** @var array<string, array{symbol: string, type: string}> */
    private array $declarations = [];
    private bool $dynamicLoader = false;

    public function enterNode(Node $node): Node
    {
        if (($node instanceof Stmt\Class_ && $node->name !== null)
            || $node instanceof Stmt\Interface_
            || $node instanceof Stmt\Trait_
            || $node instanceof Stmt\Enum_) {
            $name = $node->namespacedName;
            if ($name instanceof Name) {
                $this->addDeclaration((string) $name, 'class');
            }
        } elseif ($node instanceof Stmt\Function_) {
            $name = $node->namespacedName;
            if ($name instanceof Name) {
                $this->addDeclaration((string) $name, 'function');
            }
        } elseif ($node instanceof Stmt\Const_) {
            foreach ($node->consts as $constant) {
                $name = $constant->namespacedName;
                $this->addDeclaration((string) ($name ?? $constant->name), 'constant');
            }
        }

        if ($node instanceof Expr\Eval_) {
            $this->dynamicLoader = true;
        }
        if ($node instanceof Expr\FuncCall && $node->name instanceof Name
            && in_array(strtolower((string) $node->name), ['class_alias', 'spl_autoload_register'], true)) {
            $this->dynamicLoader = true;
        }

        return $node;
    }

    /** @return list<array{symbol: string, type: string}> */
    public function declarations(): array
    {
        $declarations = array_values($this->declarations);
        usort($declarations, static fn (array $left, array $right): int => [$left['symbol'], $left['type']] <=> [$right['symbol'], $right['type']]);

        return $declarations;
    }

    public function hasDynamicLoader(): bool
    {
        return $this->dynamicLoader;
    }

    private function addDeclaration(string $symbol, string $type): void
    {
        $symbol = ltrim($symbol, '\\');
        if ($symbol === '') {
            return;
        }

        $this->declarations[$type . "\0" . $symbol] = ['symbol' => $symbol, 'type' => $type];
    }
}
