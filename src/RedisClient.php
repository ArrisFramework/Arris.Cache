<?php

namespace Arris\Cache;

use Exception;
use Redis;
use RedisException;

use Arris\Cache\Exceptions\RedisClientException;

if (!defined('CRLF')) {
    define('CRLF', sprintf('%s%s', chr(13), chr(10)));
}

/**
 * RedisClient, a lightweight Redis PHP standalone client and phpredis wrapper
 *
 * Server/Connection:
 * @method RedisClient               pipeline()
 * @method RedisClient               multi()
 * @method RedisClient               watch(string ...$keys)
 * @method RedisClient               unwatch()
 * @method array                exec()
 * @method string|RedisClient        flushAll()
 * @method string|RedisClient        flushDb()
 * @method array|RedisClient         info(string $section = null)
 * @method bool|array|RedisClient    config(string $setGet, string $key, string $value = null)
 * @method array|RedisClient         role()
 * @method array|RedisClient         time()
 *
 * Keys:
 * @method int|RedisClient           del(string $key)
 * @method int|RedisClient           exists(string $key)
 * @method int|RedisClient           expire(string $key, int $seconds)
 * @method int|RedisClient           expireAt(string $key, int $timestamp)
 * @method array|RedisClient         keys(string $key)
 * @method int|RedisClient           persist(string $key)
 * @method bool|RedisClient          rename(string $key, string $newKey)
 * @method bool|RedisClient          renameNx(string $key, string $newKey)
 * @method array|RedisClient         sort(string $key, string $arg1, string $valueN = null)
 * @method int|RedisClient           ttl(string $key)
 * @method string|RedisClient        type(string $key)
 *
 * Scalars:
 * @method int|RedisClient           append(string $key, string $value)
 * @method int|RedisClient           decr(string $key)
 * @method int|RedisClient           decrBy(string $key, int $decrement)
 * @method bool|string|RedisClient   get(string $key)
 * @method int|RedisClient           getBit(string $key, int $offset)
 * @method string|RedisClient        getRange(string $key, int $start, int $end)
 * @method string|RedisClient        getSet(string $key, string $value)
 * @method int|RedisClient           incr(string $key)
 * @method int|RedisClient           incrBy(string $key, int $decrement)
 * @method array|RedisClient         mGet(array $keys)
 * @method bool|RedisClient          mSet(array $keysValues)
 * @method int|RedisClient           mSetNx(array $keysValues)
 * @method bool|RedisClient          set(string $key, string $value, int | array $options = null)
 * @method int|RedisClient           setBit(string $key, int $offset, int $value)
 * @method bool|RedisClient          setEx(string $key, int $seconds, string $value)
 * @method int|RedisClient           setNx(string $key, string $value)
 * @method int |RedisClient          setRange(string $key, int $offset, int $value)
 * @method int|RedisClient           strLen(string $key)
 *
 * Sets:
 * @method int|RedisClient           sAdd(string $key, mixed $value, string $valueN = null)
 * @method int|RedisClient           sRem(string $key, mixed $value, string $valueN = null)
 * @method array|RedisClient         sMembers(string $key)
 * @method array|RedisClient         sUnion(mixed $keyOrArray, string $valueN = null)
 * @method array|RedisClient         sInter(mixed $keyOrArray, string $valueN = null)
 * @method array |RedisClient        sDiff(mixed $keyOrArray, string $valueN = null)
 * @method string|RedisClient        sPop(string $key)
 * @method int|RedisClient           sCard(string $key)
 * @method int|RedisClient           sIsMember(string $key, string $member)
 * @method int|RedisClient           sMove(string $source, string $dest, string $member)
 * @method string|array|RedisClient  sRandMember(string $key, int $count = null)
 * @method int|RedisClient           sUnionStore(string $dest, string $key1, string $key2 = null)
 * @method int|RedisClient           sInterStore(string $dest, string $key1, string $key2 = null)
 * @method int|RedisClient           sDiffStore(string $dest, string $key1, string $key2 = null)
 *
 * Hashes:
 * @method bool|int|RedisClient      hSet(string $key, string $field, string $value)
 * @method bool|RedisClient          hSetNx(string $key, string $field, string $value)
 * @method bool|string|RedisClient   hGet(string $key, string $field)
 * @method bool|int|RedisClient      hLen(string $key)
 * @method bool|RedisClient          hDel(string $key, string $field)
 * @method array|RedisClient         hKeys(string $key, string $field)
 * @method array|RedisClient         hVals(string $key)
 * @method array|RedisClient         hGetAll(string $key)
 * @method bool|RedisClient          hExists(string $key, string $field)
 * @method int|RedisClient           hIncrBy(string $key, string $field, int $value)
 * @method float|RedisClient         hIncrByFloat(string $key, string $member, float $value)
 * @method bool|RedisClient          hMSet(string $key, array $keysValues)
 * @method array|RedisClient         hMGet(string $key, array $fields)
 *
 * Lists:
 * @method array|null|RedisClient    blPop(string $keyN, int $timeout)
 * @method array|null|RedisClient    brPop(string $keyN, int $timeout)
 * @method array|null |RedisClient   brPoplPush(string $source, string $destination, int $timeout)
 * @method string|null|RedisClient   lIndex(string $key, int $index)
 * @method int|RedisClient           lInsert(string $key, string $beforeAfter, string $pivot, string $value)
 * @method int|RedisClient           lLen(string $key)
 * @method string|null|RedisClient   lPop(string $key)
 * @method int|RedisClient           lPush(string $key, mixed $value, mixed $valueN = null)
 * @method int|RedisClient           lPushX(string $key, mixed $value)
 * @method array|RedisClient         lRange(string $key, int $start, int $stop)
 * @method int|RedisClient           lRem(string $key, int $count, mixed $value)
 * @method bool|RedisClient          lSet(string $key, int $index, mixed $value)
 * @method bool|RedisClient          lTrim(string $key, int $start, int $stop)
 * @method string|null|RedisClient   rPop(string $key)
 * @method string|null|RedisClient   rPoplPush(string $source, string $destination)
 * @method int|RedisClient           rPush(string $key, mixed $value, mixed $valueN = null)
 * @method int |RedisClient          rPushX(string $key, mixed $value)
 *
 * Sorted Sets:
 * @method int|RedisClient           zAdd(string $key, double $score, string $value)
 * @method int|RedisClient           zCard(string $key)
 * @method int|RedisClient           zSize(string $key)
 * @method int|RedisClient           zCount(string $key, mixed $start, mixed $stop)
 * @method int|RedisClient           zIncrBy(string $key, double $value, string $member)
 * @method array|RedisClient         zRangeByScore(string $key, mixed $start, mixed $stop, array $args = null)
 * @method array|RedisClient         zRevRangeByScore(string $key, mixed $start, mixed $stop, array $args = null)
 * @method int|RedisClient           zRemRangeByScore(string $key, mixed $start, mixed $stop)
 * @method array|RedisClient         zRange(string $key, mixed $start, mixed $stop, array $args = null)
 * @method array|RedisClient         zRevRange(string $key, mixed $start, mixed $stop, array $args = null)
 * @method int|RedisClient           zRank(string $key, string $member)
 * @method int|RedisClient           zRevRank(string $key, string $member)
 * @method int|RedisClient           zRem(string $key, string $member)
 * @method int|RedisClient           zDelete(string $key, string $member)
 * TODO
 *
 * Pub/Sub
 * @method int |RedisClient          publish(string $channel, string $message)
 * @method int|array|RedisClient     pubsub(string $subCommand, $arg = null)
 *
 * Scripting:
 * @method string|int|RedisClient    script(string $command, string $arg1 = null)
 * @method string|int|array|bool|RedisClient eval(string $script, array $keys = null, array $args = null)
 * @method string|int|array|bool|RedisClient evalSha(string $script, array $keys = null, array $args = null)
 */

