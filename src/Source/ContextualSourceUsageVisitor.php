<?php

declare(strict_types=1);

namespace PhpUpgradePreflight\Core\Source;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

final class ContextualSourceUsageVisitor extends NodeVisitorAbstract
{
    private string $file;
    private string $namespace = '';

    /** @var list<array{symbol: string, usage_type: string, line: int}> */
    private array $usages = [];

    public function __construct(string $file)
    {
        $this->file = str_replace('\\', '/', $file);
    }

    public function enterNode(Node $node): Node
    {
        if ($node instanceof Stmt\Namespace_) {
            $this->namespace = $node->name === null ? '' : (string) $node->name;

            return $node;
        }

        if ($node instanceof Stmt\Class_) {
            $this->inspectClass($node);
        }

        if ($node instanceof Stmt\Property) {
            $this->inspectProperty($node);
        }

        if ($node instanceof Stmt\Return_ && strtolower($this->file) === 'bootstrap/providers.php' && $node->expr !== null) {
            $this->addClassReferences($node->expr, 'service_provider');
        }

        if ($node instanceof Expr\Array_) {
            $this->inspectConfigurationArray($node);
        }

        if ($node instanceof Expr\FuncCall) {
            $this->inspectFunctionCall($node);
        }

        if ($node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall) {
            $this->inspectMethodLikeCall($node);
        }

        return $node;
    }

    public function leaveNode(Node $node): ?Node
    {
        if ($node instanceof Stmt\Namespace_) {
            $this->namespace = '';
        }

        return null;
    }

    /** @return list<array{symbol: string, usage_type: string, line: int}> */
    public function usages(): array
    {
        return $this->usages;
    }

    private function inspectClass(Stmt\Class_ $class): void
    {
        if ($class->name === null || $class->extends === null) {
            return;
        }

        $parent = (string) $class->extends;
        $declaredClass = ltrim($this->namespace . '\\' . (string) $class->name, '\\');

        if ($this->shortName($parent) === 'serviceprovider') {
            $this->addUsage($declaredClass, 'service_provider', $class->getStartLine());
        }

        if ($this->isConsoleCommandParent($parent)) {
            $this->addUsage($declaredClass, 'console_command', $class->getStartLine());
        }
    }

    private function inspectProperty(Stmt\Property $property): void
    {
        foreach ($property->props as $propertyItem) {
            $name = strtolower((string) $propertyItem->name);
            if ($propertyItem->default === null) {
                continue;
            }

            if (in_array($name, ['middleware', 'middlewarealiases', 'middlewaregroups', 'routemiddleware'], true)) {
                $this->addClassReferences($propertyItem->default, 'middleware_reference');
            }

            if ($name === 'commands') {
                $this->addClassReferences($propertyItem->default, 'console_command');
            }
        }
    }

    private function inspectConfigurationArray(Expr\Array_ $array): void
    {
        if (!str_starts_with(strtolower($this->file), 'config/')) {
            return;
        }

        foreach ($array->items as $item) {
            if ($item === null || !$item->key instanceof String_) {
                continue;
            }

            if (strtolower($item->key->value) === 'providers') {
                $this->addClassReferences($item->value, 'service_provider');
            }

            if (strtolower($this->file) === 'config/app.php'
                && strtolower($item->key->value) === 'aliases') {
                $this->addAliasReferences($item->value);
            }
        }
    }

    private function addAliasReferences(Node $node): void
    {
        if ($node instanceof String_ && strpos($node->value, '\\') !== false) {
            $this->addUsage($node->value, 'facade_alias', $node->getStartLine());

            return;
        }

        if ($node instanceof Expr\ClassConstFetch
            && $node->class instanceof Name
            && $node->name instanceof Identifier
            && strtolower((string) $node->name) === 'class') {
            $this->addUsage((string) $node->class, 'facade_alias', $node->class->getStartLine());

            return;
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            $value = $node->{$subNodeName};
            if ($value instanceof Node) {
                $this->addAliasReferences($value);
                continue;
            }

            if (!is_array($value)) {
                continue;
            }

            foreach ($value as $child) {
                if ($child instanceof Node) {
                    $this->addAliasReferences($child);
                }
            }
        }
    }

