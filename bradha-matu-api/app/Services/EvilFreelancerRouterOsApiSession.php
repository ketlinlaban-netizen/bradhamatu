<?php

namespace App\Services;

use RouterOS\Client;
use RouterOS\Query;
use Throwable;

class EvilFreelancerRouterOsApiSession implements RouterOsApiSession
{
    public function __construct(private Client $client) {}

    public function __destruct()
    {
        $socket = $this->client->getSocket();
        if (is_resource($socket)) {
            fclose($socket);
        }
    }

    public function print(string $command, array $args = [], array $queries = []): array
    {
        $query = new Query($command);

        foreach ($args as $key => $value) {
            $key = (string) $key;
            $query->add(str_starts_with($key, '.') ? "{$key}={$value}" : "={$key}={$value}");
        }
        foreach ($queries as $word) {
            $query->add($word);
        }

        try {
            $raw = $this->client->query($query)->read(false);
            if (! is_array($raw)) {
                throw new RouterApiException('protocol_error', 'RouterOS returned an invalid API response.');
            }

            if (in_array('!trap', $raw, true)) {
                $message = $this->attribute($raw, 'message');
                $lowerMessage = strtolower($message);
                $permissionDenied = str_contains($lowerMessage, 'permission')
                    || str_contains($lowerMessage, 'not enough privileges');
                $unsupported = str_contains($lowerMessage, 'bad command name')
                    || str_contains($lowerMessage, 'no such command')
                    || str_contains($lowerMessage, 'invalid command');

                throw new RouterApiException(
                    $permissionDenied ? 'permission_denied' : ($unsupported ? 'unsupported_command' : 'router_rejected_query'),
                    $permissionDenied
                        ? 'The monitoring account is not permitted to read this RouterOS resource.'
                        : ($unsupported
                            ? 'This RouterOS version or installed feature does not support the requested read-only resource.'
                            : 'RouterOS rejected the read-only query.'),
                );
            }

            if (in_array('!fatal', $raw, true)) {
                throw new RouterApiException('protocol_error', 'RouterOS closed the API session unexpectedly.');
            }
            if (! in_array('!done', $raw, true)) {
                throw new RouterApiException('protocol_error', 'RouterOS did not complete the API response.');
            }

            return $this->client->parseResponse($raw);
        } catch (RouterApiException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $message = strtolower($exception->getMessage());
            $category = str_contains($message, 'timeout')
                ? 'query_timeout'
                : 'protocol_error';
            $safeMessage = $category === 'query_timeout'
                ? 'The router did not complete the read query before the timeout.'
                : 'The RouterOS read query failed.';

            throw new RouterApiException($category, $safeMessage, $exception);
        }
    }

    private function attribute(array $words, string $name): string
    {
        foreach ($words as $word) {
            if (is_string($word) && str_starts_with($word, "={$name}=")) {
                return substr($word, strlen($name) + 2);
            }
        }

        return '';
    }
}
