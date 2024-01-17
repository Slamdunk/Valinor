<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Tests\Integration\Normalizer;

use Attribute;
use CuyZ\Valinor\MapperBuilder;
use CuyZ\Valinor\Normalizer\Exception\CircularReferenceFoundDuringNormalization;
use CuyZ\Valinor\Normalizer\Exception\KeyTransformerHasTooManyParameters;
use CuyZ\Valinor\Normalizer\Exception\KeyTransformerParameterInvalidType;
use CuyZ\Valinor\Normalizer\Exception\TransformerHasInvalidCallableParameter;
use CuyZ\Valinor\Normalizer\Exception\TransformerHasNoParameter;
use CuyZ\Valinor\Normalizer\Exception\TransformerHasTooManyParameters;
use CuyZ\Valinor\Normalizer\Exception\TypeUnhandledByNormalizer;
use CuyZ\Valinor\Normalizer\Format;
use CuyZ\Valinor\Tests\Fixture\Enum\BackedIntegerEnum;
use CuyZ\Valinor\Tests\Fixture\Enum\BackedStringEnum;
use CuyZ\Valinor\Tests\Fixture\Enum\PureEnum;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use IteratorAggregate;
use PHPUnit\Framework\TestCase;
use stdClass;
use Traversable;

use function array_merge;

final class NormalizerTest extends TestCase
{
    /**
     * @dataProvider normalize_basic_values_yields_expected_output_data_provider
     *
     * @param array<int, list<callable>> $transformers
     * @param list<class-string> $transformerAttributes
     */
    public function test_normalize_basic_values_yields_expected_output(
        mixed $input,
        mixed $expected,
        array $transformers = [],
        array $transformerAttributes = [],
    ): void {
        $builder = new MapperBuilder();

        foreach ($transformers as $priority => $transformersList) {
            foreach ($transformersList as $transformer) {
                $builder = $builder->registerTransformer($transformer, $priority);
            }
        }

        foreach ($transformerAttributes as $transformerAttribute) {
            $builder = $builder->registerTransformer($transformerAttribute);
        }

        $result = $builder->normalizer(Format::array())->normalize($input);

        self::assertSame($expected, $result);
    }

