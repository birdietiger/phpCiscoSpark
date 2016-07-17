<?php

class WolframAlpha {

   protected $logger;
   private $AppID;
   public $format;
   public $config_file;
   public $engine;

   public function __construct($logger, $config_file = null) {
      $this->config_file = $config_file;
      $this->logger = $logger;
      $this->load_config();
		include 'wa_wrapper/WolframAlphaEngine.php';
		$this->engine = new WolframAlphaEngine($this->AppID);
   }

   public function load_config() {
      if (is_file($this->config_file)) $config = parse_ini_file($this->config_file, true);
      else $this->logger->addWarning(__FILE__.": ".__METHOD__.": config file doesn't exist");
      if (!empty($config['AppID'])) $this->AppID = $config['AppID'];
      else $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: AppID");
      if (!empty($config['format'])) $this->format = $config['format'];
      else $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: format");
   }

}

?>
