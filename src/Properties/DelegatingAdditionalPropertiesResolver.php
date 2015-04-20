<?php

namespace SimpleBus\Asynchronous\Properties;

use SimpleBus\Message\Message;

class DelegatingAdditionalPropertiesResolver implements AdditionalPropertiesResolver
{
    /**
     * @var AdditionalPropertiesResolver[]
     */
    private $resolvers;

    public function __construct($resolvers)
    {
        $this->resolvers = $resolvers;
    }

    /**
     * Combine properties
     *
     * @{inheritdoc}
     */
    public function resolveAdditionalPropertiesFor(Message $message)
    {
        $properties = [];

        foreach ($this->resolvers as $resolver) {
            $properties = array_merge($properties, $resolver->resolveAdditionalPropertiesFor($message));
        }

        return $properties;
    }
}
