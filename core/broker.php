<?php

class MessageBroker {

	protected $logger;
	protected $config;
	public $subscribe_topics;
	public $class;
	public $target_url;
	public $host;
	public $port;
	public $username;
	public $password;
	public $subscribed_to_topics = array();

   public function __construct($logger = null, $config = null) {
		$this->logger = $logger;
		$this->config = $config;
		$this->set_variables();
   }

	public function process_subscribes($subscribe_topics = null) {
		if (empty($subscribe_topics)) $subscribe_topics = $this->subscribe_topics;
		if (empty($this->subscribe_topics)) {
			$this->logger->addWarning(__FILE__.": ".__METHOD__.": no topic to subscribe to");
			return false;
		}
		if (empty($this->class)) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": missing class parameter: class");
			return false;
		}
		if (!class_exists($this->class)) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": class doesn't exist: ".$this->class);
			return false;
		}
		if (empty($this->target_url)) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": missing class parameter: target_url");
			return false;
		}
		if (empty($this->host)) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": missing class parameter: host");
			return false;
		}
		if (empty($this->port)) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": missing class parameter: port");
			return false;
		}

		$broker_client_uniqid = uniqid(mt_rand(1000000, 9999999));
		$this->logger->addInfo(__FILE__.": ".__METHOD__.": broker client id: $broker_client_uniqid");
		$broker_client = new $this->class($this->host, $this->port, $broker_client_uniqid);

		if (!isset($this->secure))
			$this->logger->addWarning(__FILE__.": ".__METHOD__.": missing class parameter: secure");
		else
			$broker_client->secure = $this->secure;

		if (!isset($this->no_cert_check))
			$this->logger->addWarning(__FILE__.": ".__METHOD__.": missing class parameter: no_cert_check");
		else
			$broker_client->no_cert_check = $this->no_cert_check;

		if (!isset($this->websocket))
			$this->logger->addWarning(__FILE__.": ".__METHOD__.": missing class parameter: websocket");
		else
			$broker_client->websocket = $this->websocket;

		if (!isset($this->debug))
			$this->logger->addWarning(__FILE__.": ".__METHOD__.": missing class parameter: debug");
		else
			$broker_client->debug = $this->debug;

		if (empty($this->proxy))
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": missing class parameter: proxy");
		else
			$broker_client->proxy = $this->proxy;

		if (empty($this->proxyport))
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": missing class parameter: proxyport");
		else
			$broker_client->proxyport = $this->proxyport;

		if (empty($this->username))
			$this->logger->addWarning(__FILE__.": ".__METHOD__.": missing class parameter: username");
		else
			$broker_client->username = $this->username;

		if (empty($this->password))
			$this->logger->addWarning(__FILE__.": ".__METHOD__.": missing class parameter: password");
		else
			$broker_client->password = $this->password;

		$this->logger->addInfo(__FILE__.": ".__METHOD__.": starting to listen for webhook messages");
		
		for ($i=1; $i<=3; $i++) {
			if (!$broker_client->connect())
				$this->logger->addError(__FILE__.": ".__METHOD__.": failed to connect to broker, attempt $i");
			else break;
			if ($i == 3) return false;
		}

		foreach ($this->subscribe_topics as $topic => $topic_details) {
			if (!$broker_client->subscribe(array($topic => $topic_details))) {
				if (!empty($this->subscribed_to_topics[$topic])) continue;
				$this->logger->addError(__FILE__.": ".__METHOD__.": failed to subscribe to topic");
			} else $this->subscribed_to_topics[$topic] = true;
		}
		
		return $broker_client;

	}

	protected function set_variables() {

		if (empty($this->config['broker']['class'])) $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: broker class");
		else $this->class = $this->config['broker']['class'];

		if (empty($this->config['broker']['host'])) $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: broker host");
		else $this->host = $this->config['broker']['host'];

		if (empty($this->config['broker']['port'])) $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: broker port");
		else $this->port = $this->config['broker']['port'];

		if (empty($this->config['broker']['target_url'])) $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: broker target_url");
		else $this->target_url = $this->config['broker']['target_url'];

		if (!isset($this->config['broker']['secure']) || !is_bool((bool) $this->config['broker']['secure'])) $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter array: broker secure");
		else $this->secure = (bool) $this->config['broker']['secure'];

		if (!isset($this->config['broker']['no_cert_check']) || !is_bool((bool) $this->config['broker']['no_cert_check'])) $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter array: broker no_cert_check");
		else $this->no_cert_check = (bool) $this->config['broker']['no_cert_check'];

		if (!isset($this->config['broker']['websocket']) || !is_bool((bool) $this->config['broker']['websocket'])) $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter array: broker websocket");
		else $this->websocket = (bool) $this->config['broker']['websocket'];

		if (!isset($this->config['broker']['debug']) || !is_bool((bool) $this->config['broker']['debug'])) $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter array: broker debug");
		else $this->debug = (bool) $this->config['broker']['debug'];

		if (empty($this->config['broker']['proxy'])) $this->logger->addInfo(__FILE__.": ".__METHOD__.": missing configuration parameter array: broker proxy");
		else $this->proxy = $this->config['broker']['proxy'];

		if (empty($this->config['broker']['proxyport'])) $this->logger->addInfo(__FILE__.": ".__METHOD__.": missing configuration parameter array: broker proxyport");
		else $this->proxyport = $this->config['broker']['proxyport'];

	}

}

?>
