<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Source;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

final class SourceUsageVisitor extends NodeVisitorAbstract
{
    /** @var list<array{symbol: string, usage_type: string, line: int}> */
    private array $usages = [];

    /** @var array<int, true> */
    private array $specificallyClassifiedNames = [];

    public function enterNode(Node $node): Node
    {
        if ($node instanceof Stmt\Use_) {
            foreach ($node->uses as $use) {
                $type = $use->type === Stmt\Use_::TYPE_UNKNOWN ? $node->type : $use->type;
                $this->addUsage((string) $use->name, $this->importUsageType($type), $use->getStartLine());
            }

            return $node;
        }

        if ($node instanceof Stmt\GroupUse) {
            foreach ($node->uses as $use) {
                $type = $use->type === Stmt\Use_::TYPE_UNKNOWN ? $node->type : $use->type;
                $this->addUsage(
                    (string) $node->prefix . '\\' . (string) $use->name,
                    $this->importUsageType($type),
                    $use->getStartLine()
                );
            }

            return $node;
        }

        if ($node instanceof Expr\StaticCall && $node->class instanceof Name) {
            $this->addSpecificNameUsage($node->class, 'static_call');
        } elseif ($node instanceof Expr\StaticPropertyFetch && $node->class instanceof Name) {
            $this->addSpecificNameUsage($node->class, 'static_property_access');
        } elseif ($node instanceof Expr\ClassConstFetch && $node->class instanceof Name) {
            $this->addSpecificNameUsage($node->class, 'class_constant_access');
        } elseif ($node instanceof Expr\New_ && $node->class instanceof Name) {
            $this->addSpecificNameUsage($node->class, 'instantiated_class');
        } elseif ($node instanceof Stmt\Class_) {
            if ($node->extends !== null) {
                $this->addSpecificNameUsage($node->extends, 'inheritance');
            }
            foreach ($node->implements as $interface) {
                $this->addSpecificNameUsage($interface, 'interface_reference');
            }
        } elseif ($node instanceof Stmt\Interface_) {
            foreach ($node->extends as $interface) {
                $this->addSpecificNameUsage($interface, 'interface_reference');
            }
        } elseif ($node instanceof Stmt\Enum_) {
            foreach ($node->implements as $interface) {
                $this->addSpecificNameUsage($interface, 'interface_reference');
            }
        } elseif ($node instanceof Stmt\TraitUse) {
            foreach ($node->traits as $trait) {
                $this->addSpecificNameUsage($trait, 'trait_reference');
            }
        } elseif ($node instanceof Node\Attribute) {
            $this->addSpecificNameUsage($node->name, 'attribute');
        } elseif ($node instanceof Expr\FuncCall && $node->name instanceof Name) {
            $this->addSpecificNameUsage($node->name, 'function_call');
        } elseif ($node instanceof Name
            && $node->getAttribute(ExplicitFullyQualifiedNameVisitor::ATTRIBUTE) === true
            && !isset($this->specificallyClassifiedNames[spl_object_id($node)])) {
            $this->addNameUsage($node, 'fully_qualified_name');
        }

        return $node;
    }

    /** @return list<array{symbol: string, usage_type: string, line: int}> */
    public function usages(): array
    {
        return $this->usages;
    }

    private function addSpecificNameUsage(Name $name, string $usageType): void
    {
        $this->addNameUsage($name, $usageType);
        $this->specificallyClassifiedNames[spl_object_id($name)] = true;
    }

    private function addNameUsage(Name $name, string $usageType): void
    {
        $symbol = (string) $name;
        if (in_array(strtolower($symbol), ['self', 'static', 'parent'], true)) {
            return;
        }

        $this->addUsage($symbol, $usageType, $name->getStartLine());
    }

    private function addUsage(string $symbol, string $usageType, int $line): void
    {
        if ($symbol === '') {
            return;
        }

        $this->usages[] = [
            'symbol' => ltrim($symbol, '\\'),
            'usage_type' => $usageType,
            'line' => $line,
        ];
    }

    private function importUsageType(int $type): string
    {
        if ($type === Stmt\Use_::TYPE_FUNCTION) {
            return 'function_import';
        }

        if ($type === Stmt\Use_::TYPE_CONSTANT) {
            return 'constant_import';
        }

        return 'namespace_import';
    }
}
