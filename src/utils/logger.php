<?php
// src/utils/logger.php
// Lightweight logger wrapper: uses Monolog when available otherwise falls back to a simple JSON-line file logger.
use Monolog\Logger as MonologLogger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\JsonFormatter;

if (!function_exists('app_logger')) {
  function app_logger(string $name = 'app') {
    static $instances = [];
    if (isset($instances[$name])) return $instances[$name];
    try {
      if (!class_exists(MonologLogger::class)) throw new \Exception('monolog not installed');
      $projectRoot = realpath(__DIR__ . '/../../') ?: __DIR__ . '/../../';
      $logDir = rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . 'logs';
      if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
      $handler = new RotatingFileHandler($logDir . DIRECTORY_SEPARATOR . 'app.log', 30, MonologLogger::DEBUG);
      $formatter = new JsonFormatter();
      $formatter->includeStacktraces(true);
      $handler->setFormatter($formatter);
      $logger = new MonologLogger($name);
      $logger->pushHandler($handler);
      $instances[$name] = $logger;
      return $logger;
    } catch (Throwable $e) {
      // fallback simple logger
      $projectRoot = realpath(__DIR__ . '/../../') ?: __DIR__ . '/../../';
      $file = rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'app.log';
      if (!is_dir(dirname($file))) @mkdir(dirname($file), 0755, true);
      $fallback = new class($file, $name) {
        private $file; private $name;
        public function __construct($file, $name){ $this->file = $file; $this->name = $name; }
        private function write($level, $message, $context=[]){ $entry = ['ts'=>gmdate('c'),'level'=>$level,'logger'=>$this->name,'message'=>$message,'context'=>$context]; @file_put_contents($this->file, json_encode($entry, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND|LOCK_EX); }
        public function info($m,$c=[]){ $this->write('info',$m,$c); }
        public function error($m,$c=[]){ $this->write('error',$m,$c); }
        public function warning($m,$c=[]){ $this->write('warning',$m,$c); }
        public function debug($m,$c=[]){ $this->write('debug',$m,$c); }
      };
      $instances[$name] = $fallback;
      return $fallback;
    }
  }
}

if (!function_exists('audit_event')) {
  function audit_event(PDO $pdo, string $level, string $category, ?string $actor_type = null, $actor_id = null, ?string $message = null, $payload = null) {
    try {
      require_once __DIR__ . '/client_ip.php';
      $ip = get_client_ip();
      $payloadJson = null;
      if ($payload !== null) {
        if (is_string($payload)) $payloadJson = $payload; else $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
      }
      $st = $pdo->prepare('INSERT INTO system_audit (level, category, actor_type, actor_id, ip, message, payload) VALUES (?, ?, ?, ?, ?, ?, ?)');
      $st->execute([$level, $category, $actor_type, $actor_id, $ip, $message, $payloadJson]);
    } catch (Throwable $e) {
      // ignore if table missing or insert fails; log to fallback logger
      try { app_logger()->error('audit_event failed: '.$e->getMessage(), ['level'=>$level,'category'=>$category]); } catch (Throwable $_) { /* ignore */ }
    }
  }
}
// <?php
// src/utils/logger.php
// Simple application logger writing to config/uploads/logs/YYYY-MM-DD.log

if (!function_exists('app_log_safe')) {
    function app_log_safe(string $category, string $message, array $context = []): void {
        try {
            app_logger($category)->error($message, $context);
        } catch (Throwable $e) {
            @error_log('[app_log_safe] ' . $category . ': ' . $message . ' | ' . $e->getMessage());
        }
    }
}

if (!function_exists('app_log')) {
    function app_log(string $category, string $message, array $context = []): void {
        try {
            $date = new DateTime('now');
            $day = $date->format('Y-m-d');
            $time = $date->format('Y-m-d H:i:s');
            // Determine base config path preference: external mount, then project config, then src/uploads
            $candidates = [
                ['/var/www/config/uploads/logs', true],
                [__DIR__ . '/../config/../../config/uploads/logs', false], // resolve to project config/uploads/logs
                [__DIR__ . '/../uploads/logs', false],
            ];
            $logDir = null;
            foreach ($candidates as [$p, $ensure]) {
                $full = realpath(dirname($p)) !== false && strpos($p, '..') === false ? $p : $p; // keep as-is; we will mkdir as needed
                if (!is_dir($full)) {
                    @mkdir($full, 0775, true);
                }
                if (is_dir($full) && is_writable($full)) { $logDir = $full; break; }
            }
            if ($logDir === null) { return; }
            $file = $logDir . DIRECTORY_SEPARATOR . $day . '.log';
            $ip = $_SERVER['REMOTE_ADDR'] ?? '-';
            $uid = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : 0;
            $ctx = $context ? json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
            $line = sprintf("[%s] [%s] [uid:%s] [ip:%s] %s%s\n", $time, $category, $uid ?: '-', $ip, $message, $ctx ? (' | ' . $ctx) : '');
            @file_put_contents($file, $line, FILE_APPEND);
        } catch (Throwable $e) {
            // swallow logging errors
        }
    }
}
