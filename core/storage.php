<?php

class Storage {

	protected $logger;
	protected $config;
	protected $file;
	protected $original_perm;
	protected $original_temp;
	public $perm = array();
	public $mqtt = array();
	public $temp = array();

   public function __construct($logger = null, $config = null) {
		$this->logger = $logger;
		$this->config = $config;
		$this->set_variables();
		$this->load();
   }

   protected function set_variables() {

      if (empty($this->config['file'])) $this->logger->addCritical(__FILE__.": ".__METHOD__.": !!!NO PERM DATA WILL BE SAVED!!! missing configuration parameter: file");
      else {
			if (!file_exists($this->config['file'])) {
				if (!touch($this->config['file']))
					$this->logger->addCritical(__FILE__.": ".__METHOD__.": !!!NO PERM DATA WILL BE SAVED!!! Can't write to ".$this->config['file']);
				else
					$this->file = $this->config['file'];
			} else {
				if (!is_writable($this->config['file']))
					$this->logger->addCritical(__FILE__.": ".__METHOD__.": !!!NO PERM DATA WILL BE SAVED!!! Can't write to ".$this->config['file']);
				else
					$this->file = $this->config['file'];
			}
		}

   }

	public function load() {
		if (empty($this->file)) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": missing class parameter: file");
			return false;
		}
		if (empty($perm_json = file_get_contents($this->file))) {
			$this->logger->addWarning(__FILE__.": ".__METHOD__.": couldn't get perm file contents or is empty: ".$this->file);
			return false;
		}
		if (empty($this->perm = json_decode($perm_json, true))) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": perm file is not proper json: ".$this->file);
			return false;
		}
		return true;
	}

	public function save() {
		if (empty($this->file)) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": missing class parameter: file");
			return false;
		}
		if (empty($this->perm)) {
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": no data to save");
			return true;
		}
		if (empty($perm_json = json_encode($this->perm, JSON_PRETTY_PRINT))) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": failed to encode perm data");
			return false;
		}
		if (empty(file_put_contents($this->file, $perm_json))) {
			$this->logger->addWarning(__FILE__.": ".__METHOD__.": couldn't write perm file contents: ".$this->file);
			return false;
		}
		return true;
	}

	public function clear($type) {
		if (!in_array($type, array('mqtt', 'temp', 'perm'))) {
			$this->logger->addWarning(__FILE__.": ".__METHOD__.": invalid storage type: $type");
			return false;
		}
		$this->logger->addInfo(__FILE__.": ".__METHOD__.": clearing storage data type: $type");
		$this->$type = array();
		if ($type == 'perm') {
			if (empty($this->file)) {
				$this->logger->addError(__FILE__.": ".__METHOD__.": missing class parameter: file");
				return false;
			}
			if (!unlink($this->file)) {
				$this->logger->addWarning(__FILE__.": ".__METHOD__.": can't delete perm file: ".$this->file);
				return false;
			}
		}
		return true;
	}

}

?>