    public function normalize_basic_values_yields_expected_output_data_provider(): iterable
    {
        yield 'null' => [
            'input' => null,
            'expected' => null,
        ];

        yield 'string' => [
            'input' => 'foo bar',
            'expected' => 'foo bar',
        ];

        yield 'string with transformer' => [
            'input' => 'foo',
            'expected' => 'foo!',
            'transformers' => [
                [fn (string $value) => $value . '!'],
            ],
        ];

        yield 'integer' => [
            'input' => 42,
            'expected' => 42,
        ];

        yield 'integer with transformer' => [
            'input' => 42,
            'expected' => 43,
            'transformers' => [
                [fn (int $value) => $value + 1],
            ],
        ];

        yield 'integer with negative-int transformer' => [
            'input' => 42,
            'expected' => 42,
            'transformers' => [
                [
                    /** @param negative-int $value */
                    fn (int $value) => $value + 1,
                ],
            ],
        ];

        yield 'float' => [
            'input' => 1337.404,
            'expected' => 1337.404,
        ];

        yield 'float with transformer' => [
            'input' => 1337.404,
            'expected' => 1337.405,
            'transformers' => [
                [fn (float $value) => $value + 0.001],
            ],
        ];

        yield 'boolean' => [
            'input' => true,
            'expected' => true,
        ];

        yield 'array of scalar' => [
            'input' => [
                'string' => 'foo',
                'integer' => 42,
                'float' => 1337.404,
                'boolean' => true,
            ],
            'expected' => [
                'string' => 'foo',
                'integer' => 42,
                'float' => 1337.404,
                'boolean' => true,
            ],
        ];

        yield 'array with transformer' => [
            'input' => ['foo'],
            'expected' => ['foo', 'bar'],
            'transformers' => [
                [fn (array $value) => array_merge($value, ['bar'])],
            ],
        ];

        yield 'iterable of scalar' => [
            'input' => (function (): iterable {
                yield 'string' => 'foo';
                yield 'integer' => 42;
                yield 'float' => 1337.404;
                yield 'boolean' => true;
            })(),
            'expected' => [
                'string' => 'foo',
                'integer' => 42,
                'float' => 1337.404,
                'boolean' => true,
            ],
        ];

        yield 'nested iterable of scalar' => [
            'input' => (function (): iterable {
                yield 'strings' => (function (): iterable {
                    yield 'foo';
                    yield 'bar';
                })();
                yield 'integers' => (function (): iterable {
                    yield 42;
                    yield 1337;
                })();
                yield 'floats' => (function (): iterable {
                    yield 42.5;
                    yield 1337.404;
                })();
                yield 'booleans' => (function (): iterable {
                    yield true;
                    yield false;
                })();
            })(),
            'expected' => [
                'strings' => ['foo', 'bar'],
                'integers' => [42, 1337],
                'floats' => [42.5, 1337.404],
                'booleans' => [true, false],
            ],
        ];

        yield 'stdClass' => [
            'input' => (function () {
                $object = new stdClass();
                $object->foo = 'foo';
                $object->bar = 'bar';

                return $object;
            })(),
            'expected' => [
                'foo' => 'foo',
                'bar' => 'bar',
            ],
        ];

        yield 'array of object' => [
            'input' => [
                'foo' => new BasicObject('foo'),
                'bar' => new BasicObject('bar'),
            ],
            'expected' => [
                'foo' => ['value' => 'foo'],
                'bar' => ['value' => 'bar'],
            ],
        ];

        if (PHP_VERSION_ID >= 8_01_00) {
            yield 'unit enum' => [
                'input' => PureEnum::FOO,
                'expected' => 'FOO',
            ];

            yield 'backed string enum' => [
                'input' => BackedStringEnum::FOO,
                'expected' => 'foo',
            ];

            yield 'backed integer enum' => [
                'input' => BackedIntegerEnum::FOO,
                'expected' => 42,
            ];
        }

        yield 'class with public properties' => [
            'input' => new class () {
                public string $string = 'foo';
                public int $integer = 42;
                public float $float = 1337.404;
                public bool $boolean = true;
            },
            'output' => [
                'string' => 'foo',
                'integer' => 42,
                'float' => 1337.404,
                'boolean' => true,
            ],
        ];

        yield 'class with protected properties' => [
            'input' => new class () {
                protected string $string = 'foo';
                protected int $integer = 42;
                protected float $float = 1337.404;
                protected bool $boolean = true;
            },
            'output' => [
                'string' => 'foo',
                'integer' => 42,
                'float' => 1337.404,
                'boolean' => true,
            ],
        ];

        yield 'class with private properties' => [
            'input' => new class () {
                private string $string = 'foo'; // @phpstan-ignore-line
                private int $integer = 42; // @phpstan-ignore-line
                private float $float = 1337.404; // @phpstan-ignore-line
                private bool $boolean = true; // @phpstan-ignore-line
            },
            'output' => [
                'string' => 'foo',
                'integer' => 42,
                'float' => 1337.404,
                'boolean' => true,
            ],
        ];

        yield 'class with inherited properties' => [
            'input' => new SomeChildClass(),
            'output' => [
                'stringFromGrandParentClass' => 'foo',
                'stringFromParentClass' => 'bar',
                'stringFromChildClass' => 'baz',
            ],
        ];

        yield 'iterable class' => [
            'input' => new class () implements IteratorAggregate {
                public string $foo = 'foo';
                public string $bar = 'bar';

                public function getIterator(): Traversable
                {
                    yield 'baz' => 'baz';
                }
            },
            'output' => [
                'foo' => 'foo',
                'bar' => 'bar',
            ],
        ];

        yield 'date with default transformer' => [
            'input' => new DateTimeImmutable('1971-11-08'),
            'expected' => '1971-11-08T00:00:00.000000+00:00',
        ];

        yield 'date with transformer' => [
            'input' => new DateTimeImmutable('1971-11-08'),
            'expected' => '1971-11-08',
            'transformers' => [
                [fn (DateTimeInterface $object) => $object->format('Y-m-d')],
            ],
        ];

        yield 'time zone with default transformer' => [
            'input' => new DateTimeZone('Europe/Paris'),
            'expected array' => 'Europe/Paris',
        ];

        yield 'time zone with transformer' => [
            'input' => new DateTimeZone('Europe/Paris'),
            'expected array' => [
                'name' => 'Europe/Paris',
                'country_code' => 'FR',
            ],
            'transformers' => [
                [fn (DateTimeZone $object) => [
                    'name' => $object->getName(),
                    'country_code' => $object->getLocation()['country_code'] ?? 'Unknown',
                ]],
            ],
        ];

        yield 'object with transformer' => [
            'input' => new BasicObject('foo'),
            'expected' => 'foo!',
            'transformers' => [
                [fn (BasicObject $object) => $object->value . '!'],
            ],
        ];

        yield 'object with undefined object transformer' => [
            'input' => new BasicObject('foo'),
            'expected' => 'foo!',
            'transformers' => [
                [fn (object $object) => $object->value . '!'], // @phpstan-ignore-line
            ],
        ];

        yield 'iterable class with transformer' => [
            'input' => new class () implements IteratorAggregate {
                public string $foo = 'foo';
                public string $bar = 'bar';

                public function getIterator(): Traversable
                {
                    yield 'baz' => 'baz';
                }
            },
            'output' => 'value',
            'transformers' => [
                [fn (object $object) => 'value'],
            ],
        ];

        yield 'object with union object transformer' => [
            'input' => new BasicObject('foo'),
            'expected' => 'foo!',
            'transformers' => [
                [fn (stdClass|BasicObject $object) => $object->value . '!'],
            ],
        ];

        yield 'object with transformer calling next' => [
            'input' => new BasicObject('foo'),
            'expected' => [
                'value' => 'foo',
                'bar' => 'bar',
            ],
            'transformers' => [
                [
                    function (object $object, callable $next) {
                        $result = $next();
                        $result['bar'] = 'bar';

                        return $result;
                    },
                ],
            ],
        ];

        yield 'object with several prioritized transformers' => [
            'input' => new BasicObject('foo'),
            'expected' => 'foo*!?',
            'transformers' => [
                -20 => [fn (BasicObject $object, callable $next) => $object->value],
                -15 => [fn (stdClass $object) => 'bar'], // Should be ignored by the normalizer
                -10 => [fn (BasicObject $object, callable $next) => $next() . '*'],
                0 => [fn (BasicObject $object, callable $next) => $next() . '!'],
                10 => [fn (stdClass $object) => 'baz'], // Should be ignored by the normalizer
                20 => [fn (BasicObject $object, callable $next) => $next() . '?'],
            ],
        ];

        yield 'object with several prioritized transformers with same priority' => [
            'input' => new BasicObject('foo'),
            'expected' => 'foo?!*',
            'transformers' => [
                10 => [
                    fn (BasicObject $object, callable $next) => $next() . '*',
                    fn (BasicObject $object, callable $next) => $next() . '!',
                    fn (BasicObject $object, callable $next) => $object->value . '?',
                ],
            ],
        ];

        yield 'stdClass with transformers' => [
            'input' => (function () {
                $class = new stdClass();
                $class->foo = 'foo';
                $class->bar = 'bar';

                return $class;
            })(),
            'expected' => ['foo' => 'foo!', 'bar' => 'bar!'],
            'transformers' => [
                [
                    fn (string $value, callable $next) => $next() . '!',
                ],
            ],
        ];

        yield 'object with attribute on property with matching transformer' => [
            'input' => new SomeClassWithAttributeOnProperty('foo'),
            'expected' => ['value' => 'prefix_foo'],
            'transformers' => [],
            'transformerAttributes' => [AddPrefixToPropertyAttribute::class],
        ];

        yield 'object with two attributes on property with matching transformers' => [
            'input' => new SomeClassWithTwoAttributesOnProperty('foo'),
            'expected' => ['value' => 'prefix_foo_suffix'],
            'transformers' => [],
            'transformerAttributes' => [
                AddPrefixToPropertyAttribute::class,
                AddSuffixToPropertyAttribute::class,
            ],
        ];

        yield 'object with attribute on property with matching transformer from attribute interface' => [
            'input' => new SomeClassWithAttributeOnProperty('foo'),
            'expected' => ['value' => 'prefix_foo'],
            'transformers' => [],
            'transformerAttributes' => [SomePropertyAttributeInterface::class],
        ];

        yield 'object with attribute on class with matching transformer' => [
            'input' => new SomeClassWithAttributeOnClass('foo'),
            'expected' => ['prefix_from_class_value' => 'foo'],
            'transformers' => [],
            'transformerAttributes' => [AddPrefixToClassPropertiesAttribute::class],
        ];

        yield 'object with two attributes on class with matching transformers' => [
            'input' => new SomeClassWithTwoAttributesOnClass('foo'),
            'expected' => ['prefix1_prefix2_value' => 'foo'],
            'transformers' => [],
            'transformerAttributes' => [AddPrefixToClassPropertiesAttribute::class],
        ];

        yield 'object with attribute on class with matching transformer from attribute interface' => [
            'input' => new SomeClassWithAttributeOnClass('foo'),
            'expected' => ['prefix_from_class_value' => 'foo'],
            'transformers' => [],
            'transformerAttributes' => [SomeClassAttributeInterface::class],
        ];

        yield 'object with attribute on property *and* on class with matching transformer' => [
            'input' => new SomeClassWithAttributeOnPropertyAndOnClass(new SomeClassWithAttributeOnClass('foo')),
            'expected' => ['value' => ['prefix_from_property_prefix_from_class_value' => 'foo']],
            'transformers' => [],
            'transformerAttributes' => [AddPrefixToClassPropertiesAttribute::class],
        ];

        yield 'object with attribute on class to transform object to string' => [
            'input' => new SomeClassWithAttributeToTransformObjectToString(),
            'expected' => 'foo',
            'transformers' => [],
            'transformerAttributes' => [TransformObjectToString::class],
        ];

        yield 'object with attributes and custom transformers' => [
            'input' => new SomeClassWithTwoAttributesOnProperty('foo'),
            'expected' => ['value' => 'prefix_foobazbar_suffix'],
            'transformers' => [
                [
                    fn (string $value, callable $next) => $next() . 'bar',
                    fn (string $value, callable $next) => $next() . 'baz',
                ],
            ],
            'transformerAttributes' => [
                AddPrefixToPropertyAttribute::class,
                AddSuffixToPropertyAttribute::class,
            ],
        ];

        yield 'object with attribute registered both by attribute name and interface name' => [
            'input' => new class () {
                public function __construct(
                    #[AddPrefixToPropertyAttribute('prefix_')]
                    public string $value = 'value',
                ) {}
            },
            'expected' => ['value' => 'prefix_value'],
            'transformers' => [],
            'transformerAttributes' => [
                AddPrefixToPropertyAttribute::class,
                SomePropertyAttributeInterface::class,
            ],
        ];

        yield 'object with key transformer attributes on property' => [
            'input' => new class () {
                public function __construct(
                    #[RenamePropertyKey('renamed')]
                    #[AddPrefixToPropertyKey('prefix_')]
                    public string $value = 'value',
                ) {}
            },
            'expected' => ['prefix_renamed' => 'value'],
            'transformers' => [],
            'transformerAttributes' => [
                RenamePropertyKey::class,
                AddPrefixToPropertyKey::class,
            ],
        ];

        yield 'object with key transformer attributes on property are called in order' => [
            'input' => new class () {
                public function __construct(
                    #[AddPrefixToPropertyKey('prefix1_')]
                    #[AddPrefixToPropertyKeyBis('prefix2_')]
                    public string $value = 'value',
                ) {}
            },
            'expected' => ['prefix2_prefix1_value' => 'value'],
            'transformers' => [],
            'transformerAttributes' => [
                AddPrefixToPropertyKeyBis::class,
                AddPrefixToPropertyKey::class,
            ],
        ];

        yield 'object with key transformer attribute on property with matching attribute interface' => [
            'input' => new class () {
                public function __construct(
                    #[RenamePropertyKey('renamed')]
                    public string $value = 'value',
                ) {}
            },
            'expected' => ['renamed' => 'value'],
            'transformers' => [],
            'transformerAttributes' => [SomeKeyTransformerInterface::class],
        ];

        yield 'object with *registered* key transformer attribute and *unregistered* key transformer attribute on property' => [
            'input' => new class () {
                public function __construct(
                    #[AddPrefixToPropertyKey('prefix1_')]
                    #[AddPrefixToPropertyKeyBis('prefix2_')]
                    public string $value = 'value',
                ) {}
            },
            'expected' => ['prefix2_value' => 'value'],
            'transformers' => [],
            'transformerAttributes' => [
                AddPrefixToPropertyKeyBis::class,
            ],
        ];

        yield 'object with key transformer attributes *and* transformer attribute on property' => [
            'input' => new class () {
                public function __construct(
                    #[RenamePropertyKey('renamed')]
                    #[AddPrefixToPropertyAttribute('prefix_')]
                    #[AddPrefixToPropertyKey('prefix_')]
                    public string $value = 'value',
                ) {}
            },
            'expected' => ['prefix_renamed' => 'prefix_value'],
            'transformers' => [],
            'transformerAttributes' => [
                RenamePropertyKey::class,
                AddPrefixToPropertyKey::class,
                AddPrefixToPropertyAttribute::class,
            ],
        ];

        yield 'object with key transformer attribute registered both by attribute name and interface name' => [
            'input' => new class () {
                public function __construct(
                    #[RenamePropertyKey('renamed')]
                    public string $value = 'value',
                ) {}
            },
            'expected' => ['renamed' => 'value'],
            'transformers' => [],
            'transformerAttributes' => [
                RenamePropertyKey::class,
                SomeKeyTransformerInterface::class,
            ],
        ];
    }

