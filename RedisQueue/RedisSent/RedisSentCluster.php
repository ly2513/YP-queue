<?php
/**
 * Redisent, a Redis interface for the modest
 * Created by IntelliJ IDEA.
 * User: yongli
 * Date: 16/9/28
 * Time: 下午1:04
 * Email: liyong@addnewer.com
 */
namespace RedisQueue\RedisSent;

use Exception;

/**
 * A generalized RedisSent interface for a cluster of Redis servers
 */
class RedisSentCluster
{

    /**
     * Collection of RedisSent objects attached to Redis servers
     * @var array
     * @access private
     */
    private $redisSent = [];

    /**
     * Aliases of RedisSent objects attached to Redis servers, used to route commands to specific servers
     * @see    RedisentCluster::to
     * @var array
     * @access private
     */
    private $aliases = [];

    /**
     * Hash ring of Redis server nodes
     * @var array
     * @access private
     */
    private $ring = [];

    /**
     * Individual nodes of pointers to Redis servers on the hash ring
     * @var array
     * @access private
     */
    private $nodes = [];

    /**
     * Number of replicas of each node to make around the hash ring
     * @var integer
     * @access private
     */
    private $replicas = 128;

    /**
     * The commands that are not subject to hashing
     * @var array
     * @access private
     */
    private $dont_hash = [
        'RANDOMKEY',
        'DBSIZE',
        'SELECT',
        'MOVE',
        'FLUSHDB',
        'FLUSHALL',
        'SAVE',
        'BGSAVE',
        'LASTSAVE',
        'SHUTDOWN',
        'INFO',
        'MONITOR',
        'SLAVEOF'
    ];

    /**
     * Creates a RedisSent interface to a cluster of Redis servers
     *
     * @param array $servers The Redis servers in the cluster. Each server should be in the format array('host' => hostname, 'port' => port)
     */
    public function __construct($servers)
    {
        $this->ring    = [];
        $this->aliases = [];
        foreach ($servers as $alias => $server) {
            $this->redisSent[] = new RedisSent($server['host'], $server['port']);
            if (is_string($alias)) {
                $this->aliases[$alias] = $this->redisSent[count($this->redisSent) - 1];
            }
            for ($replica = 1; $replica <= $this->replicas; $replica++) {
                $this->ring[crc32($server['host'] . ':' . $server['port'] . '-' . $replica)] = $this->redisSent[count($this->redisSent) - 1];
            }
        }
        ksort($this->ring, SORT_NUMERIC);
        $this->nodes = array_keys($this->ring);
    }

    /**
     * Routes a command to a specific Redis server aliased by {$alias}.
     *
     * @param $alias The alias of the Redis server
     *
     * @return mixed The RedisSent object attached to the Redis server
     * @throws Exception
     */
    public function to($alias)
    {
        if (isset($this->aliases[$alias])) {
            return $this->aliases[$alias];
        } else {
            throw new Exception("That RedisSent alias does not exist");
        }
    }

    // Execute a Redis command on the cluster
    public function __call($name, $args)
    {
        // Pick a server node to send the command to
        $name = strtoupper($name);
        if (!in_array($name, $this->dont_hash)) {
            $node      = $this->nextNode(crc32($args[0]));
            $redisSent = $this->ring[$node];
        } else {
            $redisSent = $this->redisSent[0];
        }

        // Execute the command on the server
        return call_user_func_array([$redisSent, $name], $args);
    }

    /**
     * Routes to the proper server node
     *
     * @param $needle
     *
     * @return mixed The RedisSent object associated with the hash
     */
    private function nextNode($needle)
    {
        $haystack = $this->nodes;
        while (count($haystack) > 2) {
            $try = floor(count($haystack) / 2);
            if ($haystack[$try] == $needle) {
                return $needle;
            }
            if ($needle < $haystack[$try]) {
                $haystack = array_slice($haystack, 0, $try + 1);
            }
            if ($needle > $haystack[$try]) {
                $haystack = array_slice($haystack, $try + 1);
            }
        }

        return $haystack[count($haystack) - 1];
    }

}