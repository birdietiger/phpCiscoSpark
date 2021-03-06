<?php

class SparkHelperLogger {

	public function use_monolog($config = null) {

		//require composer autoload to get monolog running
		if (file_exists(__DIR__ . '/vendor/autoload.php'))
			require __DIR__ . '/vendor/autoload.php';
		else {
			echo "ERROR: monolog autoload file is missing. starting basic logging to keep things moving forward.\n";
			return $this->use_basic($config);
		}

		$log_file_level = (!empty($config['file_level'])) ? $config['file_level'] : 'ERROR';
		$log_stdout_level = (!empty($config['stdout_level'])) ? $config['stdout_level'] : 'ERROR';
		$logger = new \Monolog\Logger(basename(__FILE__));
		$log_format = "[%datetime%] ".uniqid()." %channel%.%level_name%: %message% %context% %extra%\n";
		$log_formatter = new \Monolog\Formatter\LineFormatter($log_format);
		if (!empty($config['file'])) {
			$log_file_handler = new \Monolog\Handler\StreamHandler($config['file'], constant('\Monolog\Logger::'.$log_file_level));
			$log_file_handler->setFormatter($log_formatter);
			$logger->pushHandler($log_file_handler);
		}
		if (isset($config['stdout']) && $config['stdout']) {
			$log_stdout_handler = new \Monolog\Handler\StreamHandler('php://stdout', constant('\Monolog\Logger::'.$log_stdout_level));
			$log_stdout_handler->setFormatter($log_formatter);
			$logger->pushHandler($log_stdout_handler);
		}
		return $logger;

	}

	public function use_basic($config = null) {
	
		return new \BasicLogger($config);

	}

}

class BasicLogger {

	public $config = array();
	public $stdout = true;
	protected $stdout_level = 'ERROR';
	protected $file = '';
	protected $file_level = 'ERROR';
	protected $uniqid;
	protected $levels = array(
		'DEBUG' => 0,
		'INFO' => 1,
		'NOTICE' => 2,
		'WARNING' => 3,
		'ERROR' => 4,
		'CRITICAL' => 5,
		'ALERT' => 6,
		'EMERGENCY' => 7,
		);

	public function __construct($config = null) {
		$this->config = $config;
		$this->uniqid = uniqid();
		if (isset($config['stdout']) && !$config['stdout']) $this->stdout = false;
		if (!empty($config['stdout_level']) && isset($this->levels[$config['stdout_level']])) $this->stdout_level = $config['stdout_level'];
		if (!empty($config['file'])) {
			if (!file_exists($config['file'])) {
				if (!touch($config['file']))
					echo $this->format_log('ALERT', "Can't write to ".$config['file']);
				else
					$this->file = $config['file'];
			} else {
				if (!is_writable($config['file']))
					echo $this->format_log('ALERT', "Can't write to ".$config['file']);
				else
					$this->file = $config['file'];
			}
		}
		if (!empty($config['file_level']) && isset($this->levels[$config['file_level']])) $this->file_level = $config['file_level'];
	}

	protected function format_log($level, $message) {

		$time = microtime(true);
		$microtime = sprintf("%06d",($time - floor($time)) * 1000000);
		$date = new DateTime( date('Y-m-d H:i:s.'.$microtime, $time) );
		$timestamp = $date->format("Y-m-d H:i:s.u");
	 	return $timestamp." ".$this->uniqid." ".$level.": ".$message."\n";

	}

	protected function write_log($level, $message) {

		if ($this->stdout) {
			if ($this->levels[$level] >= $this->levels[$this->stdout_level])
				echo $this->format_log($level, $message);
		}

		if (!empty($this->file)) {
			if ($this->levels[$level] >= $this->levels[$this->file_level])
				file_put_contents($this->file, $this->format_log($level, $message), FILE_APPEND);
		}

	}

	public function addDebug($message) {
		$level = 'DEBUG';
		$this->write_log($level, $message);
	}

	public function addInfo($message) {
		$level = 'INFO';
		$this->write_log($level, $message);
	}

	public function addNotice($message) {
		$level = 'NOTICE';
		$this->write_log($level, $message);
	}

	public function addWarning($message) {
		$level = 'WARNING';
		$this->write_log($level, $message);
	}

	public function addError($message) {
		$level = 'ERROR';
		$this->write_log($level, $message);
	}

	public function addCritical($message) {
		$level = 'CRITICAL';
		$this->write_log($level, $message);
	}

	public function addAlert($message) {
		$level = 'ALERT';
		$this->write_log($level, $message);
	}

	public function addEmergency($message) {
		$level = 'EMERGENCY';
		$this->write_log($level, $message);
	}

}

?>