    public function test_transformer_is_called_only_once_on_object_property_when_using_default_transformer(): void
    {
        $result = (new MapperBuilder())
            ->registerTransformer(
                fn (string $value, callable $next) => $next() . '!',
            )
            ->normalizer(Format::array())
            ->normalize(new BasicObject('foo'));

        self::assertSame(['value' => 'foo!'], $result);
    }

    public function test_no_priority_given_is_set_to_0(): void
    {
        $result = (new MapperBuilder())
            ->registerTransformer(
                fn (object $object) => 'foo',
                -2,
            )
            ->registerTransformer(
                fn (object $object, callable $next) => $next() . '!',
                -1,
            )
            ->registerTransformer(
                fn (object $object, callable $next) => $next() . '?',
            )
            ->registerTransformer(
                fn (object $object, callable $next) => $next() . '*',
                1,
            )
            ->normalizer(Format::array())
            ->normalize(new stdClass());

        self::assertSame('foo!?*', $result);
    }

    public function test_no_param_in_transformer_throws_exception(): void
    {
        $this->expectException(TransformerHasNoParameter::class);
        $this->expectExceptionCode(1695064946);
        $this->expectExceptionMessageMatches('/Transformer must have at least one parameter, none given for `.*`\./');

        (new MapperBuilder())
            ->registerTransformer(fn () => 42)
            ->normalizer(Format::array())
            ->normalize(new stdClass());
    }

