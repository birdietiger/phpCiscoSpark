<?php

class WorkerPool extends Pool {

	public function getJobs() {

		return $this->work;

	}

}

class CallbackWorker extends Worker {

	public function __construct() {
	}

	public function run() {
	}

}

class Callback extends Collectable {

	private $callback;
	private $spark;
	private $logger;
	private $storage;
	private $storage_temp_orig;
	private $storage_perm_orig;
	private $extensions;
	private $details;

	public function __construct($callback, $spark, $logger, $storage, $extensions, $details) {

      $function_start = \function_start();

		$this->callback = $callback;
		$this->spark = clone $spark;
		unset($spark);
		$spark_helper_logger = new \SparkHelperLogger();
		$this->logger = $spark_helper_logger->use_basic($logger->config);
		unset($logger);
		$this->storage = $storage;
		$this->storage_perm_orig = $this->storage->perm;
		$this->storage_temp_orig = $this->storage->temp;
		foreach ($this->spark->config['extensions'] as $extension => $extension_state) {
			if (!empty($extension_state)) {
				$this->$extension = $this->storage->$extension;
			}
		}

		$this->extensions = $extensions;
		$this->details = $details;

      $this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));

	}

	public function run() {

      $function_start = \function_start();

		$spark = clone $this->spark;
		unset($this->spark);
		$this->extensions->http = $spark->curl = new Curl($this->logger);

		$this->storage = call_user_func($this->callback, $spark, $this->logger, $this->storage, $this->extensions, $this->details);

		$spark->curl->close();
		unset($spark);
		unset($this->callback);
		unset($this->extensions);

      $this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		unset($this->logger);

		$this->setGarbage(); // do this last incase garbage is collected before we can log function end

	}

}

?>
