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

class Callback extends Threaded {

	private $callback;
	private $garbage = false;
	private $uniqid;
	private $spark;
	private $logger;
	private $storage;
	private $storage_temp_orig;
	private $storage_perm_orig;
	private $extensions;
	private $extensions_orig;
	private $event;

	public function __construct($callback, $spark, $logger, $storage, $extensions, $event) {

		$function_start = \function_start();

		$this->uniqid = uniqid();
		$this->logger = new \BasicLogger($logger->config, $this->uniqid);
		unset($logger);
		$this->callback = $callback;
		unset($callback);
		$this->spark = $spark;
		unset($spark);
		$this->storage = $storage;
		unset($storage);
		$this->storage_perm_orig = $this->storage->perm;
		$this->storage_temp_orig = $this->storage->temp;
		$this->extensions = $extensions;
		unset($extensions);
		$this->event = $event;
		unset($event);

		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));

	}

	public function run() {

      $function_start = \function_start();

		$spark = $this->spark;
		unset($this->spark);
		$spark->curl = new Curl($this->logger);
		$spark->logger = $this->logger;
		// set logger for extension

		$storage = $this->storage;
		unset($this->storage);

		$this->extensions_orig = $this->extensions;
		unset($this->extensions);
		$extensions = $this->extensions_orig;
		$extensions->http = new Curl($this->logger);

		$callback = $this->callback;
		//unset($this->callback);

		if (
			(is_array($callback) && is_object($callback[0]))
			|| (is_object($callback) && is_object($callback[0]))
			)
			$return = $callback[0]->{$callback[1]}($spark, $this->logger, $storage, $extensions, $this->event);
		else {
			$callback_ref = &$callback;
			$return = $callback_ref($spark, $this->logger, $storage, $extensions, $this->event);
		}
		unset($callback);
		if (!empty($return)) $storage = $return;
		unset($return);

		$this->extensions = $extensions;
		unset($extensions);

		$this->storage = $storage;
		unset($storage);

		unset($spark);
		unset($this->event);

      $this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		unset($this->logger);

		$this->garbage = true;

	}

	public function isGarbage() : bool {
		return $this->garbage;
	}

}

?>
