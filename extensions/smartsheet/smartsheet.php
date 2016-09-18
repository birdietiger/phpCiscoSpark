<?php

class Smartsheet {

	protected $logger;
	public $access_token;
	public $workspace_id;
	public $template_id;
	public $folder_id;
	public $storage;
	protected $sheet_name_max_length = 50;
	protected $folder_name_max_length = 50;
	public $config_file;
	protected $cache_expires = 3600; // secs

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
		if (!empty($config['access_token'])) $this->access_token = $config['access_token'];
		else $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: access_token");
		if (!empty($config['workspace_id'])) $this->workspace_id = $config['workspace_id'];
		else $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: workspace_id");
		if (!empty($config['template_id'])) $this->template_id = $config['template_id'];
		else $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: template_id");
		if (!empty($config['folder_id'])) $this->folder_id = $config['folder_id'];
		else $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: folder_id");
		if (!empty($config['cache_expires'])) $this->cache_expires = $config['cache_expires'];
		else $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: cache_expires");
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
	}

	public function smart_name($prefix = '', $label = '', $uniqid = '', $uniqid_length = 10) {
		$function_start = \function_start();
		$uniqid_length = (strlen($uniqid) < $uniqid_length) ? strlen($uniqid) : $uniqid_length;
		$short_uniqid = (!empty($label) && !empty($uniqid)) ? substr($uniqid, -$uniqid_length, $uniqid_length) : $uniqid;
		$short_label_max_length = $this->sheet_name_max_length - (strlen($prefix) + strlen($short_uniqid) + 5); // +5 for spaces, : and brackets
		$short_label = (strlen($label) > $short_label_max_length) ? substr($label, 0, $short_label_max_length - 3).'...' : $label;
		if (empty($prefix) && empty($uniqid)) {
			$name = $label;
			$regex = "^".preg_quote($label, '/')."$";
		} else if (empty($label) && empty($uniqid)) {
			$name = $prefix;
			$regex = "^".preg_quote($prefix, '/')."$";
		} else if (empty($label) && empty($prefix)) {
			$name = $uniqid;
			$regex = "^".preg_quote($uniqid, '/')."$";
		} else if (!empty($short_uniqid)) {
	 		$name = $prefix.': '.$short_label.' ['.$short_uniqid.']';
			$regex = "^".preg_quote($prefix, '/').":\ .*\[".preg_quote($short_uniqid, '/')."\]$";
		} else {
			$name = $prefix.': '.$short_label;
			$regex = "^".preg_quote($prefix, '/').":\ .*$";
		}
		$smart_name = array(
			'prefix' => $prefix,
			'label' => $short_label,
			'uniqid' => $short_uniqid,
			'name' => $name,
			'regex' => $regex,
			);
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $smart_name;
	}

	public function smart_create_workspace($prefix = '', $label = '', $uniqid = '', $uniqid_length = 10) {
		$function_start = \function_start();

		$smart_name = $this->smart_name($prefix, $label, $uniqid, $uniqid_length);
		if (
			!empty($this->storage->smartsheet['smart_create_workspace_cache'][$smart_name['name']]) && 
			$this->storage->smartsheet['smart_create_workspace_cache'][$smart_name['name']]['expires'] <= time()
			) unset($this->storage->smartsheet['smart_create_workspace_cache'][$smart_name['name']]);
		if (!empty($this->storage->smartsheet['smart_create_workspace_cache'][$smart_name['name']])) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": using smart_create_workspace_cache for smart name: ".$smart_name['name']);
			$workspace_id = $this->storage->smartsheet['smart_create_workspace_cache'][$smart_name['name']]['data'];
		} else {

			if (!empty($workspaces = $this->search_for_workspaces($smart_name['regex'])))
				$workspace_id = $workspaces[0]['id'];
			else
				$workspace_id = $this->create_workspace($smart_name['name']);

			if (!empty($workspace_id)) {

				$this->storage->smartsheet['smart_create_workspace_cache'][$smart_name['name']] = array(
					'data' => $workspace_id,
					'expires' => time() + $this->cache_expires,
					);

			}

		}
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $workspace_id;
	}

	public function smart_create_folder($prefix = '', $label = '', $uniqid = '', $uniqid_length = 10, $workspace_id = null) {
		$function_start = \function_start();

		if (empty($workspace_id)) {
			if (empty($this->workspace_id)) {
				$this->logger->addError(__FILE__.': '.__METHOD__.": Missing function and class parameter: workspace_id");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return false;
			} else $workspace_id = $this->workspace_id;
		}

		$smart_name = $this->smart_name($prefix, $label, $uniqid, $uniqid_length);
		if (
			!empty($this->storage->smartsheet['smart_create_folder_cache'][$workspace_id][$smart_name['name']]) && 
			$this->storage->smartsheet['smart_create_folder_cache'][$workspace_id][$smart_name['name']]['expires'] <= time()
			) unset($this->storage->smartsheet['smart_create_folder_cache'][$workspace_id][$smart_name['name']]);
		if (!empty($this->storage->smartsheet['smart_create_folder_cache'][$workspace_id][$smart_name['name']])) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": using smart_create_folder_cache for workspace id: $workspace_id and smart name: ".$smart_name['name']);
			$folder_id = $this->storage->smartsheet['smart_create_folder_cache'][$workspace_id][$smart_name['name']]['data'];
		} else {

			if (!empty($folders = $this->search_for_folders_in_workspace($smart_name['regex'], $workspace_id)))
				$folder_id = $folders[0]['id'];
			else
				$folder_id = $this->create_folder_in_workspace($smart_name['name']);

			if (!empty($folder_id)) {

				$this->storage->smartsheet['smart_create_folder_cache'][$workspace_id][$smart_name['name']] = array(
					'data' => $folder_id,
					'expires' => time() + $this->cache_expires,
					);

			}

		}
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $folder_id;
	}

	public function smart_create_sheet_workspace($prefix = '', $label = '', $uniqid = '', $uniqid_length = 10, $workspace_id = null) {
		$function_start = \function_start();

		if (empty($workspace_id)) {
			if (empty($this->workspace_id)) {
				$this->logger->addError(__FILE__.': '.__METHOD__.": Missing function and class parameter: workspace_id");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return false;
			} else $workspace_id = $this->workspace_id;
		}

		$smart_name = $this->smart_name($prefix, $label, $uniqid, $uniqid_length);
		if (
			!empty($this->storage->smartsheet['smart_create_sheet_cache'][$workspace_id][$smart_name['name']]) && 
			$this->storage->smartsheet['smart_create_sheet_cache'][$workspace_id][$smart_name['name']]['expires'] <= time()
			) unset($this->storage->smartsheet['smart_create_sheet_cache'][$workspace_id][$smart_name['name']]);
		if (!empty($this->storage->smartsheet['smart_create_sheet_cache'][$workspace_id][$smart_name['name']])) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": using smart_create_sheet_cache for workspace id: $workspace_id and smart name: ".$smart_name['name']);
			$sheet = $this->storage->smartsheet['smart_create_sheet_cache'][$workspace_id][$smart_name['name']]['data'];
		} else {

			if (!empty($sheets = $this->search_for_sheets_workspace($smart_name['regex'], $workspace_id)))
				$sheet = $sheets[0];
			else
				$sheet = $this->create_sheet_workspace($smart_name['name'], $workspace_id);

			if (!empty($sheet)) {

				$this->storage->smartsheet['smart_create_sheet_cache'][$workspace_id][$smart_name['name']] = array(
					'data' => $sheet,
					'expires' => time() + $this->cache_expires,
					);
			}

		}
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $sheet;
	}

	public function smart_create_sheet($prefix = '', $label = '', $uniqid = '', $uniqid_length = 10, $folder_id = null) {
		$function_start = \function_start();

		if (empty($folder_id)) {
			if (empty($this->folder_id)) {
				$this->logger->addError(__FILE__.': '.__METHOD__.": Missing function and class parameter: folder_id");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return false;
			} else $folder_id = $this->folder_id;
		}

		$smart_name = $this->smart_name($prefix, $label, $uniqid, $uniqid_length);
		if (
			!empty($this->storage->smartsheet['smart_create_sheet_cache'][$folder_id][$smart_name['name']]) && 
			$this->storage->smartsheet['smart_create_sheet_cache'][$folder_id][$smart_name['name']]['expires'] <= time()
			) unset($this->storage->smartsheet['smart_create_sheet_cache'][$folder_id][$smart_name['name']]);
		if (!empty($this->storage->smartsheet['smart_create_sheet_cache'][$folder_id][$smart_name['name']])) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": using smart_create_sheet_cache for folder id: $folder_id and smart name: ".$smart_name['name']);
			$sheet = $this->storage->smartsheet['smart_create_sheet_cache'][$folder_id][$smart_name['name']]['data'];
		} else {

			if (!empty($sheets = $this->search_for_sheets($smart_name['regex'], $folder_id)))
				$sheet = array('id' => $sheets[0]['id'], 'url' => $sheets[0]['permalink']);
			else
				$sheet = $this->create_sheet($smart_name['name'], $folder_id);

			if (!empty($sheet)) {

				$this->storage->smartsheet['smart_create_sheet_cache'][$folder_id][$smart_name['name']] = array(
					'data' => $sheet,
					'expires' => time() + $this->cache_expires,
					);
			}

		}
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $sheet;
	}

	public function create_workspace($name) {
		$function_start = \function_start();

		if (empty($this->access_token)) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing class parameter: access_token");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (strlen($name) == 0) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing function parameter: name");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}

		$url = "https://api.smartsheet.com/2.0/workspaces";

		$params = array('name' => $name);

		$curl = new Curl($this->logger);//$this->curl;
      $curl->method = 'POST';
		$curl->headers = array(
			"Authorization: Bearer ".$this->access_token,
			"Content-Type: application/json",
			);
		$curl->params = json_encode($params);
      $curl->response_type = 'json';
      $curl->url = $url;
      $curl->success_http_code = '200';
		$curl->backoff_codes = array('429', '500', '503');
      $curl->caller = __FILE__.': '.__METHOD__;
      if (empty($result = $curl->request())) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": didn't get anything back from create");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if ($result['resultCode'] != 0) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": failed to create workspace: ".$result['message']);
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $result['result']['id'];

	}

	public function create_folder($name, $folder_id = null) {
		$function_start = \function_start();

		if (empty($this->access_token)) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing class parameter: access_token");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (strlen($name) == 0) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing function parameter: name");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (empty($folder_id)) {
			if (empty($this->folder_id)) {
				$this->logger->addError(__FILE__.': '.__METHOD__.": Missing function and class parameter: folder_id");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return false;
			} else $folder_id = $this->folder_id;
		}

		$url = "https://api.smartsheet.com/2.0/folders/".$folder_id."/folders";

		$params = array('name' => $name);

		$curl = new Curl($this->logger);//$this->curl;
      $curl->method = 'POST';
		$curl->headers = array(
			"Authorization: Bearer ".$this->access_token,
			"Content-Type: application/json",
			);
		$curl->params = json_encode($params);
      $curl->response_type = 'json';
      $curl->url = $url;
      $curl->success_http_code = '200';
		$curl->backoff_codes = array('429', '500', '503');
      $curl->caller = __FILE__.': '.__METHOD__;
      if (empty($result = $curl->request())) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": didn't get anything back from create");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if ($result['resultCode'] != 0) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": failed to create folder: ".$result['message']);
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $result['result']['id'];

	}

	public function create_folder_in_workspace($name, $workspace_id = null) {
		$function_start = \function_start();

		if (empty($this->access_token)) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing class parameter: access_token");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (strlen($name) == 0) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing function parameter: name");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (empty($workspace_id)) {
			if (empty($this->workspace_id)) {
				$this->logger->addError(__FILE__.': '.__METHOD__.": Missing function and class parameter: workspace_id");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return false;
			} else $workspace_id = $this->workspace_id;
		}

		$url = "https://api.smartsheet.com/2.0/workspaces/".$workspace_id."/folders";

		$params = array('name' => $name);

		$curl = new Curl($this->logger);//$this->curl;
      $curl->method = 'POST';
		$curl->headers = array(
			"Authorization: Bearer ".$this->access_token,
			"Content-Type: application/json",
			);
		$curl->params = json_encode($params);
      $curl->response_type = 'json';
      $curl->url = $url;
      $curl->success_http_code = '200';
		$curl->backoff_codes = array('429', '500', '503');
      $curl->caller = __FILE__.': '.__METHOD__;
      if (empty($result = $curl->request())) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": didn't get anything back from create");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if ($result['resultCode'] != 0) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": failed to create folder: ".$result['message']);
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $result['result']['id'];

	}

	public function create_sheet_workspace($name, $workspace_id = null, $template_id = null) {
		$function_start = \function_start();

		if (empty($this->access_token)) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing class parameter: access_token");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (strlen($name) == 0) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing function parameter: name");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (empty($workspace_id)) {
			if (empty($this->workspace_id)) {
				$this->logger->addError(__FILE__.': '.__METHOD__.": Missing function and class parameter: workspace_id");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return false;
			} else $workspace_id = $this->workspace_id;
		}
		if (empty($template_id)) {
			if (empty($this->template_id)) {
				$this->logger->addError(__FILE__.': '.__METHOD__.": Missing function and class parameter: template_id");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return false;
			} else $template_id = $this->template_id;
		}

		$url = "https://api.smartsheet.com/2.0/workspaces/".$workspace_id."/sheets?include=data,attachments,discussions";

		$params = array('name' => $name, 'fromId' => $template_id);

		$curl = new Curl($this->logger);//$this->curl;
      $curl->method = 'POST';
		$curl->headers = array(
			"Authorization: Bearer ".$this->access_token,
			"Content-Type: application/json",
			);
		$curl->params = json_encode($params);
      $curl->response_type = 'json';
      $curl->url = $url;
      $curl->success_http_code = '200';
		$curl->backoff_codes = array('429', '500', '503');
      $curl->caller = __FILE__.': '.__METHOD__;
      if (empty($result = $curl->request())) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": didn't get anything back from create");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if ($result['resultCode'] != 0) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": failed to create sheet: ".$result['message']);
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		$smartsheet = array('id' => $result['result']['id'], 'url' => $result['result']['permalink']);
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $smartsheet;

	}

	public function create_sheet($name, $folder_id = null, $template_id = null) {
		$function_start = \function_start();

		if (empty($this->access_token)) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing class parameter: access_token");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (strlen($name) == 0) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing function parameter: name");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (empty($folder_id)) {
			if (empty($this->folder_id)) {
				$this->logger->addError(__FILE__.': '.__METHOD__.": Missing function and class parameter: folder_id");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return false;
			} else $folder_id = $this->folder_id;
		}
		if (empty($template_id)) {
			if (empty($this->template_id)) {
				$this->logger->addError(__FILE__.': '.__METHOD__.": Missing function and class parameter: template_id");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return false;
			} else $template_id = $this->template_id;
		}

		$url = "https://api.smartsheet.com/2.0/folders/".$folder_id."/sheets?include=data,attachments,discussions";

		$params = array('name' => $name, 'fromId' => $template_id);

		$curl = new Curl($this->logger);//$this->curl;
      $curl->method = 'POST';
		$curl->headers = array(
			"Authorization: Bearer ".$this->access_token,
			"Content-Type: application/json",
			);
		$curl->params = json_encode($params);
      $curl->response_type = 'json';
      $curl->url = $url;
      $curl->success_http_code = '200';
		$curl->backoff_codes = array('429', '500', '503');
      $curl->caller = __FILE__.': '.__METHOD__;
      if (empty($result = $curl->request())) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": didn't get anything back from create");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if ($result['resultCode'] != 0) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": failed to create sheet: ".$result['message']);
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		$smartsheet = array('id' => $result['result']['id'], 'url' => $result['result']['permalink']);
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $smartsheet;

	}

	public function create_sheet_in_workspace($name, $workspace_id = null, $template_id = null) {
		$function_start = \function_start();

		if (empty($this->access_token)) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing class parameter: access_token");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (strlen($name) == 0) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing function parameter: name");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (empty($workspace_id)) {
			if (empty($this->workspace_id)) {
				$this->logger->addError(__FILE__.': '.__METHOD__.": Missing function and class parameter: workspace_id");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return false;
			} else $workspace_id = $this->workspace_id;
		}
		if (empty($template_id)) {
			if (empty($this->template_id)) {
				$this->logger->addError(__FILE__.': '.__METHOD__.": Missing function and class parameter: template_id");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return false;
			} else $template_id = $this->template_id;
		}

		$url = "https://api.smartsheet.com/2.0/workspaces/".$this->workspace_id."/sheets?include=data,attachments,discussions";

		$params = array('name' => $name, 'fromId' => $template_id);

		$curl = new Curl($this->logger);//$this->curl;
      $curl->method = 'POST';
		$curl->headers = array(
			"Authorization: Bearer ".$this->access_token,
			"Content-Type: application/json",
			);
		$curl->params = json_encode($params);
      $curl->response_type = 'json';
      $curl->url = $url;
      $curl->success_http_code = '200';
		$curl->backoff_codes = array('429', '500', '503');
      $curl->caller = __FILE__.': '.__METHOD__;
      if (empty($result = $curl->request())) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": didn't get anything back from create");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if ($result['resultCode'] != 0) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": failed to create sheet: ".$result['message']);
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		$smartsheet = array('id' => $result['result']['id'], 'url' => $result['result']['permalink']);
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $smartsheet;

	}

	public function get_sheet_publish($sheet_id) {
		$function_start = \function_start();

		if (empty($this->access_token)) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing class parameter: access_token");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (strlen($sheet_id) == 0) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing function parameter: sheet_id");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}

		$url = "https://api.smartsheet.com/2.0/sheets/".$sheet_id."/publish";

		$curl = new Curl($this->logger);//$this->curl;
      $curl->method = 'GET';
		$curl->headers = array(
			"Authorization: Bearer ".$this->access_token,
			"Content-Type: application/json",
			);
      $curl->response_type = 'json';
      $curl->url = $url;
      $curl->success_http_code = '200';
		$curl->backoff_codes = array('429', '500', '503');
      $curl->caller = __FILE__.': '.__METHOD__;
      if (empty($result = $curl->request())) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": didn't get anything back from get publish");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}

		$publish_result = array();
		$publish_result['read_url'] = (!empty($result['readOnlyFullUrl'])) ? $result['readOnlyFullUrl'] : '';
		$publish_result['write_url'] = (!empty($result['readWriteUrl'])) ? $result['readWriteUrl'] : '';

		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $publish_result;

	}

	public function share_workspace($emails, $access = 'EDITOR', $workspace_id = null) {
		$function_start = \function_start();

		if (empty($this->access_token)) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing class parameter: access_token");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}

		if (empty($workspace_id)) {
			if (empty($this->workspace_id)) {
				$this->logger->addError(__FILE__.': '.__METHOD__.": Missing function and class parameter: workspace_id");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return false;
			} else $workspace_id = $this->workspace_id;
		}

		if (empty($emails)) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing function parameter: emails");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}

		$url = "https://api.smartsheet.com/2.0/workspaces/".$workspace_id."/shares?sendEmail=true";

		$params = array();
		foreach ($emails as $email) { $params[] = array('email' => $email, 'accessLevel' => $access); }

		$curl = new Curl($this->logger);//$this->curl;
      $curl->method = 'POST';
		$curl->headers = array(
			"Authorization: Bearer ".$this->access_token,
			"Content-Type: application/json",
			);
		$curl->params = json_encode($params);
      $curl->response_type = 'json';
      $curl->url = $url;
      $curl->success_http_code = '200';
		$curl->backoff_codes = array('429', '500', '503');
      $curl->caller = __FILE__.': '.__METHOD__;
      if (empty($result = $curl->request())) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": didn't get anything back from shares");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if ($result['resultCode'] != 0) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": failed to share sheet: ".$result['message']);
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}

		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return true;

	}

	public function publish_sheet($sheet_id, $read_lite = true, $read_full = true, $read_who = 'ALL', $write = false, $write_who = 'ORG', $ical = false) {
		$function_start = \function_start();

		if (empty($this->access_token)) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing class parameter: access_token");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (strlen($sheet_id) == 0) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing function parameter: sheet_id");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}

		$url = "https://api.smartsheet.com/2.0/sheets/".$sheet_id."/publish";

		$params = array(
			'readOnlyLiteEnabled' => $read_lite,
			'readOnlyFullEnabled' => $read_full,
			'readWriteEnabled' => $write,
			'icalEnabled' => $ical,
			);

		if ($read) $params['readOnlyFullAccessibleBy'] = $read_who;
		if ($write) $params['readWriteAccessibleBy'] = $write_who;

		$curl = new Curl($this->logger);//$this->curl;
      $curl->method = 'PUT';
		$curl->headers = array(
			"Authorization: Bearer ".$this->access_token,
			"Content-Type: application/json",
			);
		$curl->params = json_encode($params);
      $curl->response_type = 'json';
      $curl->url = $url;
      $curl->success_http_code = '200';
		$curl->backoff_codes = array('429', '500', '503');
      $curl->caller = __FILE__.': '.__METHOD__;
      if (empty($result = $curl->request())) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": didn't get anything back from publish");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if ($result['resultCode'] != 0) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": failed to publish sheet: ".$result['message']);
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}

		$this->storage->smartsheet['smart_create_sheet_cache'] = array();
		$this->storage->smartsheet['search_sheets_cache'] = array();

		$publish_result = array();
		if ($read) $publish_result['read_url'] = $result['result']['readOnlyFullUrl'];
		else $publish_result['read_url'] = '';
		if ($write) $publish_result['write_url'] = $result['result']['readWriteUrl'];
		else $publish_result['write_url'] = '';

		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $publish_result;

	}

	public function update_row($sheet_id, $row_id, $cell_data) {
		$function_start = \function_start();

		if (empty($this->access_token)) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing class parameter: access_token");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (strlen($sheet_id) == 0) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing function parameter: sheet_id");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (strlen($row_id) == 0) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing function parameter: row_id");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (empty($cell_data)) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing function parameter: cell_data");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (empty($columns = $this->get_columns($sheet_id))) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": failed to get all columns");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}

		$params = array();
		$params[0] = array('id' => $row_id, 'cells' => array());
		foreach ($cell_data as $cell_column_title => $cell_value) {
			if (!in_array($cell_column_title, $columns)) {
				$this->logger->addError(__FILE__.': '.__METHOD__.": column title is unknown: $cell_column_title");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return false;
			}
			foreach ($columns as $column_id => $column_title) {
				if ($cell_column_title == $column_title) {
					$params[0]['cells'][] = array('columnId' => $column_id, 'value' => $cell_value);
					unset($columns[$column_id]);
					break;
				}
			}
		}

		$url = "https://api.smartsheet.com/2.0/sheets/".$sheet_id."/rows";

		$curl = new Curl($this->logger);//$this->curl;
      $curl->method = 'PUT';
		$curl->headers = array(
			"Authorization: Bearer ".$this->access_token,
			"Content-Type: application/json",
			);
		$curl->params = json_encode($params);
      $curl->response_type = 'json';
      $curl->url = $url;
      $curl->success_http_code = '200';
		$curl->backoff_codes = array('429', '500', '503');
      $curl->caller = __FILE__.': '.__METHOD__;
      if (empty($result = $curl->request())) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": didn't get anything back from update rows");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if ($result['resultCode'] != 0) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": failed to update cell: ".$result['message']);
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return true;

	}


	public function get_workspace_folders($workspace_id = null) {
		$function_start = \function_start();

		if (empty($this->access_token)) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing class parameter: access_token");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (empty($workspace_id)) {
			if (empty($this->workspace_id)) {
				$this->logger->addError(__FILE__.': '.__METHOD__.": Missing function and class parameter: workspace_id");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return false;
			} else $workspace_id = $this->workspace_id;
		}

		$url = "https://api.smartsheet.com/2.0/workspaces/".$workspace_id."/folders";

		$curl = new Curl($this->logger);//$this->curl;
      $curl->method = 'GET';
		$curl->headers = array(
			"Authorization: Bearer ".$this->access_token,
			"Content-Type: application/json",
			);
      $curl->response_type = 'json';
      $curl->url = $url;
      $curl->success_http_code = '200';
		$curl->backoff_codes = array('429', '500', '503');
      $curl->caller = __FILE__.': '.__METHOD__;
      if (empty($result = $curl->request())) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": didn't get anything back from get folders");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (empty($result['data'])) {
			$this->logger->addInfo(__FILE__.': '.__METHOD__.": no folders");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		$folders = array();
		foreach ($result['data'] as $folder) {
			$folders[$folder['id']] = $folder;
		}
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $folders;

	}

	public function search_for_workspaces($query) {
		$function_start = \function_start();

		if (
			!empty($this->storage->smartsheet['search_for_workspaces_cache'][$query]) && 
			$this->storage->smartsheet['search_for_workspaces_cache'][$query]['expires'] <= time()
			) unset($this->storage->smartsheet['search_for_workspaces_cache'][$query]);
		if (!empty($this->storage->smartsheet['search_for_workspaces_cache'][$query])) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": using search_for_workspaces_cache for query: $query");
			$found_workspaces = $this->storage->smartsheet['search_for_workspaces_cache'][$query]['data'];
		} else {
			if (empty($workspaces = $this->get_workspaces())) {
				$this->logger->addInfo(__FILE__.': '.__METHOD__.": no workspaces to search");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return false;
			}

			$found_workspaces = array();
			foreach ($workspaces as $workspace_id => $workspace_details) {
				if (preg_match("/$query/", $workspace_details['name']) > 0) {
					$found_workspaces[] = $workspace_details;
				}
			}
			if (empty($found_workspaces)) $this->logger->addInfo(__FILE__.': '.__METHOD__.": no workspaces found that match query: $query");
			else $this->logger->addInfo(__FILE__.': '.__METHOD__.": found ".count($found_workspaces)." workspaces");
			$this->storage->smartsheet['search_for_workspaces_cache'][$query] = array(
				'expires' => time() + $this->cache_expires,
				'data' => $found_workspaces,
				);

		}

		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $found_workspaces;

	}

	public function search_for_folders_in_workspace($query, $workspace_id = null) {
		$function_start = \function_start();

		if (empty($workspace_id)) {
			if (empty($this->workspace_id)) {
				$this->logger->addError(__FILE__.': '.__METHOD__.": Missing function and class parameter: workspace_id");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return false;
			} else $workspace_id = $this->workspace_id;
		}

		if (
			!empty($this->storage->smartsheet['search_folders_in_workspace_cache'][$workspace_id][$query]) && 
			$this->storage->smartsheet['search_folders_in_workspace_cache'][$workspace_id][$query]['expires'] <= time()
			) unset($this->storage->smartsheet['search_folders_in_workspace_cache'][$workspace_id][$query]);

		if (!empty($this->storage->smartsheet['search_folders_in_workspace_cache'][$workspace_id][$query])) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": using search_folders_in_workspace_cache for workspace id: $workspace_id and query: $query");
			$found_folders = $this->storage->smartsheet['search_folders_in_workspace_cache'][$workspace_id][$query]['data'];
		} else {
			if (empty($folders = $this->get_folders_in_workspace($workspace_id))) {
				$this->logger->addInfo(__FILE__.': '.__METHOD__.": no folders to search");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return false;
			}

			$found_folders = array();
			foreach ($folders as $folder_id => $folder_details) {
				if (preg_match("/$query/", $folder_details['name']) > 0) {
					$found_folders[] = $folder_details;
				}
			}
			if (empty($found_folders)) $this->logger->addInfo(__FILE__.': '.__METHOD__.": no folders found that match query: $query");
			else $this->logger->addInfo(__FILE__.': '.__METHOD__.": found ".count($found_folders)." folders");
			$this->storage->smartsheet['search_folders_in_workspace_cache'][$workspace_id][$query] = array(
				'expires' => time() + $this->cache_expires,
				'data' => $found_folders,
				);

		}

		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $found_folders;

	}

	public function search_for_sheets_workspace($query, $workspace_id = null) {
		$function_start = \function_start();

		if (empty($workspace_id)) {
			if (empty($this->workspace_id)) {
				$this->logger->addError(__FILE__.': '.__METHOD__.": Missing function and class parameter: workspace_id");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return false;
			} else $workspace_id = $this->workspace_id;
		}

		if (
			!empty($this->storage->smartsheet['search_sheets_cache'][$workspace_id][$query]) && 
			$this->storage->smartsheet['search_sheets_cache'][$workspace_id][$query]['expires'] <= time()
			) unset($this->storage->smartsheet['search_sheets_cache'][$workspace_id][$query]);

		if (!empty($this->storage->smartsheet['search_sheets_cache'][$workspace_id][$query])) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": using search_sheets_cache for workspace id: $workspace_id and query: $query");
			$found_sheets = $this->storage->smartsheet['search_sheets_cache'][$workspace_id][$query]['data'];
		} else {
			if (empty($sheets = $this->get_sheets_workspace($workspace_id))) {
				$this->logger->addInfo(__FILE__.': '.__METHOD__.": no sheets to search");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return false;
			}
			$found_sheets = array();
			foreach ($sheets as $sheet_id => $sheet_details) {
				if (preg_match("/$query/", $sheet_details['name']) > 0) {
					if (!empty($publish_result = $this->get_sheet_publish($sheet_details['id']))) $sheet_details = array_merge($sheet_details, $publish_result);
					$found_sheets[] = $sheet_details;
				}
			}
			if (empty($found_sheets)) $this->logger->addInfo(__FILE__.': '.__METHOD__.": no sheets found that match query: $query");
			else $this->logger->addInfo(__FILE__.': '.__METHOD__.": found ".count($found_sheets)." sheets");
			$this->storage->smartsheet['search_sheets_cache'][$workspace_id][$query] = array(
				'expires' => time() + $this->cache_expires,
				'data' => $found_sheets,
				);

		}

		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $found_sheets;

	}

	public function search_for_sheets($query, $folder_id = null) {
		$function_start = \function_start();

		if (empty($folder_id)) {
			if (empty($this->folder_id)) {
				$this->logger->addError(__FILE__.': '.__METHOD__.": Missing function and class parameter: folder_id");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return false;
			} else $folder_id = $this->folder_id;
		}

		if (
			!empty($this->storage->smartsheet['search_sheets_cache'][$folder_id][$query]) && 
			$this->storage->smartsheet['search_sheets_cache'][$folder_id][$query]['expires'] <= time()
			) unset($this->storage->smartsheet['search_sheets_cache'][$folder_id][$query]);

		if (!empty($this->storage->smartsheet['search_sheets_cache'][$folder_id][$query])) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": using search_sheets_cache for folder id: $folder_id and query: $query");
			$found_sheets = $this->storage->smartsheet['search_sheets_cache'][$folder_id][$query]['data'];
		} else {
			if (empty($sheets = $this->get_sheets($folder_id))) {
				$this->logger->addInfo(__FILE__.': '.__METHOD__.": no sheets to search");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return false;
			}
			$found_sheets = array();
			foreach ($sheets as $sheet_id => $sheet_details) {
				if (preg_match("/$query/", $sheet_details['name']) > 0) {
					if (!empty($publish_result = $this->get_sheet_publish($sheet_details['id']))) $sheet_details = array_merge($sheet_details, $publish_result);
					$found_sheets[] = $sheet_details;
				}
			}
			if (empty($found_sheets)) $this->logger->addInfo(__FILE__.': '.__METHOD__.": no sheets found that match query: $query");
			else $this->logger->addInfo(__FILE__.': '.__METHOD__.": found ".count($found_sheets)." sheets");
			$this->storage->smartsheet['search_sheets_cache'][$folder_id][$query] = array(
				'expires' => time() + $this->cache_expires,
				'data' => $found_sheets,
				);

		}

		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $found_sheets;

	}

	public function get_all_content() {
		$function_start = \function_start();

		if (empty($this->access_token)) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing class parameter: access_token");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		$url = "https://api.smartsheet.com/2.0/home";

		$curl = new Curl($this->logger);//$this->curl;
      $curl->method = 'GET';
		$curl->headers = array(
			"Authorization: Bearer ".$this->access_token,
			);
      $curl->response_type = 'json';
      $curl->url = $url;
      $curl->success_http_code = '200';
		$curl->backoff_codes = array('429', '500', '503');
      $curl->caller = __FILE__.': '.__METHOD__;
      if (empty($result = $curl->request())) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": didn't get anything back from get home content");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}

		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $result;

	}

	public function get_sheets_workspace($workspace_id = null) {
		$function_start = \function_start();

		if (empty($this->access_token)) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing class parameter: access_token");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (empty($workspace_id)) {
			if (empty($this->workspace_id)) {
				$this->logger->addError(__FILE__.': '.__METHOD__.": Missing function and class parameter: workspace_id");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return false;
			} else $workspace_id = $this->workspace_id;
		}

		$url = "https://api.smartsheet.com/2.0/workspaces/".$workspace_id;

		$curl = new Curl($this->logger);//$this->curl;
      $curl->method = 'GET';
		$curl->headers = array(
			"Authorization: Bearer ".$this->access_token,
			"Content-Type: application/json",
			);
      $curl->response_type = 'json';
      $curl->url = $url;
      $curl->success_http_code = '200';
		$curl->backoff_codes = array('429', '500', '503');
      $curl->caller = __FILE__.': '.__METHOD__;
      if (empty($result = $curl->request())) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": didn't get anything back from get sheets");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (empty($result['sheets'])) {
			$this->logger->addInfo(__FILE__.': '.__METHOD__.": no sheets");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		$sheets = array();
		foreach ($result['sheets'] as $sheet) {
			$sheets[$sheet['id']] = $sheet;
		}
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $sheets;

	}

	public function get_sheets($folder_id = null) {
		$function_start = \function_start();

		if (empty($this->access_token)) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing class parameter: access_token");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (empty($folder_id)) {
			if (empty($this->folder_id)) {
				$this->logger->addError(__FILE__.': '.__METHOD__.": Missing function and class parameter: folder_id");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return false;
			} else $folder_id = $this->folder_id;
		}

		$url = "https://api.smartsheet.com/2.0/folders/".$folder_id;

		$curl = new Curl($this->logger);//$this->curl;
      $curl->method = 'GET';
		$curl->headers = array(
			"Authorization: Bearer ".$this->access_token,
			"Content-Type: application/json",
			);
      $curl->response_type = 'json';
      $curl->url = $url;
      $curl->success_http_code = '200';
		$curl->backoff_codes = array('429', '500', '503');
      $curl->caller = __FILE__.': '.__METHOD__;
      if (empty($result = $curl->request())) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": didn't get anything back from get sheets");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (empty($result['sheets'])) {
			$this->logger->addInfo(__FILE__.': '.__METHOD__.": no sheets");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		$sheets = array();
		foreach ($result['sheets'] as $sheet) {
			$sheets[$sheet['id']] = $sheet;
		}
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $sheets;

	}

	public function get_folders_in_workspace($workspace_id = null) {
		$function_start = \function_start();

		if (empty($this->access_token)) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing class parameter: access_token");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (empty($workspace_id)) {
			if (empty($this->workspace_id)) {
				$this->logger->addInfo(__FILE__.': '.__METHOD__.": Missing function and class parameter: workspace_id");
			} else $workspace_id = $this->workspace_id;
		}

		$url = "https://api.smartsheet.com/2.0/workspaces/$workspace_id/folders";

		$curl = new Curl($this->logger);//$this->curl;
      $curl->method = 'GET';
		$curl->headers = array(
			"Authorization: Bearer ".$this->access_token,
			"Content-Type: application/json",
			);
      $curl->response_type = 'json';
      $curl->url = $url;
      $curl->success_http_code = '200';
		$curl->backoff_codes = array('429', '500', '503');
      $curl->caller = __FILE__.': '.__METHOD__;
      if (empty($result = $curl->request())) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": didn't get anything back from get folders");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (empty($result['data'])) {
			$this->logger->addInfo(__FILE__.': '.__METHOD__.": no folders");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		$folders = array();
		foreach ($result['data'] as $folder) {
			$folders[$folder['id']] = $folder;
		}
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $folders;

	}

	public function get_workspaces() {
		$function_start = \function_start();

		if (empty($this->access_token)) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing class parameter: access_token");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}

		$url = "https://api.smartsheet.com/2.0/workspaces";


		$curl = new Curl($this->logger);//$this->curl;
      $curl->method = 'GET';
		$curl->headers = array(
			"Authorization: Bearer ".$this->access_token,
			"Content-Type: application/json",
			);
      $curl->response_type = 'json';
      $curl->url = $url;
      $curl->success_http_code = '200';
		$curl->backoff_codes = array('429', '500', '503');
      $curl->caller = __FILE__.': '.__METHOD__;
      if (empty($result = $curl->request())) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": didn't get anything back from get workspaces");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (empty($result['data'])) {
			$this->logger->addInfo(__FILE__.': '.__METHOD__.": no workspaces");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		$workspaces = array();
		foreach ($result['data'] as $workspace) {
			$workspaces[$workspace['id']] = $workspace;
		}
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $workspaces;

	}

	public function get_folders($folder_id = null) {
		$function_start = \function_start();

		if (empty($this->access_token)) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing class parameter: access_token");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (empty($folder_id)) {
			if (empty($this->folder_id)) {
				$this->logger->addInfo(__FILE__.': '.__METHOD__.": Missing function and class parameter: folder_id");
			} else $folder_id = $this->folder_id;
		}

		$url = "https://api.smartsheet.com/2.0/";
		if (!empty($folder_id)) $url .= "folders/".$folder_id;
		else $url .= "home";
		$url .= "/folders";


		$curl = new Curl($this->logger);//$this->curl;
      $curl->method = 'GET';
		$curl->headers = array(
			"Authorization: Bearer ".$this->access_token,
			"Content-Type: application/json",
			);
      $curl->response_type = 'json';
      $curl->url = $url;
      $curl->success_http_code = '200';
		$curl->backoff_codes = array('429', '500', '503');
      $curl->caller = __FILE__.': '.__METHOD__;
      if (empty($result = $curl->request())) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": didn't get anything back from get folders");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (empty($result['data'])) {
			$this->logger->addInfo(__FILE__.': '.__METHOD__.": no folders");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		$folders = array();
		foreach ($result['data'] as $folder) {
			$folders[$folder['id']] = $folder;
		}
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $folders;

	}

	public function get_rows($sheet_id, $filter = null) {
		$function_start = \function_start();

		if (empty($this->access_token)) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing class parameter: access_token");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (strlen($sheet_id) == 0) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing function parameter: sheet_id");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}

		if (empty($columns = $this->get_columns($sheet_id))) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": failed to get all columns");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}

		$url = "https://api.smartsheet.com/2.0/sheets/".$sheet_id;

		$curl = new Curl($this->logger);//$this->curl;
      $curl->method = 'GET';
		$curl->headers = array(
			"Authorization: Bearer ".$this->access_token,
			);
      $curl->response_type = 'json';
      $curl->url = $url;
      $curl->success_http_code = '200';
		$curl->backoff_codes = array('429', '500', '503');
      $curl->caller = __FILE__.': '.__METHOD__;
      if (empty($result = $curl->request())) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": didn't get anything back from get_rows");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (empty($result['rows'])) {
			$this->logger->addInfo(__FILE__.': '.__METHOD__.": no rows to get");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		$rows = array();
		foreach ($result['rows'] as $row) {
			$new_row = $row;
			unset($new_row['cells']);
			foreach ($row['cells'] as $cell) {
				if (isset($filter[$columns[$cell['columnId']]])) {
					if (is_bool($filter[$columns[$cell['columnId']]])) {
						if (!isset($cell['value'])) $cell['value'] = false;
						if ($filter[$columns[$cell['columnId']]] != $cell['value']) continue 2; 
					} else {
						if (
							!isset($cell['displayValue']) || 
							preg_match("/^".$filter[$columns[$cell['columnId']]]."$/", $cell['displayValue']) == 0
							) { continue 2; }
					}
				}
				$new_row['cells'][$columns[$cell['columnId']]] = $cell;
			}
			$rows[] = $new_row;
		}
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $rows;

	}

	public function get_columns($sheet_id) {
		$function_start = \function_start();

		if (strlen($sheet_id) == 0) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing function parameter: sheet_id");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}

		if (
			!empty($this->storage->smartsheet['columns_cache'][$sheet_id]) &&
			$this->storage->smartsheet['columns_cache'][$sheet_id]['expires'] <= time()
			) unset($this->storage->smartsheet['columns_cache'][$sheet_id]);
		if (!empty($this->storage->smartsheet['columns_cache'][$sheet_id])) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": using columns_cache for sheet id: $sheet_id");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return $this->storage->smartsheet['columns_cache'][$sheet_id]['data'];
		}

		if (empty($this->access_token)) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing class parameter: access_token");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}

		$url = "https://api.smartsheet.com/2.0/sheets/".$sheet_id."/columns";

		$curl = new Curl($this->logger);//$this->curl;
      $curl->method = 'GET';
		$curl->headers = array(
			"Authorization: Bearer ".$this->access_token,
			);
      $curl->response_type = 'json';
      $curl->url = $url;
      $curl->success_http_code = '200';
		$curl->backoff_codes = array('429', '500', '503');
      $curl->caller = __FILE__.': '.__METHOD__;
      if (empty($result = $curl->request())) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": didn't get anything back from get_columns");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (empty($result['data'])) {
			$this->logger->addInfo(__FILE__.': '.__METHOD__.": no columns to get: ".$result['message']);
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		$columns = array();
		foreach ($result['data'] as $column)
			$columns[$column['id']] = $column['title'];

		if (!empty($columns)) {
			$this->storage->smartsheet['columns_cache'][$sheet_id] = array(
				'data' => $columns,
				'expires' => time() + $this->cache_expires,
				);
		}

		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $columns;

	}

	public function delete_rows($sheet_id, $row_ids) {
		$function_start = \function_start();

		if (empty($this->access_token)) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing class parameter: access_token");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (strlen($sheet_id) == 0) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing function parameter: sheet_id");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (empty($row_ids)) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing function parameter: row_ids");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}

		$url = "https://api.smartsheet.com/2.0/sheets/".$sheet_id."/rows?ignoreRowsNotFound=true&ids=".implode(',', $row_ids);

		$curl = new Curl($this->logger);//$this->curl;
      $curl->method = 'DELETE';
		$curl->headers = array(
			"Authorization: Bearer ".$this->access_token,
			"Content-Type: application/json",
			);
      $curl->response_type = 'json';
      $curl->url = $url;
      $curl->success_http_code = '200';
		$curl->backoff_codes = array('429', '500', '503');
      $curl->caller = __FILE__.': '.__METHOD__;
      if (empty($result = $curl->request())) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": didn't get anything back from delete row");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if ($result['resultCode'] != 0) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": failed to delete row: ".$result['message']);
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return true;

	}

	public function add_row($sheet_id, $cell_data, $parent_row = null) {
		$function_start = \function_start();

		if (empty($this->access_token)) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing class parameter: access_token");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (strlen($sheet_id) == 0) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing function parameter: sheet_id");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (empty($cell_data)) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": Missing function parameter: cell_data");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (empty($columns = $this->get_columns($sheet_id))) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": failed to get all columns");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (count($cell_data) != count($columns))
			$this->logger->addWarning(__FILE__.': '.__METHOD__.": not the correct number of columns");

		$url = "https://api.smartsheet.com/2.0/sheets/".$sheet_id."/rows";

		$params = array();
		$params[0] = array('toBottom' => true);
		if (isset($parent_row))
			$params[0]['parentId'] = $parent_row;
		else
			$params[0]['expanded'] = false;
		foreach ($columns as $column_id => $column_title) {
			if (empty($cell_data[$column_title])) {
				$this->logger->addInfo(__FILE__.': '.__METHOD__.": missing cell data for column: $column_title");
				continue;
			} 
			$cell_value = $cell_data[$column_title];
			$params[0]['cells'][] = array('columnId' => $column_id, 'value' => $cell_value);
		}

		$curl = new Curl($this->logger);//$this->curl;
      $curl->method = 'POST';
		$curl->headers = array(
			"Authorization: Bearer ".$this->access_token,
			"Content-Type: application/json",
			);
		$curl->params = json_encode($params);
      $curl->response_type = 'json';
      $curl->url = $url;
      $curl->success_http_code = '200';
		$curl->backoff_codes = array('429', '500', '503');
      $curl->caller = __FILE__.': '.__METHOD__;
      if (empty($result = $curl->request())) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": didn't get anything back from add row");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if ($result['resultCode'] != 0) {
			$this->logger->addError(__FILE__.': '.__METHOD__.": failed to add row: ".$result['message']);
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		$new_row = $result['result'][0];
		unset($new_row['cells']);
		foreach ($result['result'][0]['cells'] as $cell) {
			$new_row['cells'][$columns[$cell['columnId']]] = $cell;
		}
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $new_row;

	}

}