class RedisClient
{
    public const TYPE_STRING = 'string';
    public const TYPE_LIST = 'list';
    public const TYPE_SET = 'set';
    public const TYPE_ZSET = 'zset';
    public const TYPE_HASH = 'hash';
    public const TYPE_NONE = 'none';

    const FREAD_BLOCK_SIZE = 8192;

    /**
     * Socket connection to the Redis server or Redis library instance
     * @var resource|Redis
     */
    protected $redis;
    protected $redisMulti;

    /**
     * Host of the Redis server
     * @var string
     */
    protected $host;

    /**
     * Scheme of the Redis server (tcp, tls, unix)
     * @var string
     */
    protected $scheme;

    /**
     * Port on which the Redis server is running
     * @var integer
     */
    protected $port;

    /**
     * Timeout for connecting to Redis server
     * @var float
     */
    protected $timeout;

    /**
     * Timeout for reading response from Redis server
     * @var float
     */
    protected $readTimeout;

    /**
     * Unique identifier for persistent connections
     * @var string
     */
    protected $persistent;

    /**
     * @var bool
     */
    protected $closeOnDestruct = TRUE;

    /**
     * @var bool
     */
    protected $connected = FALSE;

    /**
     * @var bool
     */
    protected $standalone;

    /**
     * @var int
     */
    protected $maxConnectRetries = 0;

    /**
     * @var int
     */
    protected $connectFailures = 0;

    /**
     * @var bool
     */
    protected $usePipeline = FALSE;

    /**
     * @var array
     */
    protected $commandNames;

    /**
     * @var string
     */
    protected $commands;

    /**
     * @var bool
     */
    protected $isMulti = FALSE;

    /**
     * @var bool
     */
    protected $isWatching = FALSE;

    /**
     * @var string
     */
    protected $authPassword;

    /**
     * @var int
     */
    protected $database = 0;

    /**
     * Aliases for backwards compatibility with phpredis
     * @var array
     */
    protected $wrapperMethods = array('delete' => 'del', 'getkeys' => 'keys', 'sremove' => 'srem');

    /**
     * @var array
     */
    protected $renamedCommands;

    /**
     * @var int
     */
    protected $requests = 0;

    /**
     * @var bool
     */
    protected $subscribed = false;
    
    
    /**
     * Creates a Redisent connection to the Redis server on host {@link $host} and port {@link $port}.
     * $host may also be a path to a unix socket or a string in the form of tcp://[hostname]:[port] or unix://[path]
     *
     * @param string $host The hostname of the Redis server
     * @param integer $port The port number of the Redis server
     * @param float|null $timeout Timeout period in seconds
     * @param string $persistent Flag to establish persistent connection
     * @param int $db The selected datbase of the Redis server
     * @param string|null $password The authentication password of the Redis server
     */
    public function __construct(string $host = '127.0.0.1', int $port = 6379, float $timeout = null, string $persistent = '', int $db = 0, string $password = null)
    {
        $this->host = $host;
        $this->port = $port;
        $this->scheme = null;
        $this->timeout = $timeout;
        $this->persistent = $persistent;
        $this->standalone = !extension_loaded('redis');
        $this->authPassword = $password;
        $this->database = $db;
        $this->convertHost();
        // PHP Redis extension support TLS since 5.3.0
        if ($this->scheme === 'tls' && !$this->standalone && version_compare(phpversion('redis'), '5.3.0', '<')) {
            $this->standalone = true;
        }
    }

