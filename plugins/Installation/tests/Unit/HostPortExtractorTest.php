<?php

namespace Piwik\Plugins\Installation\tests\Unit;

use Piwik\Plugins\Installation\HostPortExtractor;

class HostPortExtractorTest extends \PHPUnit\Framework\TestCase
{
    public function testStandardIpAddressNoPort()
    {
        $IP = '127.0.0.1';
        $extractedIPandPort = HostPortExtractor::extract($IP);

        $this->assertNull($extractedIPandPort);
    }

    public function testStandardIpAddressWithPort()
    {
        $IpWithPort = '127.0.0.1:3000';
        $extractedIPandPort = HostPortExtractor::extract($IpWithPort);
 
        $this->assertEquals($extractedIPandPort->host, '127.0.0.1');
        $this->assertEquals($extractedIPandPort->port, '3000');
    }

    public function testAddressNoPort()
    {
        $address = 'localhost';
        $extractedIPandPort = HostPortExtractor::extract($address);

        $this->assertNull($extractedIPandPort);
    }

    public function testAddressWithPort()
    {
        $address = 'localhost:3000';
        $extractedIPandPort = HostPortExtractor::extract($address);

        $this->assertEquals($extractedIPandPort->host, 'localhost');
        $this->assertEquals($extractedIPandPort->port, '3000');
    }

    public function testUnixSocket()
    {
        $unixSocket = '/test/path/socket';
        $extractedUnixSocket = HostPortExtractor::extract($unixSocket);

        $this->assertEquals($extractedUnixSocket->host, '');
        $this->assertEquals($extractedUnixSocket->port, '/test/path/socket');
    }

    public function testIPv6NoPort()
    {
        $IPv6 = '[2001:db8:3333:4444:5555:6666:7777:8888]';
        $extractedIP = HostPortExtractor::extract($IPv6);

        $this->assertEquals($extractedIP->host, '[2001:db8:3333:4444:5555:6666:7777:8888]');
        $this->assertEquals($extractedIP->port, '');
    }

    public function testIPv6WithPort()
    {
        $IPv6WithPort = '[2001:db8:3333:4444:5555:6666:7777:8888]:3000';
        $extractedIPandPort = HostPortExtractor::extract($IPv6WithPort);

        $this->assertEquals($extractedIPandPort->host,'[2001:db8:3333:4444:5555:6666:7777:8888]');
        $this->assertEquals($extractedIPandPort->port, '3000');
    }

    public function testIpv6Short()
    {
        $IPv6Short = '[2001::8888]';
        $extractedIP = HostPortExtractor::extract($IPv6Short);

        $this->assertEquals($extractedIP->host, '[2001::8888]');
        $this->assertEquals($extractedIP->port, '');
    }

    public function testIPv6NoBrackets()
    {
        $IpNoBrackets = '2001::8888';
        $extractedIP = HostPortExtractor::extract($IpNoBrackets);

        $this->assertNull($extractedIP);
    }

    public function testIPv6TooManyColons()
    {
        $IPv6TooManyColons = '[2001::db8::8888]';
        $extractedIP = HostPortExtractor::extract($IPv6TooManyColons);

        $this->assertNull($extractedIP);
    }

    public function testIPv6InvalidChars()
    {
        $IPv6InvalidChars = '[200r::11zz]';
        $extractedIP = HostPortExtractor::extract($IPv6InvalidChars);

        $this->assertNull($extractedIP);
    }
}
