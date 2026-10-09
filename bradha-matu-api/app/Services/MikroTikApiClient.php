<?php

namespace App\Services;

/**
 * MikroTikApiClient
 *
 * A pure-PHP implementation of the MikroTik RouterOS binary API protocol.
 * Communicates over TCP (port 8728) or TCP+TLS (port 8729) — no HTTP needed.
 *
 * Protocol reference:
 *   https://help.mikrotik.com/docs/display/ROS/API
 *
 * Flow:
 *   1. Open socket to router on port 8728 (plain) or 8729 (TLS)
 *   2. Send /login with the username and password
 *   3. Send command sentences (e.g. /system/resource/print) → receive !re/!done replies
 *   4. Close socket
 *
 * Login credentials are sent in the documented /login sentence. Use TLS or
 * a trusted management network because the binary API does not encrypt
 * passwords on a plain TCP connection.
 *
 * All data sent after login is read-only print/getall commands — NO set/add/remove/reboot.
 *
 * Word encoding (length-prefixed):
 *   0 <= len <= 0x7F       → 1 byte:  len
 *   0x80 <= len <= 0x3FFF  → 2 bytes: len (high byte | 0x80, low byte)
 *   0x4000 <= len <= 0x1FFFFF → 3 bytes: len
 *   0x200000 <= len <= 0xFFFFFFF → 4 bytes: len
 *   len >= 0x10000000      → 5 bytes: 0xF0 + 4-byte len
 *
 * A sentence = sequence of words terminated by a zero-length word (0x00).
 */
class MikroTikApiClient
{
    /** @var resource|null */
    private $socket;

    private string $host;

    private int $port;

    private bool $useTls;

    private bool $verifyTlsCertificate;

    private int $timeout;

