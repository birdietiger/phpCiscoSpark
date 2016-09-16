<?php

class Curl {

	protected $logger;
	public $caller = '';
	public $method;
	public $paginate = false;
	public $url;
	public $success_http_code;
	public $response_type;
	public $headers;
	public $params;
	public $user_pwd = '';
	public $backoff_timer = 1000000; // usecs
	public $backoff_multiplier = 1.2;
	public $backoff_codes = array();
	public $backoff_max_time = 15; // secs
	protected $connect_timeout = 15; // secs
	protected $function_timeout = 30; // secs
	protected $ch;

	public function __construct($logger = null) {
		$this->logger = $logger;
		$this->ch = curl_init();
	}

	public function request() {
		$function_start = \function_start();

		if (empty($this->caller)) { $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing class parameter: caller"); }
		if (empty($this->method)) { $this->logger->addError($this->caller.": ".__FILE__.": ".__METHOD__.": missing class parameter: method"); return false; }
		if (empty($this->url)) { $this->logger->addError($this->caller.": ".__FILE__.": ".__METHOD__.": missing class parameter: url"); return false; }
		if (empty($this->success_http_code)) { $this->logger->addError($this->caller.": ".__FILE__.": ".__METHOD__.": missing class parameter: success_http_code"); return false; }
		if (empty($this->response_type)) { $this->logger->addError($this->caller.": ".__FILE__.": ".__METHOD__.": missing class parameter: response_type"); return false; }
		if (empty($this->headers)) { $this->logger->addInfo($this->caller.": ".__FILE__.": ".__METHOD__.": missing class parameter: headers"); }
		if (empty($this->params)) { $this->logger->addInfo($this->caller.": ".__FILE__.": ".__METHOD__.": missing class parameter: params"); }
		if (!(
			$this->response_type == 'json' || 
			$this->response_type == 'html' ||
			$this->response_type == 'empty'
			)) { $this->logger->addError($this->caller.": ".__FILE__.": ".__METHOD__.": invalid class parameter: response_type"); return false; }

		curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, $this->method);
		curl_setopt($this->ch, CURLOPT_URL, $this->url);
		curl_setopt($this->ch, CURLOPT_HEADER, true);
		curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($this->ch, CURLOPT_CONNECTTIMEOUT, $this->connect_timeout); 
		curl_setopt($this->ch, CURLOPT_TIMEOUT, $this->function_timeout);
		if (!empty($this->user_pwd)) curl_setopt($this->ch, CURLOPT_USERPWD, $this->user_pwd);
		if (!empty($this->headers)) curl_setopt($this->ch, CURLOPT_HTTPHEADER, $this->headers);
		if ($this->method == 'POST') curl_setopt($this->ch, CURLOPT_POST, true);
		if (!empty($this->params)) curl_setopt($this->ch, CURLOPT_POSTFIELDS, $this->params);
		$this->logger->addInfo($this->caller.": ".__FILE__.": ".__METHOD__.": url: ".$this->url);
		$this->logger->addInfo($this->caller.": ".__FILE__.": ".__METHOD__.": method: ".$this->method);
		$this->logger->addInfo($this->caller.": ".__FILE__.": ".__METHOD__.": request headers: ".json_encode($this->headers));
		$this->logger->addInfo($this->caller.": ".__FILE__.": ".__METHOD__.": params: ".serialize($this->params));
		$backoff_timer = $this->backoff_timer;
		$start_execution_time = time();
		while (true) {
			$response = curl_exec($this->ch);
			$http_code = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
			$header_length = curl_getinfo($this->ch, CURLINFO_HEADER_SIZE);
			$header = substr($response, 0, $header_length);
			$this->logger->addInfo($this->caller.": ".__FILE__.": ".__METHOD__.": response header: $header");
			$header_lines = explode("\r\n", $header);
			foreach ($header_lines as $index => $header_line) {
				if ($index == 0 || empty($header_line)) continue;
				$header_elements = explode(":", $header_line, 2);
				$header_array[strtolower($header_elements[0])] = (!empty($header_elements[1])) ? $header_elements[1] : '';
			}
			$body = substr($response, $header_length);
			$this->logger->addDebug($this->caller.": ".__FILE__.": ".__METHOD__.": response body: $body");
			if ($http_code == $this->success_http_code) {
				switch ($this->response_type) {
					case 'json':
						if (empty($data = json_decode($body, true))) {
							$this->logger->addError($this->caller.": ".__FILE__.": ".__METHOD__.": response body isn't valid json");
							$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
							return false;
						} else {
							$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
							if (
								$this->paginate
								&& !empty($header_array['link'])
								&& preg_match('/rel="next"\s*$/', $header_array['link']) > 0
								) {
								$this->url = preg_replace('/^\s*<(.+)>.*$/', '\1', $header_array['link']);
								$this->url = preg_replace('/([\?&])max=null(&|$)/', '$1$2', $this->url);
								$this->logger->addInfo($this->caller.": ".__FILE__.": ".__METHOD__.": paginating using ".$this->url);
								if (empty($new_data = $this->request())) {
									$this->logger->addError($this->caller.": ".__FILE__.": ".__METHOD__.": paginating failed");
									return false;
								}
								$data = array_merge_recursive($new_data, $data);
							}
							return $data;
						}
					case 'html':
						$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
						return $body;
					case 'empty':
						$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
						return true;
				}
			} else {
				if (in_array($http_code, $this->backoff_codes) || curl_errno($this->ch) == 28) {
					if (curl_errno($this->ch) == 28)
						$this->logger->addWarning($this->caller.": ".__FILE__.": ".__METHOD__.": timeout exceed"); 
					else
						$this->logger->addWarning($this->caller.": ".__FILE__.": ".__METHOD__.": response http status code isn't $this->success_http_code: $http_code");
					usleep($backoff_timer);
					$backoff_timer = $backoff_timer * $this->backoff_multiplier;
					if ($this->backoff_max_time > (time() - $start_execution_time) + ($backoff_timer/1000000)) {
						$this->logger->addInfo($this->caller.": ".__FILE__.": ".__METHOD__.": backing off to try again");
						continue;
					} else $this->logger->addInfo($this->caller.": ".__FILE__.": ".__METHOD__.": backoff max time exceeded. no more attempts");
				}
				$this->logger->addError($this->caller.": ".__FILE__.": ".__METHOD__.": response http status code isn't $this->success_http_code: $http_code");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return false;
			}
		}
	}

	public function close() {
		curl_close($this->ch);
	}

}

?>
