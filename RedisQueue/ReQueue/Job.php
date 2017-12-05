<?php
/**
 * Created by IntelliJ IDEA.
 * User: yongLi
 * Date: 16/10/01
 * Time: 11:28
 * Email: liyong@addnewer.com
 */
namespace RedisQueue\ReQueue;

use RedisQueue\ReQueue\Job\Status;
use RedisQueue\ReQueue\Job\DontPerform;
use RedisQueue\ResQueue;
use InvalidArgumentException;

/**
 * Class Job
 *
 * @package RedisQueue\ReQueue
 * @author  yongli <liyong@addnewer.com>
 */
class Job
{
    /**
     * @var string The name of the queue that this job belongs to.
     */
    public $queue;

    /**
     * @var ResQueue_Worker Instance of the ResQueue worker running this job.
     */
    public $worker;

    /**
     * @var object Object containing details of the job.
     */
    public $payload;

    /**
     * @var object Instance of the class performing work for this job.
     */
    private $instance;

    /**
     * Instantiate a new instance of a job.
     *
     * @param string $queue   The queue that the job belongs to.
     * @param array  $payload array containing details of the job.
     */
    public function __construct($queue, $payload)
    {
        $this->queue   = $queue;
        $this->payload = $payload;
    }

    /**
     * Create a new job and save it to the specified queue.
     *
     * @param string  $queue   The name of the queue to place the job in.
     * @param string  $class   The name of the class that contains the code to execute the job.
     * @param array   $args    Any optional arguments that should be passed when the job is executed.
     * @param boolean $monitor Set to true to be able to monitor the status of a job.
     *
     * @return string
     */
    public static function create($queue, $class, $args = null, $monitor = false)
    {
        if ($args !== null && !is_array($args)) {
            throw new InvalidArgumentException('Supplied $args must be an array.');
        }
        $id = md5(uniqid('', true));
        ResQueue::push($queue, [
            'class' => $class,
            'args'  => [$args],
            'id'    => $id,
        ]);
        if ($monitor) {
            Status::create($id);
        }

        return $id;
    }

    /**
     * Find the next available job from the specified queue and return an
     * instance of Job for it.
     *
     * @param string $queue The name of the queue to check for a job in.
     *
     * @return null|object Null when there aren't any waiting jobs, instance of ResQueue_Job when a job was found.
     */
    public static function reserve($queue)
    {
        $payload = ResQueue::pop($queue);
        if (!is_array($payload)) {
            return false;
        }

        return new Job($queue, $payload);
    }

    /**
     * Find the next available job from the specified queues using blocking list pop
     * and return an instance of ResQueue_Job for it.
     *
     * @param array $queues
     * @param int   $timeout
     *
     * @return false|object Null when there aren't any waiting jobs, instance of Resque_Job when a job was found.
     */
    public static function reserveBlocking(array $queues, $timeout = null)
    {
        $item = ResQueue::blpop($queues, $timeout);
        if (!is_array($item)) {
            return false;
        }

        return new Job($item['queue'], $item['payload']);
    }

    /**
     * Update the status of the current job.
     *
     * @param int $status Status constant from redisQueue_Job_Status indicating the current status of a job.
     */
    public function updateStatus($status)
    {
        if (empty($this->payload['id'])) {
            return;
        }
        $statusInstance = new Status($this->payload['id']);
        $statusInstance->update($status);
    }

    /**
     * Return the status of the current job.
     *
     * @return int The status of the job as one of the ResQueue_Job_Status constants.
     */
    public function getStatus()
    {
        $status = new Status($this->payload['id']);

        return $status->get();
    }

    /**
     * Get the arguments supplied to this job.
     *
     * @return array Array of arguments.
     */
    public function getArguments()
    {
        if (!isset($this->payload['args'])) {
            return [];
        }

        return $this->payload['args'][0];
    }

    /**
     * @return object Get the instantiated object for this job that will be performing work.
     *
     * @throws \RedisQueue\ReQueue\QueueException
     */
    public function getInstance()
    {
        if (!is_null($this->instance)) {
            return $this->instance;
        }
        $class = ucfirst($this->payload['class']);
        if (!class_exists($class)) {
            require $_SERVER['JOBPATH'] . $class . '.php';
        }
        if (!class_exists($class)) {
            throw new QueueException('Could not find job class ' . $class . '.');
        }
        if (!method_exists($class, 'perform')) {
            throw new QueueException('Job class ' . $class . ' does not contain a perform method.');
        }
        $this->instance        = new $class();
        $this->instance->job   = $this;
        $this->instance->args  = $this->getArguments();
        $this->instance->queue = $this->queue;

        return $this->instance;
    }

    /**
     * Actually execute a job by calling the perform method on the class
     * associated with the job with the supplied arguments.
     *
     * @return bool
     * @throws ResQueue_Exception When the job's class could not be found or it does not contain a perform method.
     */
    public function perform()
    {
        $instance = $this->getInstance();
        try {
            Event::trigger('beforePerform', $this);
            if (method_exists($instance, 'setUp')) {
                $instance->setUp();
            }
            $instance->perform();
            if (method_exists($instance, 'tearDown')) {
                $instance->tearDown();
            }
            Event::trigger('afterPerform', $this);
        } // beforePerform/setUp have said don't perform this job. Return.
        catch (DontPerform $e) {
            return false;
        }

        return true;
    }

    /**
     * Mark the current job as having failed.
     *
     * @param $exception
     */
    public function fail($exception)
    {
        Event::trigger('onFailure', [
            'exception' => $exception,
            'job'       => $this,
        ]);
        $this->updateStatus(Status::STATUS_FAILED);
        Failure::create($this->payload, $exception, $this->worker, $this->queue);
        Stat::incr('failed');
        Stat::incr('failed:' . $this->worker);
    }

    /**
     * Re-queue the current job.
     * @return string
     */
    public function recreate()
    {
        $status  = new Status($this->payload['id']);
        $monitor = false;
        if ($status->isTracking()) {
            $monitor = true;
        }

        return self::create($this->queue, $this->payload['class'], $this->payload['args'], $monitor);
    }

    /**
     * Generate a string representation used to describe the current job.
     *
     * @return string The string representation of the job.
     */
    public function __toString()
    {
        $name = [
            'Job{' . $this->queue . '}'
        ];
        if (!empty($this->payload['id'])) {
            $name[] = 'ID: ' . $this->payload['id'];
        }
        $name[] = $this->payload['class'];
        if (!empty($this->payload['args'])) {
            $name[] = json_encode($this->payload['args']);
        }

        return '(' . implode(' | ', $name) . ')';
    }
}