    public function __construct(
        string $host,
        int $port = 8728,
        bool $useTls = false,
        int $timeout = 10,
        bool $verifyTlsCertificate = true,
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->useTls = $useTls;
        $this->timeout = $timeout;
        $this->verifyTlsCertificate = $verifyTlsCertificate;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Connection lifecycle
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Connect to the router and authenticate.
     *
     * @throws \Exception on connection or auth failure
     */
    public function connect(string $username, string $password): void
    {
        $this->openSocket();

        try {
            $this->sendSentence(['/login', "=name={$username}", "=password={$password}"]);

            foreach ($this->readSentences() as $sentence) {
                if (isset($sentence['!trap']) || isset($sentence['!fatal'])) {
                    $message = $sentence['message'] ?? 'Authentication failed';
                    throw new \RuntimeException("MikroTik auth error: {$message}");
                }
                if (isset($sentence['!done'])) {
                    return;
                }
            }

            throw new \RuntimeException('MikroTik login did not complete successfully');
        } catch (\Throwable $exception) {
            $this->disconnect();
            throw $exception;
        }
    }

    public function disconnect(): void
    {
        if ($this->socket) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    public function isConnected(): bool
    {
        return $this->socket !== null;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Command execution
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Send a print command and return all data items.
     *
     * @param  string  $command  e.g. "/system/resource/print"
     * @param  array  $args  attribute words, e.g. ['.proplist' => 'cpu-load,uptime']
     * @param  array  $queries  query words, e.g. ['?type=ether', '?type=vlan', '?#|']
     * @return array<int, array<string, string>> list of items, each a key-value map
     *
     * @throws \Exception on trap/error
     */
    public function print(string $command, array $args = [], array $queries = []): array
    {
        $words = [$command];

        // API attribute words (prefixed with .)
        foreach ($args as $key => $value) {
            if (is_int($key)) {
                $words[] = ".{$value}";
            } elseif (str_starts_with((string) $key, '.')) {
                $words[] = "{$key}={$value}";
            } else {
                $words[] = "={$key}={$value}";
            }
        }

        // Query words (prefixed with ?)
        foreach ($queries as $query) {
            $words[] = $query;
        }

        $this->sendSentence($words);
        $sentences = $this->readSentences();

        $items = [];
        foreach ($sentences as $sentence) {
            if (isset($sentence['!trap'])) {
                $msg = $sentence['message'] ?? 'Unknown error';
                throw new \Exception("MikroTik trap: {$msg}");
            }
            if (isset($sentence['!fatal'])) {
                $msg = $sentence['message'] ?? 'Router closed the connection';
                throw new \RuntimeException("MikroTik fatal error: {$msg}");
            }
            if (isset($sentence['!re'])) {
                // Data reply — extract attribute key-value pairs
                $item = [];
                foreach ($sentence['!re'] as $attr) {
                    if (str_starts_with($attr, '=')) {
                        $eqPos = strpos($attr, '=', 1);
                        if ($eqPos !== false) {
                            $key = substr($attr, 1, $eqPos - 1);
                            $val = substr($attr, $eqPos + 1);
                            $item[$key] = $val;
                        }
                    }
                }
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Send a monitor command (e.g. /interface/monitor-traffic) with once
     * and return the data items. Monitor commands use POST semantics in REST
     * but in the binary API they are just print-like commands with extra args.
     */
    public function monitor(string $command, array $args = []): array
    {
        // Add once='' to get a single sample
        $args['once'] = '';

        return $this->print($command, $args);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Word encoding / decoding (the core protocol)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Encode a word's length prefix per the API protocol.
     *
     * Length encoding scheme:
     *   0 <= len <= 0x7F          → 1 byte
     *   0x80 <= len <= 0x3FFF     → 2 bytes (| 0x8000)
     *   0x4000 <= len <= 0x1FFFFF → 3 bytes (| 0xC00000)
     *   0x200000 <= len <= 0xFFFFFFF → 4 bytes (| 0xE0000000)
     *   len >= 0x10000000         → 5 bytes (0xF0 prefix + 4-byte len)
     */
    private function encodeLength(int $len): string
    {
        if ($len < 0 || $len > 0xFFFFFFFF) {
            throw new \InvalidArgumentException('RouterOS API word length must be between 0 and 0xFFFFFFFF bytes');
        }

        if ($len < 0x80) {
            return chr($len);
        }
        if ($len < 0x4000) {
            return chr($len >> 8 | 0x80).chr($len & 0xFF);
        }
        if ($len < 0x200000) {
            return chr($len >> 16 | 0xC0).chr(($len >> 8) & 0xFF).chr($len & 0xFF);
        }
        if ($len < 0x10000000) {
            return chr($len >> 24 | 0xE0).chr(($len >> 16) & 0xFF).chr(($len >> 8) & 0xFF).chr($len & 0xFF);
        }

        return chr(0xF0).chr(($len >> 24) & 0xFF).chr(($len >> 16) & 0xFF).chr(($len >> 8) & 0xFF).chr($len & 0xFF);
    }

    /**
     * Decode a word's length prefix from the socket.
     * Returns the decoded length, or -1 on EOF.
     */
    private function decodeLength(): int
    {
        $firstByte = $this->readByte();
        if ($firstByte === -1) {
            return -1;
        }

        if ($firstByte >= 0xF8) {
            throw new \UnexpectedValueException(sprintf('Unsupported RouterOS API control byte 0x%02X', $firstByte));
        }
        if ($firstByte < 0x80) {
            return $firstByte;
        }
        if (($firstByte & 0xF0) === 0x80) {
            return (($firstByte & 0x3F) << 8) | $this->readByte();
        }
        if (($firstByte & 0xF0) === 0xC0) {
            return (($firstByte & 0x1F) << 16) | ($this->readByte() << 8) | $this->readByte();
        }
        if (($firstByte & 0xF0) === 0xE0) {
            return (($firstByte & 0x0F) << 24) | ($this->readByte() << 16) | ($this->readByte() << 8) | $this->readByte();
        }
        if ($firstByte === 0xF0) {
            return unpack('N', $this->readRaw(4))[1];
        }

        throw new \UnexpectedValueException(sprintf('Invalid RouterOS API length prefix 0x%02X', $firstByte));
    }

    /**
     * Encode and write a single word to the socket.
     */
    private function writeWord(string $word): void
    {
        $encoded = $this->encodeLength(strlen($word)).$word;
        $this->writeRaw($encoded);
    }

    /**
     * Read a single word from the socket.
     * Returns the word string, or null on zero-length word (sentence terminator).
     */
    private function readWord(): ?string
    {
        $len = $this->decodeLength();
        if ($len < 0) {
            throw new \RuntimeException('RouterOS closed the connection before completing a sentence');
        }
        if ($len === 0) {
            return null;
        }  // zero-length word = sentence terminator

        return $this->readRaw($len);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Sentence send / read
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Send a sentence: write each word, then a zero-length word terminator.
     *
     * @param  array<int, string>  $words
     */
    private function sendSentence(array $words): void
    {
        foreach ($words as $word) {
            $this->writeWord($word);
        }
        // Zero-length word terminates the sentence
        $this->writeRaw("\x00");
    }

    /**
     * Read all reply sentences until !done or !fatal.
     *
     * Returns an array of parsed sentence arrays, each containing:
     *   - '!re'   => array of attribute words (data reply)
     *   - '!done' => true (command completed)
     *   - '!trap' => true + 'message' + 'category' (error)
     *   - '!fatal' => true + 'message' (connection closing)
     *
     * @return array<int, array<string, mixed>>
     */
    private function readSentences(): array
    {
        $sentences = [];
        $currentWords = [];

        while (true) {
            $word = $this->readWord();

            if ($word === null) {
                // Zero-length word — sentence terminator
                if (empty($currentWords)) {
                    // Empty sentence is ignored
                    $currentWords = [];

                    continue;
                }

                $parsed = $this->parseSentence($currentWords);
                $sentences[] = $parsed;
                $currentWords = [];

                // Stop after !done or !fatal
                if (isset($parsed['!done']) || isset($parsed['!fatal'])) {
                    break;
                }

                continue;
            }

            $currentWords[] = $word;
        }

        return $sentences;
    }

    /**
     * Parse a list of raw words into a structured sentence.
     *
     * @param  array<int, string>  $words
     * @return array<string, mixed>
     */
    private function parseSentence(array $words): array
    {
        $sentence = [];

        foreach ($words as $word) {
            if ($word === '!done') {
                $sentence['!done'] = true;
            } elseif ($word === '!trap') {
                $sentence['!trap'] = true;
            } elseif ($word === '!fatal') {
                $sentence['!fatal'] = true;
            } elseif ($word === '!re') {
                $sentence['!re'] = [];
            } elseif (str_starts_with($word, '=ret=')) {
                // Login challenge
                $sentence['ret'] = substr($word, 5);
            } elseif (str_starts_with($word, '=message=')) {
                $sentence['message'] = substr($word, 9);
            } elseif (str_starts_with($word, '=category=')) {
                $sentence['category'] = (int) substr($word, 10);
            } elseif (str_starts_with($word, '=')) {
                // Data attribute — add to !re array
                if (! isset($sentence['!re'])) {
                    $sentence['!re'] = [];
                }
                $sentence['!re'][] = $word;
            } elseif (str_starts_with($word, '.tag=')) {
                $sentence['tag'] = substr($word, 5);
            }
        }

        return $sentence;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Low-level socket I/O
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Open a TCP socket (optionally TLS) to the router.
     *
     * @throws \Exception
     */
    protected function openSocket(): void
    {
        $remote = "tcp://{$this->host}:{$this->port}";

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => $this->verifyTlsCertificate,
                'verify_peer_name' => $this->verifyTlsCertificate,
                'allow_self_signed' => ! $this->verifyTlsCertificate,
                'peer_name' => $this->host,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $this->socket = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            (float) $this->timeout,
            STREAM_CLIENT_CONNECT,
            $this->useTls ? $context : null
        );

        if (! $this->socket) {
            throw new \Exception("Cannot connect to {$this->host}:{$this->port} — {$errstr} ({$errno})");
        }

        stream_set_timeout($this->socket, $this->timeout);

        if ($this->useTls) {
            $cryptoResult = @stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if (! $cryptoResult) {
                $this->disconnect();
                throw new \Exception("TLS handshake failed with {$this->host}:{$this->port}");
            }
        }
    }

    /**
     * Read a single byte from the socket. Returns -1 on EOF.
     */
    private function readByte(): int
    {
        if (! $this->socket) {
            return -1;
        }
        $data = @fread($this->socket, 1);
        if ($data === false || strlen($data) === 0) {
            return -1;
        }

        return ord($data);
    }

    /**
     * Read exactly $length bytes from the socket.
     *
     * @throws \Exception on read failure
     */
    private function readRaw(int $length): string
    {
        if (! $this->socket) {
            throw new \Exception('Socket not connected');
        }

        $data = '';
        $remaining = $length;
        while ($remaining > 0) {
            $chunk = @fread($this->socket, $remaining);
            if ($chunk === false || strlen($chunk) === 0) {
                $meta = stream_get_meta_data($this->socket);
                if ($meta['timed_out']) {
                    throw new \Exception("Socket read timed out ({$this->host}:{$this->port})");
                }
                throw new \Exception('Socket read error — connection may be closed');
            }
            $data .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $data;
    }

    /**
     * Write raw bytes to the socket.
     *
     * @throws \Exception on write failure
     */
    private function writeRaw(string $data): void
    {
        if (! $this->socket) {
            throw new \Exception('Socket not connected');
        }
        $written = 0;
        $total = strlen($data);
        while ($written < $total) {
            $chunk = @fwrite($this->socket, substr($data, $written));
            if ($chunk === false || $chunk === 0) {
                throw new \Exception('Socket write failed — connection may be closed');
            }
            $written += $chunk;
        }
        fflush($this->socket);
    }

    /**
     * Ensure socket is closed when the object is destroyed.
     */
    public function __destruct()
    {
        $this->disconnect();
    }
}