    private function inspectFunctionCall(Expr\FuncCall $call): void
    {
        if (!$call->name instanceof Name) {
            return;
        }

        $function = $this->shortName((string) $call->name);
        if ($this->isNamedFunction($call->name, 'config') && isset($call->args[0])) {
            $this->addConfigReferences($call->args[0]->value);
        }

        if (in_array($function, ['mock', 'prophesize', 'spy'], true)) {
            $this->addTestDoubleTarget($call->args);
        }
    }

    /** @param Expr\MethodCall|Expr\StaticCall $call */
    private function inspectMethodLikeCall($call): void
    {
        if (!$call->name instanceof Identifier) {
            return;
        }

        $method = strtolower((string) $call->name);

        if ($this->isConfigCall($call, $method) && isset($call->args[0])) {
            $this->addConfigReferences($call->args[0]->value, $method !== 'set');
        }

        if ($this->isServiceProviderRegistration($call, $method)) {
            $this->addClassReferences($call->args[0]->value, 'service_provider');
        }

        if (in_array($method, [
            'aliasmiddleware',
            'appendmiddleware',
            'appendmiddlewaretogroup',
            'middleware',
            'middlewaregroup',
            'prependmiddleware',
            'prependmiddlewaretogroup',
            'pushmiddleware',
            'withoutmiddleware',
        ], true)) {
            $this->addArgumentClassReferences($call->args, 'middleware_reference');
        }

        if (in_array($method, ['commands', 'loadcommands', 'registercommands', 'resolvecommands'], true)) {
            $this->addArgumentClassReferences($call->args, 'console_command');
        }

        if (in_array($method, [
            'createconfiguredmock',
            'createmock',
            'createpartialmock',
            'createstub',
            'getmockbuilder',
            'getmockforabstractclass',
            'getmockfortrait',
            'mock',
            'partialmock',
            'prophesize',
            'spy',
        ], true)) {
            $this->addTestDoubleTarget($call->args);
        }

        if ($call instanceof Expr\StaticCall
            && in_array($method, ['fake', 'partialmock', 'shouldreceive', 'spy'], true)
            && $call->class instanceof Name) {
            $class = (string) $call->class;
            if ($this->shortName($class) !== 'mockery') {
                $this->addUsage($class, 'test_double', $call->class->getStartLine());
            }
        }
    }

    /** @param Expr\MethodCall|Expr\StaticCall $call */
    private function isConfigCall($call, string $method): bool
    {
        if (!in_array($method, ['array', 'boolean', 'collection', 'get', 'getmany', 'has', 'integer', 'missing', 'pull', 'set', 'string'], true)) {
            return false;
        }

        if ($call instanceof Expr\StaticCall) {
            return $call->class instanceof Name && $this->isConfigFacade($call->class);
        }

        return $call->var instanceof Expr\FuncCall
            && $call->var->name instanceof Name
            && $this->isNamedFunction($call->var->name, 'config');
    }

    /** @param Expr\MethodCall|Expr\StaticCall $call */
    private function isServiceProviderRegistration($call, string $method): bool
    {
        if (!isset($call->args[0])) {
            return false;
        }

        if (in_array($method, ['registerprovider', 'withproviders'], true)) {
            return true;
        }

        if ($method !== 'register') {
            return false;
        }

        return $this->hasApplicationReceiver($call)
            || $this->hasClassReferenceEndingWith($call->args[0]->value, 'serviceprovider');
    }

    /** @param Expr\MethodCall|Expr\StaticCall $call */
    private function hasApplicationReceiver($call): bool
    {
        if ($call instanceof Expr\StaticCall) {
            return $call->class instanceof Name && $this->shortName((string) $call->class) === 'application';
        }

        $receiver = $call->var;
        if ($receiver instanceof Expr\Variable && is_string($receiver->name)) {
            return in_array(strtolower($receiver->name), ['app', 'application', 'laravel'], true);
        }

        if ($receiver instanceof Expr\PropertyFetch && $receiver->name instanceof Identifier) {
            return in_array(strtolower((string) $receiver->name), ['app', 'application', 'laravel'], true);
        }

        if ($receiver instanceof Expr\FuncCall && $receiver->name instanceof Name) {
            return in_array(strtolower((string) $receiver->name), ['app', 'application'], true);
        }

        return $receiver instanceof Expr\MethodCall
            && $receiver->name instanceof Identifier
            && in_array(strtolower((string) $receiver->name), ['app', 'application', 'getapplication'], true);
    }