    public function __destruct()
    {
        if ($this->closeOnDestruct) {
            $this->close();
        }
    }

    /**
     * @return bool
     */
    public function isSubscribed(): bool
    {
        return $this->subscribed;
    }

    /**
     * Return the host of the Redis instance
     * @return string
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * Return the port of the Redis instance
     * @return int
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * Return the selected database
     * @return int
     */
    public function getDatabase(): int
    {
        return $this->database;
    }

    /**
     * @return string
     */
    public function getPersistence(): string
    {
        return $this->persistent;
    }

    /**
     * @return RedisClient
     * @throws RedisClientException
     */
    public function forceStandalone(): RedisClient
    {
        if ($this->standalone) {
            return $this;
        }
        if ($this->connected) {
            throw new RedisClientException('Cannot force Arris\Cache\RedisClient to use standalone PHP driver after a connection has already been established.');
        }
        $this->standalone = TRUE;
        return $this;
    }

    /**
     * @param int $retries
     * @return RedisClient
     */
    public function setMaxConnectRetries($retries): RedisClient
    {
        $this->maxConnectRetries = $retries;
        return $this;
    }

    /**
     * @param bool $flag
     * @return RedisClient
     */
    public function setCloseOnDestruct($flag): RedisClient
    {
        $this->closeOnDestruct = $flag;
        return $this;
    }

    protected function convertHost(): void
    {
        if (preg_match('#^(tcp|tls|unix)://(.*)$#', $this->host, $matches)) {
            if ($matches[1] === 'tcp' || $matches[1] === 'tls') {
                $this->scheme = $matches[1];
                if (!preg_match('#^([^:]+)(:([0-9]+))?(/(.+))?$#', $matches[2], $matches)) {
                    throw new RedisClientException('Invalid host format; expected ' . $this->scheme . '://host[:port][/persistence_identifier]');
                }
                $this->host = $matches[1];
                $this->port = (int)(isset($matches[3]) ? $matches[3] : $this->port);
                $this->persistent = isset($matches[5]) ? $matches[5] : $this->persistent;
            } else {
                $this->host = $matches[2];
                $this->port = NULL;
                $this->scheme = 'unix';
                if ($this->host[0] !== '/') {
                    throw new RedisClientException('Invalid unix socket format; expected unix:///path/to/redis.sock');
                }
            }
        }
        if ($this->port !== NULL && $this->host[0] === '/') {
            $this->port = NULL;
            $this->scheme = 'unix';
        }
        if (!$this->scheme) {
            $this->scheme = 'tcp';
        }
    }

    /**
     * @return RedisClient
     * @throws RedisClientException
     */
    public function connect(): RedisClient
    {
        if ($this->connected) {
            return $this;
        }
        $this->close(true);

        if ($this->standalone) {
            $flags = STREAM_CLIENT_CONNECT;
            $remote_socket = $this->port === NULL
                ? $this->scheme . '://' . $this->host
                : $this->scheme . '://' . $this->host . ':' . $this->port;
            if ($this->persistent && $this->port !== NULL) {
                // Persistent connections to UNIX sockets are not supported
                $remote_socket .= '/' . $this->persistent;
                $flags |= STREAM_CLIENT_PERSISTENT;
            }
            $result = $this->redis = @stream_socket_client($remote_socket, $errno, $errstr, $this->timeout !== null ? $this->timeout : 2.5, $flags);
        } else {
            if (!$this->redis) {
                $this->redis = new Redis;
            }
            $socketTimeout = $this->timeout ?: 0.0;
            try {
                $result = $this->persistent
                    ? $this->redis->pconnect($this->host, $this->port, $socketTimeout, $this->persistent)
                    : $this->redis->connect($this->host, $this->port, $socketTimeout);
            } catch (Exception $e) {
                // Some applications will capture the php error that phpredis can sometimes generate and throw it as an Exception
                $result = false;
                $errno = 1;
                $errstr = $e->getMessage();
            }
        }

        // Use recursion for connection retries
        if (!$result) {
            $this->connectFailures++;
            if ($this->connectFailures <= $this->maxConnectRetries) {
                return $this->connect();
            }
            $failures = $this->connectFailures;
            $this->connectFailures = 0;
            throw new RedisClientException("Connection to Redis {$this->host}:{$this->port} failed after $failures failures." . (isset($errno) && isset($errstr) ? "Last Error : ({$errno}) {$errstr}" : ""));
        }

        $this->connectFailures = 0;
        $this->connected = TRUE;

        // Set read timeout
        if ($this->readTimeout) {
            $this->setReadTimeout($this->readTimeout);
        }

        if ($this->authPassword) {
            $this->auth($this->authPassword);
        }
        if ($this->database !== 0) {
            $this->select($this->database);
        }
        return $this;
    }

