<?php

class Bing {

	protected $logger;
	public $account_key;
	public $config_file;

	public function __construct($logger, $config_file = null) {
		$this->config_file = $config_file;
		$this->logger = $logger;
		if (!class_exists('Curl')) {
         $this->logger->addCritical(__FILE__.": ".__METHOD__.": Curl class is missing. make sure to include Curl handler");
         exit();
      }
		$this->load_config();
	}

	public function load_config() {
		if (is_file($this->config_file)) $config = parse_ini_file($this->config_file, true);
		else $this->logger->addWarning(__FILE__.": ".__METHOD__.": config file doesn't exist");
		if (!empty($config['account_key'])) $this->account_key = $config['account_key'];
		else $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: account_key");
	}

	public function search($query, $top = 3) {

		if (empty($this->account_key)) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing class parameter: account_key");
			return false;
		}
		if (strlen($query) == 0) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing function parameter: query");
			return false;
		}
		$url = 
			"https://api.datamarket.azure.com/Bing/Search/Web?".
			"\$format=json&".
			"\$top=$top&".
			"Query='".urlencode($query)."'";

		$curl = new Curl($this->logger);
      $curl->method = 'GET';
		$curl->user_pwd = $this->account_key .':'.$this->account_key;
      $curl->response_type = 'json';
      $curl->url = $url;
      $curl->success_http_code = '200';
      $curl->caller = __FILE__.': '.__METHOD__;
      if (empty($result = $curl->request())) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": didn't get anything back from search");
			return false;
		}
		return $result['d']['results'];

	}

}
