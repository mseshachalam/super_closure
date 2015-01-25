<?php namespace SuperClosure;

use SuperClosure\Analyzer\AstAnalyzer as DefaultAnalyzer;
use SuperClosure\Analyzer\ClosureAnalyzer;

/**
 * Serializer is used to serialize Closure objects, abstracting away all the
 * details, impossibilities, and scary things that happen within.
 */
class Serializer implements SerializerInterface
{
    /** @var string Special value marking a recursive reference to a closure. */
    const RECURSION = "{{RECURSION}}";

    /** @var array Keys of closure data required for serialization. */
    private static $dataToKeep = [
        'code'    => true,
        'context' => true,
        'binding' => true,
        'scope'   => true
    ];

    /** @var ClosureAnalyzer */
    private $analyzer;

    /**
     * @param ClosureAnalyzer $analyzer
     */
    public function __construct(ClosureAnalyzer $analyzer = null)
    {
        $this->analyzer = $analyzer ?: new DefaultAnalyzer;
    }

    /**
     * @inheritDoc
     */
    public function serialize(\Closure $closure)
    {
        return serialize(new SerializableClosure($closure, $this));
    }

    /**
     * @inheritDoc
     */
    public function unserialize($serialized)
    {
        /** @var SerializableClosure $unserialized */
        $unserialized = unserialize($serialized);

        return $unserialized->getClosure();
    }

    /**
     * @inheritDoc
     */
    public function getData(\Closure $closure, $forSerialization = false)
    {
        // Use the closure analyzer to get data about the closure.
        $data = $this->analyzer->analyze($closure);

        // If the closure data is getting retrieved solely for the purpose of
        // serializing the closure, then make some modifications to the data.
        if ($forSerialization) {
            // If there is no reference to the binding, don't serialize it.
            if (!$data['hasThis']) {
                $data['binding'] = null;
            }

            // Remove data about the closure that does not get serialized.
            $data = array_intersect_key($data, self::$dataToKeep);

            // Wrap any other closures within the context.
            foreach ($data['context'] as &$value) {
                if ($value instanceof \Closure) {
                    $value = ($value === $closure)
                        ? self::RECURSION
                        : new SerializableClosure($value, $this);
                }
            }
        }

        return $data;
    }

    /**
     * Recursively traverses and wraps all Closure objects within the value.
     *
     * NOTE: THIS METHOD MAY NOT WORK IN ALL SITUATIONS, SO BE CAREFUL.
     *
     * @param mixed $data Any variable that contains closures.
     */
    public static function wrapClosures(&$data, SerializerInterface $serializer)
    {
        if ($data instanceof \Closure) {
            $reflection = new \ReflectionFunction($data);
            if ($binding = $reflection->getClosureThis()) {
                self::wrapClosures($binding, $serializer);
                $scope = $reflection->getClosureScopeClass();
                $scope = $scope ? $scope->getName() : 'static';
                $data = $data->bindTo($binding, $scope);
            }
            $data = new SerializableClosure($data, $serializer);
        } elseif (is_array($data) || $data instanceof \stdClass || $data instanceof \Traversable) {
            foreach ($data as &$value) {
                self::wrapClosures($value, $serializer);
            }
        } elseif (is_object($data) && !$data instanceof \Serializable) {
            $reflection = new \ReflectionObject($data);
            if (!$reflection->hasMethod('__sleep')) {
                foreach ($reflection->getProperties() as $property) {
                    if ($property->isPrivate() || $property->isProtected()) {
                        $property->setAccessible(true);
                    }
                    $value = $property->getValue($data);
                    self::wrapClosures($value, $serializer);
                    $property->setValue($data, $value);
                }
            }
        }
    }
}
