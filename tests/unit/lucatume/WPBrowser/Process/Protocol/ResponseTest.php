<?php

namespace lucatume\WPBrowser\Process\Protocol;

use Exception;
use lucatume\WPBrowser\Opis\Closure\SerializableClosure;
use lucatume\WPBrowser\Process\SerializableThrowable;
use lucatume\WPBrowser\Traits\UopzFunctions;
use PHPUnit\Framework\TestCase;
use Throwable;

class ResponseTest extends TestCase
{
    use UopzFunctions;

    public function testConstructorAndGettersNormal(): void
    {
        $returnValue = "success";
        $exitValue = 0;
        $telemetry = ["memoryPeakUsage" => 123456];

        $response = new Response($returnValue, $exitValue, $telemetry);

        $this->assertEquals($returnValue, $response->getReturnValue());
        $this->assertEquals($exitValue, $response->getExitValue());
        $this->assertEquals($telemetry, $response->getTelemetry());
    }

    public function testConstructorAndGettersThrowable(): void
    {
        $exception = new Exception("Error");
        $response = new Response($exception);

        $this->assertInstanceOf(Throwable::class, $response->getReturnValue());
        $this->assertEquals(1, $response->getExitValue());
    }

    public function testContstrucortAndGettersSerializableThrowable(): void
    {
        $exception = new Exception("Error");
        $serializableThrowable = new SerializableThrowable($exception);
        $response = new Response($serializableThrowable);

        $this->assertInstanceOf(SerializableThrowable::class, $response->getReturnValue());
        $this->assertEquals(1, $response->getExitValue());
    }

    public function testFromStderrWithNoSeparator(): void
    {
        $stderrBufferString = '[17-Mar-2023 16:54:06 Europe/Paris] PHP Parse error:  Expected T_CLASS or string, got foo in Unknown on line 0';
        $response = Response::fromStderr($stderrBufferString);

        $this->assertInstanceOf(\ParseError::class, $response->getReturnValue());
        $this->assertEquals(1, $response->getExitValue());
    }

    public function testFromStderrWithSeparatorAndValidPayload(): void
    {
        $returnValue = new SerializableClosure(static function () {
            return "success";
        });
        $telemetry = ["memoryPeakUsage" => 123456];
        $payload = Parser::encode([$returnValue, $telemetry]);
        $separator = Response::$stderrValueSeparator;
        $stderrBufferString = "{$separator}{$payload}";

        $response = Response::fromStderr($stderrBufferString);

        $this->assertEquals('success', $response->getReturnValue());
        $this->assertEquals(0, $response->getExitValue());
        $this->assertEquals($telemetry, $response->getTelemetry());
    }

    public function testGetPayload(): void
    {
        $returnValue = "success";
        $exitValue = 0;
        $telemetry = ["memoryPeakUsage" => 123456];
        $this->setFunctionReturn('memory_get_peak_usage', 123456);

        $response = new Response($returnValue, $exitValue, $telemetry);

        $payload = $response->getPayload();
        [$decodedReturnValue, $decodedTelemetry] = Parser::decode($payload);

        $this->assertEquals($returnValue, $decodedReturnValue());
        $this->assertEquals($telemetry, $decodedTelemetry);
    }

    public function testGetStderrLength(): void
    {
        $separator = Response::$stderrValueSeparator;
        $payload = Parser::encode([new SerializableClosure(static function () {
            return "success";
        }), ['foo' => 'bar']]);
        $stderrBufferString = "Error message{$separator}{$payload}";

        $response = Response::fromStderr($stderrBufferString);

        $this->assertEquals(strlen("Error message"), $response->getStderrLength());
    }

    public function testFromStderrWithNoiseAfterPayload():void{
        $returnValue = new SerializableClosure(static function () {
            return "success";
        });
        $telemetry = ["memoryPeakUsage" => 123456];
        $payload = Parser::encode([$returnValue, $telemetry]);
        $separator = Response::$stderrValueSeparator;
        $stderrBufferString = "{$separator}{$payload}some noise";

        $response = Response::fromStderr($stderrBufferString);

        $this->assertEquals('success', $response->getReturnValue());
        $this->assertEquals(0, $response->getExitValue());
        $this->assertEquals($telemetry, $response->getTelemetry());
    }
}
