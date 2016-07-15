<?php

class Extensions {
	protected $logger;
	protected $config_dir;
	protected $storage;
	public function __construct($logger = null, $config_dir = null, $extensions = array(), $storage = null) {
		$this->logger = $logger;
		$this->storage = $storage;
		if (empty($config_dir)) {
			$logger->addWarning(__FILE__.": ".__METHOD__.": no config dir provided");
			return false;
		}
		if (empty($extensions)) {
			$logger->addWarning(__FILE__.": ".__METHOD__.": no extensions provided");
			return false;
		}
		foreach ($extensions as $extension => $extension_enabled) {
			if ($extension_enabled != '1') continue;
			$logger->addInfo(__FILE__.": ".__METHOD__.": loading extension: $extension");
			$extension_file = __DIR__."/../extensions/$extension/$extension.php";
			if (file_exists($extension_file)) {
				require_once $extension_file;
				if ($this->$extension = new $extension($logger, "$config_dir/$extension.conf", $storage))
					$logger->addInfo(__FILE__.": ".__METHOD__.": loaded extension: $extension");
				else $logger->addInfo(__FILE__.": ".__METHOD__.": failed to load extension: $extension");
			} else
				$logger->addError(__FILE__.": ".__METHOD__.": extension defined in config, but autoload file doesn't exist: $extension_file");
		}
	}
}

?>
