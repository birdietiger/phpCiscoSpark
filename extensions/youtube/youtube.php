<?php

class YouTube {

	protected $logger;
	public $developer_key;
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
		if (!empty($config['developer_key'])) $this->developer_key = $config['developer_key'];
		else $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: developer_key");
	}

	public function search($query, $max_results = 3) {

		if (empty($this->developer_key)) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing class parameter: developer_key");
			return false;
		}
		if (strlen($query) == 0) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing function parameter: query");
			return false;
		}
		$url = 
			"https://www.googleapis.com/youtube/v3/search?".
			"part=snippet&".
			"order=rating&".
			"type=video&".
			"videoDefinition=high&".
			"videoEmbeddable=true&".
			"key=".$this->developer_key."&".
			"q=".urlencode($query)."&".
			"maxResults=".$max_results;

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
		$videos = array();
		foreach ($result['items'] as $item) {
			$videos[$item['id']['videoId']] = array(
				'videoId' => $item['id']['videoId'],
				'url' => 'https://www.youtube.com/watch?v='.$item['id']['videoId'],
				'publishedAt' => $item['snippet']['publishedAt'],
				'channelId' => $item['snippet']['channelId'],
				'channelTitle' => $item['snippet']['channelTitle'],
				'title' => $item['snippet']['title'],
				'description' => $item['snippet']['description'],
				'thumbnails' => $item['snippet']['thumbnails'],
				);
		}
		return $videos;

	}

}
