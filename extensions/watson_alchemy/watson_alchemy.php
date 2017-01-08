<?php

class Watson_Alchemy {

	protected $logger;
	public $api_key;
	public $api_url;
	public $storage;
	public $config_file;

	public function __construct($logger, $config_file = null, $storage = null) {
		$function_start = \function_start();
		$this->config_file = $config_file;
		$this->logger = $logger;
		if ($storage !== null) $this->storage = $storage;
		else $this->storage = new StdClass();
		if (!class_exists('Curl')) {
			$this->logger->addCritical(__FILE__.": ".__METHOD__.": Curl class is missing. make sure to include Curl handler");
			exit();
		}
		$this->load_config();
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
	}

	public function load_config() {
		$function_start = \function_start();
		if (is_file($this->config_file)) $config = parse_ini_file($this->config_file, true);
		else $this->logger->addWarning(__FILE__.": ".__METHOD__.": config file doesn't exist");
		if (!empty($config['api_key'])) $this->api_key = $config['api_key'];
		else $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: api_key");
		if (!empty($config['api_url'])) $this->api_url = $config['api_url'];
		else $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: api_url");
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
	}

	public function input($input, $extracts = [ 'entities', 'keywords' ], $max_retrieve = 5) {
		$function_start = \function_start();

		if (empty($this->api_key)) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing class parameter: api_key");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}

		if (empty($this->api_url)) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing class parameter: api_url");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}

		if (
			is_string($input)
			&& !empty($input)
			) $text = $input;
		else if (
			is_array($input)
			&& !empty($event->messages['text'])
			) $text = $event->messages['text'];

		if (empty($text)) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing function parameter: input");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}

		$params = [
			'text' => $text,
			'extract' => implode(',', $extracts),
			'outputMode' => 'json',
			'maxRetrieve' => $max_retrieve,
			];

		$curl = new Curl($this->logger);//$this->curl;
      $curl->method = 'POST';
		$curl->headers = array(
			"Accept: application/json",
			"Content-Type: application/x-www-form-urlencoded",
			);
		$curl->params = http_build_query($params);
      $curl->response_type = 'json';
      $curl->url = $this->api_url.'/text/TextGetCombinedData?apikey='.$this->api_key;
      $curl->success_http_code = '200';
      $curl->caller = __FILE__.': '.__METHOD__;
      if (empty($result = $curl->request())) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": didn't get anything back from input");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}

		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $result;

	}

}
