<?php

namespace SimpleBus\Asynchronous\Tests\Properties;

use SimpleBus\Asynchronous\Properties\AdditionalPropertiesResolver;
use SimpleBus\Asynchronous\Properties\DelegatingAdditionalPropertiesResolver;
use SimpleBus\Message\Message;

class DelegatingAdditionalPropertiesResolverTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @test
     */
    public function it_should_merge_multiple_resolvers()
    {
        $message = $this->messageDummy();

        $resolver = new DelegatingAdditionalPropertiesResolver(array(
            $this->getResolver($message, array('test' => 'a')),
            $this->getResolver($message, array('test' => 'b', 'priority' => 123)),
        ));

        $this->assertSame(['test' => 'b', 'priority' => 123], $resolver->resolveAdditionalPropertiesFor($message));
    }

    /**
     * @param Message $message
     * @param array   $data
     *
     * @return \PHPUnit_Framework_MockObject_MockObject|AdditionalPropertiesResolver
     */
    private function getResolver(Message $message, array $data)
    {
        $resolver = $this->getMock('SimpleBus\Asynchronous\Properties\AdditionalPropertiesResolver');
        $resolver->expects($this->once())
            ->method('resolveAdditionalPropertiesFor')
            ->with($this->identicalTo($message))
            ->will($this->returnValue($data));

        return $resolver;
    }

    /**
     * @return \PHPUnit_Framework_MockObject_MockObject|Message
     */
    private function messageDummy()
    {
        return $this->getMock('SimpleBus\Message\Message');
    }
}
