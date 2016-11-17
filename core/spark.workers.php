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

		$this->logger = new \BasicLogger($logger->config);
		unset($logger);

		$this->callback = $callback;
		foreach ($spark->config['extensions'] as $extension => $extension_state) {
			if (!empty($extension_state)) {
				if (isset($this->storage->$extension))
					$this->$extension = $this->storage->$extension;
				else
					$this->$extension = [];
			}
		}
		$this->spark = clone $spark;
		unset($spark);
		$this->storage = $storage;
		$this->storage_perm_orig = $this->storage->perm;
		$this->storage_temp_orig = $this->storage->temp;

		$this->extensions = $extensions;
		unset($extensions);
		$this->details = $details;
		unset($details);

		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));

	}

	public function run() {

      $function_start = \function_start();

		$spark = clone $this->spark;
		unset($this->spark);
		$spark->curl = new Curl($this->logger);

		$storage = clone $this->storage;
		unset($this->storage);

		$extensions = clone $this->extensions;
		unset($this->extensions);
		$extensions->http = new Curl($this->logger);


		if (is_array($this->callback) && is_object($this->callback[0]))
			$return = &$this->callback[0]->{$this->callback[1]}($spark, $this->logger, $storage, $extensions, $this->details);
		else {
			$callback = &$this->callback;
			$return = $callback($spark, $this->logger, $storage, $extensions, $this->details);
		}
		if (!empty($return)) $storage = $return;

		foreach ($spark->config['extensions'] as $extension => $extension_state) {
			if (isset($this->$extension)) {
				if (!isset($extensions->$extension->storage->$extension)) $extensions->$extension->storage->$extension = [];
				$extension_diff = \array_diff_assoc_recursive($extensions->$extension->storage->$extension, $this->$extension);
				if (!isset($storage->$extension)) $storage->$extension = [];
				$storage->$extension = array_replace_recursive($storage->$extension, $extension_diff);
				unset($this->$extension);
			}
		}

		$this->storage = $storage;
		unset($storage);

		unset($spark);
		unset($this->details);
		unset($this->callback);

      $this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		unset($this->logger);

		$this->setGarbage(); // do this last incase garbage is collected before we can log function end

	}

}

?>
