<?php

namespace Tests\PHPStan;

use Livewire\Component;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Livewire\Component defines __call, so PHPStan treats any unknown $this->method() as valid
 * and the typo only surfaces at runtime as a BadMethodCallException.
 *
 * @implements Rule<MethodCall>
 */
class LivewireUndefinedMethodRule implements Rule
{
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->var instanceof Variable || $node->var->name !== 'this' || ! $node->name instanceof Identifier) {
            return [];
        }

        $classReflection = $scope->getClassReflection();

        if ($classReflection === null || ! $classReflection->is(Component::class)) {
            return [];
        }

        $method = $node->name->toString();

        if ($classReflection->hasNativeMethod($method)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf('Call to undefined method %s::%s() on a Livewire component.', $classReflection->getDisplayName(), $method))
                ->identifier('livewire.undefinedMethod')
                ->build(),
        ];
    }
}
