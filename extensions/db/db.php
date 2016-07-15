<?php

class db {

	protected $logger;
	public $config_file;
	public $username;
	public $password;
	public $source_name;

	private $dbh;
	private $error;
	private $stmt;

	public function __construct($logger, $config_file = null) {
		$this->config_file = $config_file;
		$this->logger = $logger;
		$this->load_config();
	}

	public function load_config() {
		if (is_file($this->config_file)) $config = parse_ini_file($this->config_file, true);
		else $this->logger->addWarning(__FILE__.": ".__METHOD__.": config file doesn't exist");
		if (!empty($config['source_name'])) $this->source_name = $config['source_name'];
		else $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: source_name");
		if (!empty($config['username'])) $this->username = $config['username'];
		else $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: username");
		if (!empty($config['password'])) $this->password = $config['password'];
		else $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: password");
	}

	public function query($query) {
		if (strlen($query) == 0) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": missing function parameter: query");
			return false;
		}
		$this->stmt = $this->dbh->prepare($query);
		return true;
	}

	public function bind($param, $value, $type = null){
		if (strlen($param) == 0) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": missing function parameter: param");
			return false;
		}
		if (strlen($value) == 0) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": missing function parameter: value");
			return false;
		}
		if (is_null($type)) {
			switch (true) {
				case is_int($value):
					$type = PDO::PARAM_INT;
					break;
				case is_bool($value):
					$type = PDO::PARAM_BOOL;
					break;
				case is_null($value):
					$type = PDO::PARAM_NULL;
					break;
				default:
					$type = PDO::PARAM_STR;
			}
		}
		return $this->stmt->bindValue($param, $value, $type);
	}

	public function resultset(){
		$this->execute();
		return $this->stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	public function single(){
		$this->execute();
		return $this->stmt->fetch(PDO::FETCH_ASSOC);
	}

	public function rowCount(){
		return $this->stmt->rowCount();
	}

	public function lastInsertId(){
		return $this->dbh->lastInsertId();
	}

	public function beginTransaction(){
		return $this->dbh->beginTransaction();
	}

	public function endTransaction(){
		return $this->dbh->commit();
	}

	public function cancelTransaction(){
		return $this->dbh->rollBack();
	}

	public function debugDumpParams(){
		return $this->stmt->debugDumpParams();
	}

	public function execute(){
		return $this->stmt->execute();
	}

	public function connect() {
		if (empty($this->source_name)) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": missing class parameter: source_name");
			return false;
		}
		if (empty($this->username)) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": missing class parameter: username");
			return false;
		}
		if (empty($this->password)) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": missing class parameter: password");
			return false;
		}
		$options = array(
			PDO::ATTR_PERSISTENT    => true,
			PDO::ATTR_ERRMODE       => PDO::ERRMODE_EXCEPTION
			);
		try {
			$this->dbh = new PDO($this->source_name, $this->username, $this->password, $options);
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": connected to db");
			return true;
		} catch(PDOException $e) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": failed to connect: ".$e->getMessage());
			return false;
		}
	}

}