    /**
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->connected;
    }

    /**
     * Set the read timeout for the connection. Use 0 to disable timeouts entirely (or use a very long timeout
     * if not supported).
     *
     * @param int $timeout 0 (or -1) for no timeout, otherwise number of seconds
     * @return RedisClient
     * @throws RedisClientException
     */
    public function setReadTimeout($timeout): RedisClient
    {
        if ($timeout < -1) {
            throw new RedisClientException('Timeout values less than -1 are not accepted.');
        }
        $this->readTimeout = $timeout;
        if ($this->isConnected()) {
            if ($this->standalone) {
                $timeout = $timeout <= 0 ? 315360000 : $timeout; // Ten-year timeout
                stream_set_blocking($this->redis, TRUE);
                stream_set_timeout($this->redis, (int)floor($timeout), ($timeout - floor($timeout)) * 1000000);
            } else if (defined('Redis::OPT_READ_TIMEOUT')) {
                // supported in phpredis 2.2.3
                // a timeout value of -1 means reads will not timeout
                $timeout = $timeout == 0 ? -1 : $timeout;
                $this->redis->setOption(Redis::OPT_READ_TIMEOUT, $timeout);
            }
        }
        return $this;
    }

    /**
     * @return bool
     */
    public function close($force = FALSE): bool
    {
        $result = TRUE;
        if ($this->redis && ($force || ($this->connected && !$this->persistent))) {
            try {
                if (is_callable(array($this->redis, 'close'))) {
                    $this->redis->close();
                } else {
                    @fclose($this->redis);
                    $this->redis = null;
                }
            } catch (Exception $e) {
                // Ignore exceptions on close
            }
            $this->connected = $this->usePipeline = $this->isMulti = $this->isWatching = FALSE;
        }
        return $result;
    }

    /**
     * Enabled command renaming and provide mapping method. Supported methods are:
     *
     * 1. renameCommand('foo') // Salted md5 hash for all commands -> md5('foo'.$command)
     * 2. renameCommand(function($command){ return 'my'.$command; }); // Callable
     * 3. renameCommand('get', 'foo') // Single command -> alias
     * 4. renameCommand(['get' => 'foo', 'set' => 'bar']) // Full map of [command -> alias]
     *
     * @param string|callable|array $command
     * @param string|null $alias
     * @return $this
     */
    public function renameCommand($command, $alias = NULL): RedisClient
    {
        if (!$this->standalone) {
            $this->forceStandalone();
        }
        if ($alias === NULL) {
            $this->renamedCommands = $command;
        } else {
            if (!$this->renamedCommands) {
                $this->renamedCommands = array();
            }
            $this->renamedCommands[$command] = $alias;
        }
        return $this;
    }

    /**
     * @param $command
     * @return string
     */
    public function getRenamedCommand($command): string
    {
        static $map;

        // Command renaming not enabled
        if ($this->renamedCommands === NULL) {
            return $command;
        }

        // Initialize command map
        if ($map === NULL) {
            if (is_array($this->renamedCommands)) {
                $map = $this->renamedCommands;
            } else {
                $map = array();
            }
        }

        // Generate and return cached result
        if (!isset($map[$command])) {
            // String means all commands are hashed with salted md5
            if (is_string($this->renamedCommands)) {
                $map[$command] = md5($this->renamedCommands . $command);
            } // Would already be set in $map if it was intended to be renamed
            else if (is_array($this->renamedCommands)) {
                return $command;
            } // User-supplied function
            else if (is_callable($this->renamedCommands)) {
                $map[$command] = call_user_func($this->renamedCommands, $command);
            }
        }
        return $map[$command];
    }

    /**
     * @param string $password
     * @return bool
     */
    public function auth($password)
    {
        $response = $this->__call('auth', array($password));
        $this->authPassword = $password;
        return $response;
    }

    /**
     * @param int $index
     * @return bool
     */
    public function select($index)
    {
        $response = $this->__call('select', array($index));
        $this->database = (int)$index;
        return $response;
    }

    /**
     * @param string|array $pattern
     * @return array
     */
    public function pUnsubscribe(): array
    {
        list($command, $channel, $subscribedChannels) = $this->__call('punsubscribe', func_get_args());
        $this->subscribed = $subscribedChannels > 0;
        return array($command, $channel, $subscribedChannels);
    }

    /**
     * @param int $Iterator
     * @param string $pattern
     * @param int $count
     * @return bool|array
     */
    public function scan(&$Iterator, $pattern = null, $count = null)
    {
        return $this->__call('scan', array(&$Iterator, $pattern, $count));
    }

    /**
     * @param int $Iterator
     * @param string $field
     * @param string $pattern
     * @param int $count
     * @return bool|array
     */
    public function hscan(&$Iterator, $field, $pattern = null, $count = null)
    {
        return $this->__call('hscan', array($field, &$Iterator, $pattern, $count));
    }

    /**
     * @param int $Iterator
     * @param string $field
     * @param string $pattern
     * @param int $Iterator
     * @return bool|array
     */
    public function sscan(&$Iterator, $field, $pattern = null, $count = null)
    {
        return $this->__call('sscan', array($field, &$Iterator, $pattern, $count));
    }

    /**
     * @param int $Iterator
     * @param string $field
     * @param string $pattern
     * @param int $Iterator
     * @return bool|array
     */
    public function zscan(&$Iterator, $field, $pattern = null, $count = null)
    {
        return $this->__call('zscan', array($field, &$Iterator, $pattern, $count));
    }

