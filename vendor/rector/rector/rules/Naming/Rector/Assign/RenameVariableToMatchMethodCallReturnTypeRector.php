<?php

declare (strict_types=1);
namespace Rector\Naming\Rector\Assign;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Stmt\ClassLike;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\ObjectType;
use Rector\Core\Rector\AbstractRector;
use Rector\FamilyTree\Reflection\FamilyRelationsAnalyzer;
use Rector\Naming\Guard\BreakingVariableRenameGuard;
use Rector\Naming\Matcher\VariableAndCallAssignMatcher;
use Rector\Naming\Naming\ExpectedNameResolver;
use Rector\Naming\NamingConvention\NamingConventionAnalyzer;
use Rector\Naming\PhpDoc\VarTagValueNodeRenamer;
use Rector\Naming\ValueObject\VariableAndCallAssign;
use Rector\Naming\VariableRenamer;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\PHPStanStaticTypeMapper\Utils\TypeUnwrapper;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;
/**
 * @see \Rector\Tests\Naming\Rector\Assign\RenameVariableToMatchMethodCallReturnTypeRector\RenameVariableToMatchMethodCallReturnTypeRectorTest
 */
final class RenameVariableToMatchMethodCallReturnTypeRector extends \Rector\Core\Rector\AbstractRector
{
    /**
     * @readonly
     * @var \Rector\Naming\Guard\BreakingVariableRenameGuard
     */
    private $breakingVariableRenameGuard;
    /**
     * @readonly
     * @var \Rector\Naming\Naming\ExpectedNameResolver
     */
    private $expectedNameResolver;
    /**
     * @readonly
     * @var \Rector\Naming\NamingConvention\NamingConventionAnalyzer
     */
    private $namingConventionAnalyzer;
    /**
     * @readonly
     * @var \Rector\Naming\PhpDoc\VarTagValueNodeRenamer
     */
    private $varTagValueNodeRenamer;
    /**
     * @readonly
     * @var \Rector\Naming\Matcher\VariableAndCallAssignMatcher
     */
    private $variableAndCallAssignMatcher;
    /**
     * @readonly
     * @var \Rector\Naming\VariableRenamer
     */
    private $variableRenamer;
    /**
     * @readonly
     * @var \Rector\PHPStanStaticTypeMapper\Utils\TypeUnwrapper
     */
    private $typeUnwrapper;
    /**
     * @readonly
     * @var \PHPStan\Reflection\ReflectionProvider
     */
    private $reflectionProvider;
    /**
     * @readonly
     * @var \Rector\FamilyTree\Reflection\FamilyRelationsAnalyzer
     */
    private $familyRelationsAnalyzer;
    public function __construct(\Rector\Naming\Guard\BreakingVariableRenameGuard $breakingVariableRenameGuard, \Rector\Naming\Naming\ExpectedNameResolver $expectedNameResolver, \Rector\Naming\NamingConvention\NamingConventionAnalyzer $namingConventionAnalyzer, \Rector\Naming\PhpDoc\VarTagValueNodeRenamer $varTagValueNodeRenamer, \Rector\Naming\Matcher\VariableAndCallAssignMatcher $variableAndCallAssignMatcher, \Rector\Naming\VariableRenamer $variableRenamer, \Rector\PHPStanStaticTypeMapper\Utils\TypeUnwrapper $typeUnwrapper, \PHPStan\Reflection\ReflectionProvider $reflectionProvider, \Rector\FamilyTree\Reflection\FamilyRelationsAnalyzer $familyRelationsAnalyzer)
    {
        $this->breakingVariableRenameGuard = $breakingVariableRenameGuard;
        $this->expectedNameResolver = $expectedNameResolver;
        $this->namingConventionAnalyzer = $namingConventionAnalyzer;
        $this->varTagValueNodeRenamer = $varTagValueNodeRenamer;
        $this->variableAndCallAssignMatcher = $variableAndCallAssignMatcher;
        $this->variableRenamer = $variableRenamer;
        $this->typeUnwrapper = $typeUnwrapper;
        $this->reflectionProvider = $reflectionProvider;
        $this->familyRelationsAnalyzer = $familyRelationsAnalyzer;
    }
    public function getRuleDefinition() : \Symplify\RuleDocGenerator\ValueObject\RuleDefinition
    {
        return new \Symplify\RuleDocGenerator\ValueObject\RuleDefinition('Rename variable to match method return type', [new \Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample(<<<'CODE_SAMPLE'
class SomeClass
{
public function run()
{
    $a = $this->getRunner();
}

public function getRunner(): Runner
{
    return new Runner();
}
}
CODE_SAMPLE
, <<<'CODE_SAMPLE'
class SomeClass
{
public function run()
{
    $runner = $this->getRunner();
}

public function getRunner(): Runner
{
    return new Runner();
}
}
CODE_SAMPLE
)]);
    }
    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes() : array
    {
        return [\PhpParser\Node\Expr\Assign::class];
    }
    /**
     * @param Assign $node
     */
    public function refactor(\PhpParser\Node $node) : ?\PhpParser\Node
    {
        $variableAndCallAssign = $this->variableAndCallAssignMatcher->match($node);
        if (!$variableAndCallAssign instanceof \Rector\Naming\ValueObject\VariableAndCallAssign) {
            return null;
        }
        $call = $variableAndCallAssign->getCall();
        if ($this->isMultipleCall($call)) {
            return null;
        }
        $expectedName = $this->expectedNameResolver->resolveForCall($call);
        if ($expectedName === null) {
            return null;
        }
        if ($this->isName($node->var, $expectedName)) {
            return null;
        }
        if ($this->shouldSkip($variableAndCallAssign, $expectedName)) {
            return null;
        }
        $this->renameVariable($variableAndCallAssign, $expectedName);
        return $node;
    }
    /**
     * @param \PhpParser\Node\Expr\FuncCall|\PhpParser\Node\Expr\MethodCall|\PhpParser\Node\Expr\StaticCall $callNode
     */
    private function isMultipleCall($callNode) : bool
    {
        $parentNode = $callNode->getAttribute(\Rector\NodeTypeResolver\Node\AttributeKey::PARENT_NODE);
        $callNodeClass = \get_class($callNode);
        while ($parentNode instanceof \PhpParser\Node) {
            $usedNodes = $this->betterNodeFinder->find($parentNode, function (\PhpParser\Node $node) use($callNodeClass, $callNode) : bool {
                $nodeClass = \get_class($node);
                if ($callNodeClass !== $nodeClass) {
                    return \false;
                }
                /** @var FuncCall|StaticCall|MethodCall $node */
                $passedNode = clone $node;
                /** @var FuncCall|StaticCall|MethodCall $callNode */
                $usedNode = clone $callNode;
                /** @var FuncCall|StaticCall|MethodCall $passedNode */
                $passedNode->args = [];
                /** @var FuncCall|StaticCall|MethodCall $usedNode */
                $usedNode->args = [];
                return $this->nodeComparator->areNodesEqual($passedNode, $usedNode);
            });
            if (\count($usedNodes) > 1) {
                return \true;
            }
            $parentNode = $parentNode->getAttribute(\Rector\NodeTypeResolver\Node\AttributeKey::PARENT_NODE);
        }
        return \false;
    }
    private function shouldSkip(\Rector\Naming\ValueObject\VariableAndCallAssign $variableAndCallAssign, string $expectedName) : bool
    {
        if ($this->namingConventionAnalyzer->isCallMatchingVariableName($variableAndCallAssign->getCall(), $variableAndCallAssign->getVariableName(), $expectedName)) {
            return \true;
        }
        if ($this->isClassTypeWithChildren($variableAndCallAssign->getCall())) {
            return \true;
        }
        return $this->breakingVariableRenameGuard->shouldSkipVariable($variableAndCallAssign->getVariableName(), $expectedName, $variableAndCallAssign->getFunctionLike(), $variableAndCallAssign->getVariable());
    }
    private function renameVariable(\Rector\Naming\ValueObject\VariableAndCallAssign $variableAndCallAssign, string $expectedName) : void
    {
        $assign = $variableAndCallAssign->getAssign();
        $assignPhpDocInfo = $this->phpDocInfoFactory->createFromNodeOrEmpty($assign);
        $this->varTagValueNodeRenamer->renameAssignVarTagVariableName($assignPhpDocInfo, $variableAndCallAssign->getVariableName(), $expectedName);
        $this->variableRenamer->renameVariableInFunctionLike($variableAndCallAssign->getFunctionLike(), $variableAndCallAssign->getVariableName(), $expectedName, $variableAndCallAssign->getAssign());
    }
    /**
     * @param \PhpParser\Node\Expr\FuncCall|\PhpParser\Node\Expr\MethodCall|\PhpParser\Node\Expr\StaticCall $expr
     */
    private function isClassTypeWithChildren($expr) : bool
    {
        $callStaticType = $this->getType($expr);
        $callStaticType = $this->typeUnwrapper->unwrapNullableType($callStaticType);
        if (!$callStaticType instanceof \PHPStan\Type\ObjectType) {
            return \false;
        }
        if (\is_a($callStaticType->getClassName(), \PhpParser\Node\Stmt\ClassLike::class, \true)) {
            return \false;
        }
        if (!$this->reflectionProvider->hasClass($callStaticType->getClassName())) {
            return \false;
        }
        $classReflection = $this->reflectionProvider->getClass($callStaticType->getClassName());
        $childrenClassReflections = $this->familyRelationsAnalyzer->getChildrenOfClassReflection($classReflection);
        return $childrenClassReflections !== [];
    }
}