    public function test_too_many_params_in_transformer_throws_exception(): void
    {
        $this->expectException(TransformerHasTooManyParameters::class);
        $this->expectExceptionCode(1695065433);
        $this->expectExceptionMessageMatches('/Transformer must have at most 2 parameters, 3 given for `.*`\./');

        (new MapperBuilder())
            ->registerTransformer(fn (stdClass $object, callable $next, int $unexpectedParameter) => 42)
            ->normalizer(Format::array())
            ->normalize(new stdClass());
    }

    public function test_second_param_in_transformer_is_not_callable_throws_exception(): void
    {
        $this->expectException(TransformerHasInvalidCallableParameter::class);
        $this->expectExceptionCode(1695065710);
        $this->expectExceptionMessageMatches('/Transformer\'s second parameter must be a callable, `int` given for `.*`\./');

        (new MapperBuilder())
            ->registerTransformer(fn (stdClass $object, int $unexpectedParameterType) => 42)
            ->normalizer(Format::array())
            ->normalize(new stdClass());
    }

    public function test_no_param_in_transformer_attribute_throws_exception(): void
    {
        $this->expectException(TransformerHasNoParameter::class);
        $this->expectExceptionCode(1695064946);
        $this->expectExceptionMessageMatches('/Transformer must have at least one parameter, none given for `.*`./');

        $class = new #[TransformerAttributeWithNoParameter] class () {};

        (new MapperBuilder())
            ->registerTransformer(TransformerAttributeWithNoParameter::class)
            ->normalizer(Format::array())
            ->normalize($class);
    }

