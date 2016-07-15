<?php

class Giphy {

	protected $logger;
	public $api_key;
	public $rating = 'g'; // y,g, pg, pg-13 or r
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
		if (!empty($config['api_key'])) $this->api_key = $config['api_key'];
		else $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: api_key");
		if (!empty($config['rating'])) $this->rating = $config['rating'];
		else $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: rating");
	}

	public function translate($query) {

		if (empty($this->api_key)) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing class parameter: api_key");
			return false;
		}
		if (strlen($query) == 0) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing function parameter: query");
			return false;
		}
		$url = 
			"http://api.giphy.com/v1/gifs/translate?".
			"api_key=".$this->api_key."&".
			"rating=".$this->rating."&".
			"s=".urlencode($query);

		$curl = new Curl($this->logger);
      $curl->method = 'GET';
      $curl->response_type = 'json';
      $curl->url = $url;
      $curl->success_http_code = '200';
      $curl->caller = __FILE__.': '.__METHOD__;
      if (empty($result = $curl->request())) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": didn't get anything back from translate");
			return false;
		}
		return $result['data']['images']['fixed_height']['url'];

	}

	public function search($query, $limit = 3, $offset = 0) {

		if (empty($this->api_key)) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing class parameter: api_key");
			return false;
		}
		if (strlen($query) == 0) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing function parameter: query");
			return false;
		}
		$url = 
			"http://api.giphy.com/v1/gifs/search?".
			"api_key=".$this->api_key."&".
			"rating=".$this->rating."&".
			"limit=".$limit."&".
			"offset=".$offset."&".
			"q=".urlencode($query);

		$curl = new Curl($this->logger);
      $curl->method = 'GET';
      $curl->response_type = 'json';
      $curl->url = $url;
      $curl->success_http_code = '200';
      $curl->caller = __FILE__.': '.__METHOD__;
      if (empty($result = $curl->request())) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": didn't get anything back from search");
			return false;
		}
		return $result;

	}

}