    /**
     * @param string|array $patterns
     * @param $callback
     * @return $this|array|bool|RedisClient|mixed|null|string
     * @throws RedisClientException
     */
    public function pSubscribe($patterns, $callback)
    {
        if (!$this->standalone) {
            return $this->__call('pSubscribe', array((array)$patterns, $callback));
        }

        // Standalone mode: use infinite loop to subscribe until timeout
        $patternCount = is_array($patterns) ? count($patterns) : 1;
        while ($patternCount--) {
            [$command, $pattern, $status] = $this->__call('psubscribe', array($patterns));

            $this->subscribed = $status > 0;
            if (!$status) {
                throw new RedisClientException('Invalid pSubscribe response.');
            }
        }
        while ($this->subscribed) {
            [$type, $pattern, $channel, $message] = $this->read_reply();
            if ($type !== 'pmessage') {
                throw new RedisClientException('Received non-pmessage reply.');
            }
            $callback($this, $pattern, $channel, $message);
        }
        return null;
    }

    /**
     * @param string|array $pattern
     * @return array
     */
    public function unsubscribe(): array
    {
        list($command, $channel, $subscribedChannels) = $this->__call('unsubscribe', func_get_args());
        $this->subscribed = $subscribedChannels > 0;
        return array($command, $channel, $subscribedChannels);
    }

    /**
     * @param string|array $channels
     * @param $callback
     * @return $this|array|bool|RedisClient|mixed|null|string
     * @throws RedisClientException
     */
    public function subscribe($channels, $callback)
    {
        if (!$this->standalone) {
            return $this->__call('subscribe', array((array)$channels, $callback));
        }

        // Standalone mode: use infinite loop to subscribe until timeout
        $channelCount = is_array($channels) ? count($channels) : 1;
        while ($channelCount--) {
            [$command, $channel, $status] = $this->__call('subscribe', array($channels));
            $this->subscribed = $status > 0;
            if (!$status) {
                throw new RedisClientException('Invalid subscribe response.');
            }
        }
        while ($this->subscribed) {
            [$type, $channel, $message] = $this->read_reply();
            if ($type !== 'message') {
                throw new RedisClientException('Received non-message reply.');
            }
            $callback($this, $channel, $message);
        }
        return null;
    }

    /**
     * @param string|null $name
     * @return string|RedisClient
     */
    public function ping($name = null)
    {
        return $this->__call('ping', $name ? array($name) : array());
    }

