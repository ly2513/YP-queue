<?php
/**
 * Created by IntelliJ IDEA.
 * User: yongLi
 * Date: 17/1/23
 * Time: 11:28
 * Email: liyong@addnewer.com
 */
namespace RedisQueue\ReQueue;

class Log
{
    public $logPath = null;

    const LOGFILE = null;

    /**
     * 配置日志初始化目录与文件
     *
     * Log constructor.
     *
     */
    public function __construct()
    {
        $this->logPath = $_SERVER['LOGPATH'] ?? '';
        is_dir($this->logPath) or mkdir($this->logPath, 0777, true);
        $this->logFile = $this->logPath . 'queue_' . date('Y-m-d', time()) . '.log';
        is_file($this->logFile) or touch($this->logFile);
    }

    /**
     * 写日志
     *
     * @param     $message
     * @param int $logLevel
     */
    public function writeLog($message, $logLevel = 2)
    {
        if ($logLevel == 1) {
            file_put_contents($this->logFile, $message, FILE_APPEND);
        }
        if ($logLevel == 2) {
            $message = '[' . date('Y-m-d H:i:s', time()) . ']' . $message . PHP_EOL;
            file_put_contents($this->logFile, $message, FILE_APPEND);
        }

    }
}