    /** @param list<Arg> $arguments */
    private function addArgumentClassReferences(array $arguments, string $usageType): void
    {
        foreach ($arguments as $argument) {
            $this->addClassReferences($argument->value, $usageType);
        }
    }

    private function addClassReferences(Node $node, string $usageType): void
    {
        if ($node instanceof Expr\ClassConstFetch
            && $node->class instanceof Name
            && $node->name instanceof Identifier
            && strtolower((string) $node->name) === 'class') {
            $this->addUsage((string) $node->class, $usageType, $node->class->getStartLine());

            return;
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            $value = $node->{$subNodeName};
            if ($value instanceof Node) {
                $this->addClassReferences($value, $usageType);
                continue;
            }

            if (!is_array($value)) {
                continue;
            }

            foreach ($value as $child) {
                if ($child instanceof Node) {
                    $this->addClassReferences($child, $usageType);
                }
            }
        }
    }

    private function hasClassReferenceEndingWith(Node $node, string $suffix): bool
    {
        if ($node instanceof Expr\ClassConstFetch
            && $node->class instanceof Name
            && $node->name instanceof Identifier
            && strtolower((string) $node->name) === 'class') {
            return str_ends_with($this->shortName((string) $node->class), $suffix);
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            $value = $node->{$subNodeName};
            if ($value instanceof Node && $this->hasClassReferenceEndingWith($value, $suffix)) {
                return true;
            }

            if (!is_array($value)) {
                continue;
            }

            foreach ($value as $child) {
                if ($child instanceof Node && $this->hasClassReferenceEndingWith($child, $suffix)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function addConfigReferences(Node $node, bool $includeArrayValues = false): void
    {
        if ($node instanceof String_) {
            $this->addUsage($node->value, 'config_reference', $node->getStartLine());

            return;
        }

        if (!$node instanceof Expr\Array_) {
            return;
        }

        foreach ($node->items as $item) {
            if ($item === null) {
                continue;
            }

            if ($item->key instanceof String_) {
                $this->addUsage($item->key->value, 'config_reference', $item->key->getStartLine());
                continue;
            }

            if ($includeArrayValues && $item->value instanceof String_) {
                $this->addUsage($item->value->value, 'config_reference', $item->value->getStartLine());
            }
        }
    }

    /** @param list<Arg> $arguments */
    private function addTestDoubleTarget(array $arguments): void
    {
        if (!isset($arguments[0])) {
            return;
        }

        $target = $arguments[0]->value;
        if ($target instanceof String_) {
            $symbol = preg_replace('/^(?:alias|overload):/i', '', $target->value);
            if (is_string($symbol)) {
                $this->addUsage($symbol, 'test_double', $target->getStartLine());
            }

            return;
        }

        $this->addClassReferences($target, 'test_double');
    }

    private function isConsoleCommandParent(string $parent): bool
    {
        if ($this->shortName($parent) !== 'command') {
            return false;
        }

        $normalizedParent = strtolower(str_replace('\\', '/', $parent));
        $normalizedFile = strtolower($this->file);

        return strpos($normalizedParent, '/console/') !== false
            || strpos($normalizedFile, '/console/commands/') !== false
            || str_starts_with($normalizedFile, 'console/commands/');
    }

    private function shortName(string $name): string
    {
        $parts = explode('\\', ltrim($name, '\\'));

        return strtolower((string) end($parts));
    }

    private function isNamedFunction(Name $name, string $function): bool
    {
        return strtolower(ltrim((string) $name, '\\')) === $function;
    }

    private function isConfigFacade(Name $name): bool
    {
        $normalized = strtolower(ltrim((string) $name, '\\'));

        return $normalized === 'config'
            || str_ends_with($normalized, '\\facade\\config')
            || str_ends_with($normalized, '\\facades\\config');
    }

    private function addUsage(string $symbol, string $usageType, int $line): void
    {
        $symbol = ltrim(trim($symbol), '\\');
        if ($symbol === '') {
            return;
        }

        $this->usages[] = [
            'symbol' => $symbol,
            'usage_type' => $usageType,
            'line' => $line,
        ];
    }
}