    /**
     * @param $name
     * @param $args
     * @return $this|RedisClient|array|bool|int|string|null
     */
    public function __call($name, $args)
    {
        // Lazy connection
        $this->connect();

        $name = strtolower($name);

        // Send request via native PHP
        if ($this->standalone) {
            $trackedArgs = array();
            switch ($name) {
                case 'eval':
                case 'evalsha':
                    $script = array_shift($args);
                    $keys = (array)array_shift($args);
                    $eArgs = (array)array_shift($args);
                    $args = array($script, count($keys), $keys, $eArgs);
                    break;
                case 'zinterstore':
                case 'zunionstore':
                    $dest = array_shift($args);
                    $keys = (array)array_shift($args);
                    $weights = array_shift($args);
                    $aggregate = array_shift($args);
                    $args = array($dest, count($keys), $keys);
                    if ($weights) {
                        $args[] = (array)$weights;
                    }
                    if ($aggregate) {
                        $args[] = $aggregate;
                    }
                    break;
                case 'set':
                    // The php redis module has different behaviour with ttl
                    // https://github.com/phpredis/phpredis#set
                    if (count($args) === 3) {

                        if (is_int($args[2])) {
                            $args = array($args[0], $args[1], array('EX', $args[2]));
                        } elseif (is_array($args[2])) {
                            $tmp_args = $args;
                            $args = array($tmp_args[0], $tmp_args[1]);
                            foreach ($tmp_args[2] as $k => $v) {
                                if (is_string($k)) {
                                    $args[] = array($k, $v);
                                } elseif (is_int($k)) {
                                    $args[] = $v;
                                }
                            }
                            unset($tmp_args);
                        }
                    }
                    break;

                case 'scan':
                    $trackedArgs = array(&$args[0]);
                    if (empty($trackedArgs[0])) {
                        $trackedArgs[0] = 0;
                    }
                    $eArgs = array($trackedArgs[0]);
                    if (!empty($args[1])) {
                        $eArgs[] = 'MATCH';
                        $eArgs[] = $args[1];
                    }
                    if (!empty($args[2])) {
                        $eArgs[] = 'COUNT';
                        $eArgs[] = $args[2];
                    }
                    $args = $eArgs;
                    break;
                case 'sscan':
                case 'zscan':
                case 'hscan':
                    $trackedArgs = array(&$args[1]);
                    if (empty($trackedArgs[0])) {
                        $trackedArgs[0] = 0;
                    }
                    $eArgs = array($args[0], $trackedArgs[0]);
                    if (!empty($args[2])) {
                        $eArgs[] = 'MATCH';
                        $eArgs[] = $args[2];
                    }
                    if (!empty($args[3])) {
                        $eArgs[] = 'COUNT';
                        $eArgs[] = $args[3];
                    }
                    $args = $eArgs;
                    break;
                case 'zrangebyscore':
                case 'zrevrangebyscore':
                case 'zrange':
                case 'zrevrange':
                    if (isset($args[3]) && is_array($args[3])) {
                        // map options
                        $cArgs = array();
                        if (!empty($args[3]['withscores'])) {
                            $cArgs[] = 'withscores';
                        }
                        if (($name === 'zrangebyscore' || $name === 'zrevrangebyscore') && array_key_exists('limit', $args[3])) {
                            $cArgs[] = array('limit' => $args[3]['limit']);
                        }
                        $args[3] = $cArgs;
                        $trackedArgs = $cArgs;
                    }
                    break;
                case 'mget':
                    if (isset($args[0]) && is_array($args[0])) {
                        $args = array_values($args[0]);
                    }
                    break;
                case 'hmset':
                    if (isset($args[1]) && is_array($args[1])) {
                        $cArgs = array();
                        foreach ($args[1] as $id => $value) {
                            $cArgs[] = $id;
                            $cArgs[] = $value;
                        }
                        $args[1] = $cArgs;
                    }
                    break;
                case 'zsize':
                    $name = 'zcard';
                    break;
                case 'zdelete':
                    $name = 'zrem';
                    break;
                case 'hmget':
                    // hmget needs to track the keys for rehydrating the results
                    if (isset($args[1])) {
                        $trackedArgs = $args[1];
                    }
                    break;
            }
            // Flatten arguments
            $args = self::_flattenArguments($args);

            // In pipeline mode
            if ($this->usePipeline) {
                if ($name === 'pipeline') {
                    throw new RedisClientException('A pipeline is already in use and only one pipeline is supported.');
                }

                if ($name === 'exec') {
                    if ($this->isMulti) {
                        $this->commandNames[] = array($name, $trackedArgs);
                        $this->commands .= self::_prepare_command(array($this->getRenamedCommand($name)));
                    }

                    // Write request
                    if ($this->commands) {
                        $this->write_command($this->commands);
                    }
                    $this->commands = NULL;

                    // Read response
                    $queuedResponses = array();
                    $response = array();
                    foreach ($this->commandNames as $command) {
                        [$name, $arguments] = $command;
                        $result = $this->read_reply($name, true);
                        if ($result !== null) {
                            $result = $this->decode_reply($name, $result, $arguments);
                        } else {
                            $queuedResponses[] = $command;
                        }
                        $response[] = $result;
                    }

                    if ($this->isMulti) {
                        $response = array_pop($response);
                        foreach ($queuedResponses as $key => $command) {
                            list($name, $arguments) = $command;
                            $response[$key] = $this->decode_reply($name, $response[$key], $arguments);
                        }
                    }

                    $this->commandNames = NULL;
                    $this->usePipeline = $this->isMulti = FALSE;
                    return $response;
                } else if ($name === 'discard') {
                    $this->commands = NULL;
                    $this->commandNames = NULL;
                    $this->usePipeline = $this->isMulti = FALSE;
                } else {
                    if ($name === 'multi') {
                        $this->isMulti = TRUE;
                    }
                    array_unshift($args, $this->getRenamedCommand($name));
                    $this->commandNames[] = array($name, $trackedArgs);
                    $this->commands .= self::_prepare_command($args);
                    return $this;
                }
            }

            // Start pipeline mode
            if ($name === 'pipeline') {
                $this->usePipeline = TRUE;
                $this->commandNames = array();
                $this->commands = '';
                return $this;
            }

            // If unwatching, allow reconnect with no error thrown
            if ($name === 'unwatch') {
                $this->isWatching = FALSE;
            }

            // Non-pipeline mode
            array_unshift($args, $this->getRenamedCommand($name));
            $command = self::_prepare_command($args);
            $this->write_command($command);
            $response = $this->read_reply($name);
            $response = $this->decode_reply($name, $response, $trackedArgs);

            // Watch mode disables reconnect so error is thrown
            if ($name === 'watch') {
                $this->isWatching = TRUE;
            } // Transaction mode
            else if ($this->isMulti && ($name === 'exec' || $name === 'discard')) {
                $this->isMulti = FALSE;
            } // Started transaction
            else if ($this->isMulti || $name === 'multi') {
                $this->isMulti = TRUE;
                $response = $this;
            }
        } // Send request via phpredis client

        else {
            // Tweak arguments
            switch ($name) {
                case 'get':   // optimize common cases
                case 'set':
                case 'hget':
                case 'hset':
                case 'setex':
                case 'mset':
                case 'msetnx':
                case 'hmset':
                case 'hmget':
                case 'del':
                case 'zrangebyscore':
                case 'zrevrangebyscore':
                    break;
                case 'zrange':
                case 'zrevrange':
                    if (isset($args[3]) && is_array($args[3])) {
                        $cArgs = $args[3];
                        $args[3] = !empty($cArgs['withscores']);
                    }
                    $args = self::_flattenArguments($args);
                    break;
                case 'zinterstore':
                case 'zunionstore':
                    $cArgs = array();
                    $cArgs[] = array_shift($args); // destination
                    $cArgs[] = array_shift($args); // keys
                    if (isset($args[0]) and isset($args[0]['weights'])) {
                        $cArgs[] = (array)$args[0]['weights'];
                    } else {
                        $cArgs[] = null;
                    }
                    if (isset($args[0]) and isset($args[0]['aggregate'])) {
                        $cArgs[] = strtoupper($args[0]['aggregate']);
                    }
                    $args = $cArgs;
                    break;
                case 'mget':
                    if (isset($args[0]) && !is_array($args[0])) {
                        $args = array($args);
                    }
                    break;
                case 'lrem':
                    $args = array($args[0], $args[2], $args[1]);
                    break;
                case 'eval':
                case 'evalsha':
                    if (isset($args[1]) && is_array($args[1])) {
                        $cKeys = $args[1];
                    } elseif (isset($args[1]) && is_string($args[1])) {
                        $cKeys = array($args[1]);
                    } else {
                        $cKeys = array();
                    }


                    if (isset($args[2]) && is_array($args[2])) {
                        $cArgs = $args[2];
                    } elseif (isset($args[2]) && is_string($args[2])) {
                        $cArgs = array($args[2]);
                    } else {
                        $cArgs = array();
                    }
                    $args = array($args[0], array_merge($cKeys, $cArgs), count($cKeys));
                    break;
                case 'subscribe':
                case 'psubscribe':
                    break;
                case 'scan':
                case 'sscan':
                case 'hscan':
                case 'zscan':
                    // allow phpredis to see the caller's reference
                    //$param_ref =& $args[0];
                    break;
                default:
                    // Flatten arguments
                    $args = self::_flattenArguments($args);
            }

            try {
                // Proxy pipeline mode to the phpredis library
                if ($name === 'pipeline' || $name === 'multi') {
                    if ($this->isMulti) {
                        return $this;
                    }

                    $this->isMulti = TRUE;
                    $this->redisMulti = call_user_func_array(array($this->redis, $name), $args);
                    return $this;
                }

                if ($name === 'exec' || $name === 'discard') {
                    $this->isMulti = FALSE;
                    $response = $this->redisMulti->$name();
                    $this->redisMulti = NULL;
                    return $response;
                }

                // Use aliases to be compatible with phpredis wrapper
                if (isset($this->wrapperMethods[$name])) {
                    $name = $this->wrapperMethods[$name];
                }

                // Multi and pipeline return self for chaining
                if ($this->isMulti) {
                    call_user_func_array(array($this->redisMulti, $name), $args);
                    return $this;
                }

                // Send request, retry one time when using persistent connections on the first request only
                $this->requests++;
                try {
                    $response = call_user_func_array(array($this->redis, $name), $args);
                } catch (RedisException $e) {
                    if ($this->persistent && $this->requests == 1 && $e->getMessage() === 'read error on connection') {
                        $this->close(true);
                        $this->connect();
                        $response = call_user_func_array(array($this->redis, $name), $args);
                    } else {
                        throw $e;
                    }
                }
            } // Wrap exceptions
            catch (RedisException $e) {
                $code = 0;
                if (!($result = $this->redis->IsConnected())) {
                    $this->close(true);
                    $code = RedisClientException::CODE_DISCONNECTED;
                }
                throw new RedisClientException($e->getMessage(), $code, $e);
            }

            #echo "> $name : ".substr(print_r($response, TRUE),0,100)."\n";

            // change return values where it is too difficult to minim in standalone mode
            switch ($name) {
                case 'type':
                    $typeMap = array(
                        self::TYPE_NONE,
                        self::TYPE_STRING,
                        self::TYPE_SET,
                        self::TYPE_LIST,
                        self::TYPE_ZSET,
                        self::TYPE_HASH,
                    );
                    $response = $typeMap[$response];
                    break;

                // Handle scripting errors
                case 'eval':
                case 'evalsha':
                case 'script':
                    $error = $this->redis->getLastError();
                    $this->redis->clearLastError();
                    if ($error && substr($error, 0, 8) === 'NOSCRIPT') {
                        $response = NULL;
                    } else if ($error) {
                        throw new RedisClientException($error);
                    }
                    break;
                case 'exists':
                    // smooth over phpredis-v4 vs earlier difference to match documented credis return results
                    $response = (int)$response;
                    break;
                case 'ping':
                    if ($response) {
                        if ($response === true) {
                            $response = isset($args[0]) ? $args[0] : "PONG";
                        } else if ($response[0] === '+') {
                            $response = substr($response, 1);
                        }
                    }
                    break;
                case 'auth':
                    if (is_bool($response) && $response === true) {
                        $this->redis->clearLastError();
                    }
                default:
                    $error = $this->redis->getLastError();
                    $this->redis->clearLastError();
                    if ($error) {
                        throw new RedisClientException(rtrim($error));
                    }
                    break;
            }
        }

        return $response;
    }