    public function test_too_many_params_in_transformer_attribute_throws_exception(): void
    {
        $this->expectException(TransformerHasTooManyParameters::class);
        $this->expectExceptionCode(1695065433);
        $this->expectExceptionMessageMatches('/Transformer must have at most 2 parameters, 3 given for `.*`./');

        $class = new #[TransformerAttributeWithTooManyParameters] class () {};

        (new MapperBuilder())
            ->registerTransformer(TransformerAttributeWithTooManyParameters::class)
            ->normalizer(Format::array())
            ->normalize($class);
    }

    public function test_second_param_in_transformer_attribute_is_not_callable_throws_exception(): void
    {
        $this->expectException(TransformerHasInvalidCallableParameter::class);
        $this->expectExceptionCode(1695065710);
        $this->expectExceptionMessageMatches('/Transformer\'s second parameter must be a callable, `int` given for `.*`./');

        $class = new #[TransformerAttributeWithSecondParameterNotCallable] class () {};

        (new MapperBuilder())
            ->registerTransformer(TransformerAttributeWithSecondParameterNotCallable::class)
            ->normalizer(Format::array())
            ->normalize($class);
    }

    public function test_too_many_params_in_key_transformer_attribute_throws_exception(): void
    {
        $this->expectException(KeyTransformerHasTooManyParameters::class);
        $this->expectExceptionCode(1701701102);
        $this->expectExceptionMessageMatches('/Key transformer must have at most 1 parameter, 2 given for `.*`./');

        $class = new class () {
            public function __construct(
                #[KeyTransformerAttributeWithTooManyParameters]
                public string $value = 'value',
            ) {}
        };

        (new MapperBuilder())
            ->registerTransformer(KeyTransformerAttributeWithTooManyParameters::class)
            ->normalizer(Format::array())
            ->normalize($class);
    }

