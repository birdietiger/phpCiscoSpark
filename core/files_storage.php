<?php

class FilesStorage {

	protected $logger;
	protected $config;
	public $upload_url;
	public $tokens_url;
	public $content_url;
	public $temp_folder_id;
	public $oauth_redirect_uri;
	public $client_id;
	public $client_secret;
	public $class;

   public function __construct($logger = null, $config = null) {
		$this->logger = $logger;
		$this->config = $config;
		$this->set_variables();
   }

   protected function set_variables() {

      if (empty($this->config['files_storage']['upload_url'])) $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: upload_url");
      else $this->upload_url = $this->config['files_storage']['upload_url'];

      if (empty($this->config['files_storage']['tokens_url'])) $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: tokens_url");
      else $this->tokens_url = $this->config['files_storage']['tokens_url'];

      if (empty($this->config['files_storage']['content_url'])) $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: content_url");
      else $this->content_url = $this->config['files_storage']['content_url'];

      if (!isset($this->config['files_storage']['temp_folder_id']) ||
         strlen($this->config['files_storage']['temp_folder_id']) == 0) $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: temp_folder_id");
      else $this->temp_folder_id = $this->config['files_storage']['temp_folder_id'];

      if (empty($this->config['files_storage']['oauth_redirect_uri'])) $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: oauth_redirect_uri");
      else $this->oauth_redirect_uri = $this->config['files_storage']['oauth_redirect_uri'];

      if (empty($this->config['files_storage']['client_id'])) $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: client_id");
      else $this->client_id = $this->config['files_storage']['client_id'];

      if (empty($this->config['files_storage']['class'])) $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: class");
      else $this->class = $this->config['files_storage']['class'];

   }

}

?>
