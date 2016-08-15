<?php

class Ldap {

	public $host;
	public $port = 389;
	public $username = '';
	public $password = '';
	private $config_file;
	private $logger;

	public function __construct($logger, $config_file = null) {
		$this->config_file = $config_file;
		$this->logger = $logger;
		$this->load_config();
		if (!$this->connect($this->host, $this->port, $this->username, $this->password)) {
			$this->logger->addCritical(__FILE__.": ".__METHOD__.": unable to init ldap extension");
			die();
		}
	}

	public function load_config() {
		if (is_file($this->config_file)) $config = parse_ini_file($this->config_file, true);
		else $this->logger->addWarning(__FILE__.": ".__METHOD__.": config file doesn't exist");
		if (empty($this->host)) {
			if (!empty($config['host'])) $this->host = $config['host'];
			else {
				$this->logger->addCritical(__FILE__.": ".__METHOD__.": missing configuration parameter: host");
				die();
			}
		}
		if (!empty($config['port'])) $this->port = $config['port'];
		else $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: port");
		if (!empty($config['username'])) $this->username = $config['username'];
		else $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: username");
		if (!empty($config['password'])) $this->password = $config['password'];
		else $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: password");
	}

	public function connect($host, $port = 389, $username = null, $password = null) {

		$this->lc = ldap_connect($host, $port);
		if (!$this->lc) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": couldn't connect to ldap host: $host");
			return false;
		}

		ldap_set_option($this->lc, LDAP_OPT_PROTOCOL_VERSION, 3);
		ldap_set_option($this->lc,  LDAP_OPT_NETWORK_TIMEOUT, 2);
		ldap_set_option($this->lc,  LDAP_OPT_TIMELIMIT, 2);

		if (!empty($username) && !empty($password)) $ldap_bind = ldap_bind($this->lc,$username,$password);
		else $ldap_bind = ldap_bind($this->lc);
		if (!$ldap_bind) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": couldn't bind to ldap");
			return false;
		}

		return true;

	}

	public function search($params) {

		if (empty($params['base'])) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": missing function param: base");
			return false;
		}
		if (empty($params['want'])) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": missing function param: want");
			return false;
		}
		if (empty($params['have'])) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": missing function param: have");
			return false;
		}
		if (empty($params['have_values'])) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": missing function param: have_values");
			return false;
		}
		if (empty($params['max_entries'])) {
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": missing function param: max_entries");
			$params['max_entries'] = 0;
		}

		$filter = '(|';
		foreach ($params['have_values'] as $value) {
			foreach ($params['have'] as $have_item)
				$filter .= "($have_item=$value)";
		}
		$filter .= ")";

		$params['want'] = array_merge($params['want'], $params['have']);
		$sr = ldap_search($this->lc, $params['base'], $filter, $params['want'], $params['max_entries']);
		if (!$sr) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": couldn't do ldap search");
			return false;
		}

		$ldap_entries = ldap_get_entries($this->lc, $sr);

		$ldap_wanted_items = array();

		for ($i = 0; $i < $ldap_entries["count"]; $i++) {
			$ldap_wanted_items[$i] = array();
			foreach ($params['want'] as $justthis) {
				if (in_array($justthis, $params['have'])) continue;
				$ldap_wanted_items[$i][$justthis] = $ldap_entries[$i][$justthis][0];
			}
		}

		if (empty($ldap_wanted_items))
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": no ldap items found");

		return $ldap_wanted_items;

	}

}
	
?>