    public function test_invalid_param_type_in_key_transformer_attribute_throws_exception(): void
    {
        $this->expectException(KeyTransformerParameterInvalidType::class);
        $this->expectExceptionCode(1701706316);
        $this->expectExceptionMessageMatches('/Key transformer parameter must be a string, stdClass given for `.*`./');

        $class = new class () {
            public function __construct(
                #[KeyTransformerAttributeParameterNotStringOrInteger]
                public string $value = 'value',
            ) {}
        };

        (new MapperBuilder())
            ->registerTransformer(KeyTransformerAttributeParameterNotStringOrInteger::class)
            ->normalizer(Format::array())
            ->normalize($class);
    }

    public function test_object_circular_reference_is_detected_and_throws_exception(): void
    {
        $this->expectException(CircularReferenceFoundDuringNormalization::class);
        $this->expectExceptionCode(1695064016);
        $this->expectExceptionMessage('A circular reference was detected with an object of type `' . ObjectWithCircularReferenceA::class . '`. Circular references are not supported by the normalizer');

        $a = new ObjectWithCircularReferenceA();
        $b = new ObjectWithCircularReferenceB();
        $a->b = $b;
        $b->a = $a;

        (new MapperBuilder())->normalizer(Format::array())->normalize($a);
    }

    public function test_unhandled_type_throws_exception(): void
    {
        $this->expectException(TypeUnhandledByNormalizer::class);
        $this->expectExceptionCode(1695062925);
        $this->expectExceptionMessage('Value of type `Closure` cannot be normalized.');

        (new MapperBuilder())->normalizer(Format::array())->normalize(fn () => 42);
    }
}

final class BasicObject
{
    public function __construct(public string $value) {}
}

class SomeGrandParentClass
{
    public string $stringFromGrandParentClass = 'foo';
}

class SomeParentClass extends SomeGrandParentClass
{
    public string $stringFromParentClass = 'bar';
}

final class SomeChildClass extends SomeParentClass
{
    public string $stringFromChildClass = 'baz';
}

final class ObjectWithCircularReferenceA
{
    public ObjectWithCircularReferenceB $b;
}

