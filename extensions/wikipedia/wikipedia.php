<?php

class Wikipedia {

	protected $logger;
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
	}

	public function search($query, $limit = 3) {

		$query = str_replace(' ', '_', $query);

		if (strlen($query) == 0) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing function parameter: query");
			return false;
		}
		$url = 
			"https://en.wikipedia.org/w/api.php?".
			"format=json&".
			"action=opensearch&".
			"limit=".$limit."&".
			"search=".urlencode($query);

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
		$items = array();
		foreach ($result[1] as $key => $title) {
			$items[] = array(
				'title' => $title,
				'abstract' => $result[2][$key],
				'url' => $result[3][$key],
				);
		}
		return $items;

	}

}