    protected function write_command($command): void
    {
        // Reconnect on lost connection (Redis server "timeout" exceeded since last command)
        if ( feof($this->redis) ) {
            // If a watch or transaction was in progress and connection was lost, throw error rather than reconnect
            // since transaction/watch state will be lost.
            if (($this->isMulti && !$this->usePipeline) || $this->isWatching) {
                $this->close(true);
                throw new RedisClientException('Lost connection to Redis server during watch or transaction.');
            }
            $this->close(true);
            $this->connect();
            if ($this->authPassword) {
                $this->auth($this->authPassword);
            }
            if ($this->database != 0) {
                $this->select($this->database);
            }
        }

        $commandLen = strlen($command);
        $lastFailed = FALSE;
        for ($written = 0; $written < $commandLen; $written += $fwrite) {
            $fwrite = fwrite($this->redis, substr($command, $written));
            if ($fwrite === FALSE || ($fwrite == 0 && $lastFailed)) {
                $this->close(true);
                throw new RedisClientException('Failed to write entire command to stream');
            }
            $lastFailed = $fwrite == 0;
        }
    }

    protected function read_reply($name = '', $returnQueued = false)
    {
        $reply = fgets($this->redis);
        if ($reply === FALSE) {
            $info = stream_get_meta_data($this->redis);
            $this->close(true);
            if ($info['timed_out']) {
                throw new RedisClientException('Read operation timed out.', RedisClientException::CODE_TIMED_OUT);
            }

            throw new RedisClientException('Lost connection to Redis server.', RedisClientException::CODE_DISCONNECTED);
        }
        $reply = rtrim($reply, CRLF);
        $replyType = $reply[0];
        switch ($replyType) {
            /* Error reply */
            case '-':
                if ($this->isMulti || $this->usePipeline) {
                    $response = FALSE;
                } else if ($name === 'evalsha' && substr($reply, 0, 9) === '-NOSCRIPT') {
                    $response = NULL;
                } else {
                    throw new RedisClientException(substr($reply, 0, 4) === '-ERR' ? 'ERR ' . substr($reply, 5) : substr($reply, 1));
                }
                break;
            /* Inline reply */
            case '+':
                $response = substr($reply, 1);
                if ($response === 'OK') {
                    return TRUE;
                }
                if ($response === 'QUEUED') {
                    return $returnQueued ? null : true;
                }
                break;
            /* Bulk reply */
            case '$':
                if ($reply === '$-1') return FALSE;
                $size = (int)substr($reply, 1);
                $response = stream_get_contents($this->redis, $size + 2);
                if (!$response) {
                    $this->close(true);
                    throw new RedisClientException('Error reading reply.');
                }
                $response = substr($response, 0, $size);
                break;
            /* Multi-bulk reply */
            case '*':
                $count = substr($reply, 1);
                if ($count == '-1') return FALSE;

                $response = array();
                for ($i = 0; $i < $count; $i++) {
                    $response[] = $this->read_reply();
                }
                break;
            /* Integer reply */
            case ':':
                $response = (int)substr($reply, 1);
                break;
            default:
                throw new RedisClientException('Invalid response: ' . print_r($reply, TRUE));
                break;
        }

        return $response;
    }