final class ObjectWithCircularReferenceB
{
    public ObjectWithCircularReferenceA $a;
}

#[Attribute]
final class NonTransformerAttribute {}

#[Attribute]
final class TransformerAttributeWithNoParameter
{
    public function normalize(): void {}
}

#[Attribute]
final class TransformerAttributeWithTooManyParameters
{
    public function normalize(stdClass $object, callable $next, int $unexpectedParameter): void {}
}

#[Attribute]
final class TransformerAttributeWithSecondParameterNotCallable
{
    public function normalize(stdClass $object, int $unexpectedParameterType): void {}
}

#[Attribute]
final class KeyTransformerAttributeWithTooManyParameters
{
    public function normalizeKey(string $key, int $unexpectedParameter): void {}
}

#[Attribute]
final class KeyTransformerAttributeParameterNotStringOrInteger
{
    public function normalizeKey(stdClass $unexpectedParameterType): void {}
}

interface SomePropertyAttributeInterface
{
    public function normalize(string $value, callable $next): string;
}

interface SomeClassAttributeInterface
{
    /**
     * @return array<mixed>
     */
    public function normalize(object $value, callable $next): array;
}

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final class AddPrefixToPropertyAttribute implements SomePropertyAttributeInterface
{
    public function __construct(private string $prefix) {}

    public function normalize(string $value, callable $next): string
    {
        return $this->prefix . $next();
    }
}

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final class AddSuffixToPropertyAttribute implements SomePropertyAttributeInterface
{
    public function __construct(private string $suffix) {}

    public function normalize(string $value, callable $next): string
    {
        return $next() . $this->suffix;
    }
}

interface SomeKeyTransformerInterface
{
    public function normalizeKey(): string;
}

#[Attribute(Attribute::TARGET_PROPERTY)]
final class RenamePropertyKey implements SomeKeyTransformerInterface
{
    public function __construct(private string $value) {}

    public function normalizeKey(): string
    {
        return $this->value;
    }
}

#[Attribute(Attribute::TARGET_PROPERTY)]
final class AddPrefixToPropertyKey
{
    public function __construct(private string $prefix) {}

    public function normalizeKey(string $key): string
    {
        return $this->prefix . $key;
    }
}

#[Attribute(Attribute::TARGET_PROPERTY)]
final class AddPrefixToPropertyKeyBis
{
    public function __construct(private string $prefix) {}

    public function normalizeKey(string $key): string
    {
        return $this->prefix . $key;
    }
}

final class SomeClassWithAttributeOnProperty
{
    public function __construct(
        #[AddPrefixToPropertyAttribute('prefix_')]
        public string $value = 'value',
    ) {}
}

final class SomeClassWithTwoAttributesOnProperty
{
    public function __construct(
        #[AddPrefixToPropertyAttribute('prefix_')]
        #[AddSuffixToPropertyAttribute('_suffix')]
        public string $value = 'value',
    ) {}
}

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final class AddPrefixToClassPropertiesAttribute implements SomeClassAttributeInterface
{
    public function __construct(private string $prefix) {}

    public function normalize(object $value, callable $next): array
    {
        $prefixed = [];

        foreach ($next() as $key => $subValue) {
            $prefixed[$this->prefix . $key] = $subValue;
        }

        return $prefixed;
    }
}

#[Attribute(Attribute::TARGET_CLASS)]
final class TransformObjectToString
{
    public function normalize(object $object): string
    {
        return 'foo';
    }
}

#[AddPrefixToClassPropertiesAttribute('prefix_from_class_')]
final class SomeClassWithAttributeOnClass
{
    public function __construct(
        public string $value = 'value',
    ) {}
}

#[AddPrefixToClassPropertiesAttribute('prefix1_')]
#[AddPrefixToClassPropertiesAttribute('prefix2_')]
final class SomeClassWithTwoAttributesOnClass
{
    public function __construct(
        public string $value = 'value',
    ) {}
}

final class SomeClassWithAttributeOnPropertyAndOnClass
{
    public function __construct(
        #[AddPrefixToClassPropertiesAttribute('prefix_from_property_')]
        public SomeClassWithAttributeOnClass $value,
    ) {}
}

#[TransformObjectToString]
final class SomeClassWithAttributeToTransformObjectToString {}
