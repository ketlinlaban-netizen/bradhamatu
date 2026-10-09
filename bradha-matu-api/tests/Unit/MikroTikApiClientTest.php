<?php

namespace Tests\Unit;

use App\Services\MikroTikApiClient;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

class MikroTikApiClientTest extends TestCase
{
    private MikroTikApiClient $client;

    public function test_print_sends_command_attributes_api_attributes_and_queries_in_their_correct_formats(): void
    {
        [$clientSocket, $routerSocket] = stream_socket_pair(
            STREAM_PF_UNIX,
            STREAM_SOCK_STREAM,
            STREAM_IPPROTO_IP,
        );
        $this->attachSocket($clientSocket);

        fwrite($routerSocket, $this->sentence(['!re', '=cpu-load=4']).$this->sentence(['!done']));

        $items = $this->client->print(
            '/system/resource/print',
            ['.proplist' => 'cpu-load,uptime', 'interface' => 'ether1'],
            ['?type=ether'],
        );

        self::assertSame([['cpu-load' => '4']], $items);
        self::assertSame(
            $this->sentence([
                '/system/resource/print',
                '.proplist=cpu-load,uptime',
                '=interface=ether1',
                '?type=ether',
            ]),
            fread($routerSocket, 4096),
        );

        fclose($routerSocket);
        $this->client->disconnect();
    }

    public function test_connection_close_while_waiting_for_a_sentence_throws_instead_of_looping(): void
    {
        [$clientSocket, $routerSocket] = stream_socket_pair(
            STREAM_PF_UNIX,
            STREAM_SOCK_STREAM,
            STREAM_IPPROTO_IP,
        );
        $this->attachSocket($clientSocket);
        fclose($routerSocket);

        $method = (new ReflectionClass($this->client))->getMethod('readSentences');

        try {
            $method->invoke($this->client);
            self::fail('Expected an exception after the router closed the socket.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('closed the connection', $exception->getMessage());
        } finally {
            $this->client->disconnect();
        }
    }

    public function test_fatal_router_reply_is_reported_as_an_error(): void
    {
        [$clientSocket, $routerSocket] = stream_socket_pair(
            STREAM_PF_UNIX,
            STREAM_SOCK_STREAM,
            STREAM_IPPROTO_IP,
        );
        $this->attachSocket($clientSocket);
        fwrite($routerSocket, $this->sentence(['!fatal', '=message=service unavailable']));

        try {
            $this->client->print('/system/resource/print');
            self::fail('Expected a fatal RouterOS reply to throw.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('service unavailable', $exception->getMessage());
        } finally {
            fclose($routerSocket);
            $this->client->disconnect();
        }
    }

    public function test_login_sends_username_and_password_in_the_documented_sentence(): void
    {
        [$clientSocket, $routerSocket] = stream_socket_pair(
            STREAM_PF_UNIX,
            STREAM_SOCK_STREAM,
            STREAM_IPPROTO_IP,
        );
        fwrite($routerSocket, $this->sentence(['!done']));

        $client = new class('127.0.0.1') extends MikroTikApiClient
        {
            /** @var resource|null */
            public $testSocket;

            protected function openSocket(): void
            {
                $property = (new ReflectionClass(MikroTikApiClient::class))->getProperty('socket');
                $property->setValue($this, $this->testSocket);
            }
        };
        $client->testSocket = $clientSocket;

        $client->connect('monitoring', 'test-password');

        self::assertSame(
            $this->sentence(['/login', '=name=monitoring', '=password=test-password']),
            fread($routerSocket, 4096),
        );

        fclose($routerSocket);
        $client->disconnect();
    }

    public function test_length_encoding_matches_routeros_boundaries(): void
    {
        $client = new MikroTikApiClient('127.0.0.1');
        $method = (new ReflectionClass($client))->getMethod('encodeLength');

        foreach ([
            0x7F => "\x7F",
            0x80 => "\x80\x80",
            0x4000 => "\xC0\x40\x00",
            0x200000 => "\xE0\x20\x00\x00",
            0x10000000 => "\xF0\x10\x00\x00\x00",
        ] as $length => $expected) {
            self::assertSame($expected, $method->invoke($client, $length));
        }
    }

    /** @param resource $socket */
    private function attachSocket($socket): void
    {
        $this->client = new MikroTikApiClient('127.0.0.1');
        $property = (new ReflectionClass($this->client))->getProperty('socket');
        $property->setValue($this->client, $socket);
    }

    /**
     * @param  list<string>  $words
     */
    private function sentence(array $words): string
    {
        $encoded = '';
        foreach ($words as $word) {
            $length = strlen($word);
            self::assertLessThan(0x80, $length);
            $encoded .= chr($length).$word;
        }

        return $encoded."\x00";
    }
}