    protected function decode_reply($name, $response, array &$arguments = array())
    {
        // Smooth over differences between phpredis and standalone response
        switch ($name) {
            case '': // Minor optimization for multi-bulk replies
                break;
            case 'config':
            case 'hgetall':
                $keys = $values = array();
                while ($response) {
                    $keys[] = array_shift($response);
                    $values[] = array_shift($response);
                }
                $response = count($keys) ? array_combine($keys, $values) : array();
                break;
            case 'info':
                $lines = explode(CRLF, trim($response, CRLF));
                $response = array();
                foreach ($lines as $line) {
                    if (!$line || $line[0] === '#') {
                        continue;
                    }
                    list($key, $value) = explode(':', $line, 2);
                    $response[$key] = $value;
                }
                break;
            case 'ttl':
                if ($response === -1) {
                    $response = false;
                }
                break;
            case 'hmget':
                if (count($arguments) != count($response)) {
                    throw new RedisClientException(
                        'hmget arguments and response do not match: ' . print_r($arguments, true) . ' ' . print_r(
                            $response, true
                        )
                    );
                }
                // rehydrate results into key => value form
                $response = array_combine($arguments, $response);
                break;

            case 'scan':
            case 'sscan':
                $arguments[0] = (int)array_shift($response);
                $response = empty($response[0]) ? array() : $response[0];
                break;
            case 'hscan':
            case 'zscan':
                $arguments[0] = (int)array_shift($response);
                $response = empty($response[0]) ? array() : $response[0];
                if (!empty($response) && is_array($response)) {
                    $count = count($response);
                    $out = array();
                    for ($i = 0; $i < $count; $i += 2) {
                        $out[$response[$i]] = $response[$i + 1];
                    }
                    $response = $out;
                }
                break;
            case 'zrangebyscore':
            case 'zrevrangebyscore':
            case 'zrange':
            case 'zrevrange':
                if (in_array('withscores', $arguments, true)) {
                    // Map array of values into key=>score list like phpRedis does
                    $item = null;
                    $out = array();
                    foreach ($response as $value) {
                        if ($item == null) {
                            $item = $value;
                        } else {
                            // 2nd value is the score
                            $out[$item] = (float)$value;
                            $item = null;
                        }
                    }
                    $response = $out;
                }
                break;
        }

        return $response;
    }

    /**
     * Build the Redis unified protocol command
     *
     * @param array $args
     * @return string
     */
    private static function _prepare_command($args): string
    {
        return sprintf('*%d%s%s%s', count($args), CRLF, implode(CRLF, array_map(array('self', '_map'), $args)), CRLF);
    }

    private static function _map($arg): string
    {
        return sprintf('$%d%s%s', strlen($arg), CRLF, $arg);
    }
    
    /**
     * Flatten arguments
     *
     * If an argument is an array, the key is inserted as argument followed by the array values
     *  array('zrangebyscore', '-inf', 123, array('limit' => array('0', '1')))
     * becomes
     *  array('zrangebyscore', '-inf', 123, 'limit', '0', '1')
     *
     * @param array $arguments
     * @param array $out
     * @return array
     */
    private static function _flattenArguments(array $arguments, &$out = array()): array
    {
        foreach ($arguments as $key => $arg) {
            if (!is_int($key)) {
                $out[] = $key;
            }

            if (is_array($arg)) {
                self::_flattenArguments($arg, $out);
            } else {
                $out[] = $arg;
            }
        }

        return $out;
    }

}

# -eof-
