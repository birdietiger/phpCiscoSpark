<?php

class Watson_Conversation {

	protected $logger;
	public $username;
	public $password;
	public $workspace_id;
	public $api_url;
	public $version;
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
		if (!empty($config['username'])) $this->username = $config['username'];
		else $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: username");
		if (!empty($config['password'])) $this->password = $config['password'];
		else $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: password");
		if (!empty($config['api_url'])) $this->api_url = $config['api_url'];
		else $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: api_url");
		if (!empty($config['version'])) $this->version = $config['version'];
		else $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: version");
		if (!empty($config['workspace_id'])) $this->workspace_id = $config['workspace_id'];
		else $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: workspace_id");
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
	}

	public function start($event, $room_context = false) {
		if (isset($event->messages['text'])) $event->messages['text'] = '';
		return $this->input($event, true, $room_context);
	}

	public function input($event, $alt_intents = true, $room_context = false) {
		$function_start = \function_start();

		if (empty($this->username)) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing class parameter: username");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (empty($this->password)) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing class parameter: password");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}

		if (empty($this->workspace_id)) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing class parameter: workspace_id");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}

		if (empty($this->api_url)) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing class parameter: api_url");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}

		if (!isset($event->messages['text'])) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing function parameter: event->messages['text']");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}

		if (empty($this->version)) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing class parameter: version");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}

		if ($room_context === true) {
			if (!empty($this->storage->watson_conversation['contexts']['rooms'][$event->rooms['id']]['context']))
				$context = $this->storage->watson_conversation['contexts']['rooms'][$event->rooms['id']]['context'];
		} else {
			if (!empty($this->storage->watson_conversation['contexts']['rooms'][$event->rooms['id']]['people'][$event->people['id']]['context']))
				$context = $this->storage->watson_conversation['contexts']['rooms'][$event->rooms['id']]['people'][$event->people['id']]['context'];
		}

		$params = [
			'input' => [ 'text' => $event->messages['text'] ],
			'alternate_intents' => $alt_intents
			];
		if (!empty($context)) $params['context'] = $context;

		$curl = new Curl($this->logger);//$this->curl;
      $curl->method = 'POST';
		$curl->headers = array(
			"Authorization: Basic ".base64_encode($this->username.':'.$this->password),
			"Content-Type: application/json",
			"Accept: application/json",
			);
		$curl->params = json_encode($params);
      $curl->response_type = 'json';
      $curl->url = $this->api_url.'/v1/workspaces/'.$this->workspace_id.'/message?version='.$this->version;
      $curl->success_http_code = '200';
		//$curl->backoff_codes = array('429', '500', '503');
      $curl->caller = __FILE__.': '.__METHOD__;
      if (empty($result = $curl->request())) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": didn't get anything back from messages input");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if ($room_context === true) {
			if (!empty($event->rooms['id']))
				$this->storage->watson_conversation['contexts']['rooms'][$event->rooms['id']]['context'] = $result['context'];
		} else {
			if (!empty($event->rooms['id']) && !empty($event->people['id']))
				$this->storage->watson_conversation['contexts']['rooms'][$event->rooms['id']]['people'][$event->people['id']]['context'] = $result['context'];
		}
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $result;

	}

}
