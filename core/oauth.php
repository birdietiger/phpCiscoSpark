<?php

require_once(__DIR__ . "/oauth.providers.php");

class Oauth {

	protected $logger;
	protected $config;
	public $oauth_init;
	protected $oauth_provider;
	public $machine_account;
	public $machine_password;
	public $redirect_uri;
	public $max_redirects;

	public function __construct($logger = null, $config = null, $oauth_provider = null) {
		$this->oauth_provider = $oauth_provider;
		$this->logger = $logger;
		$this->config = $config;
		$this->set_variables();
	}

   protected function set_variables() {

		if (empty($this->config['oauth_init_url'])) $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: url");
		else $this->oauth_init['oauth_init_url'] = $this->config['oauth_init_url'];

		if (empty($this->config['client_id'])) $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: client_id");
		else $this->oauth_init['client_id'] = $this->config['client_id'];

		if (!isset($this->config['oauth_scope']) ||
			strlen($this->config['oauth_scope']) == 0) $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: oauth_scope");
		else $this->oauth_init['oauth_scope'] = $this->config['oauth_scope'];

		if (empty($this->config['machine_account'])) $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: machine_account");
		else $this->machine_account = $this->config['machine_account'];

		if (empty($this->config['machine_password'])) $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: machine_password");
		else $this->machine_password = $this->config['machine_password'];

		if (empty($this->config['oauth_redirect_uri'])) $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: oauth_redirect_uri");
		else $this->oauth_redirect_uri = $this->config['oauth_redirect_uri'];

		if (empty($this->config['oauth_max_redirects'])) $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: oauth_max_redirects");
		else $this->oauth_max_redirects = $this->config['oauth_max_redirects'];

		if (empty($this->config['oauth_response_type'])) $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: oauth_response_type");
		else $this->oauth_init['oauth_response_type'] = $this->config['oauth_response_type'];

		if (empty($this->config['oauth_response_token_type'])) $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: oauth_response_token_type");
		else $this->oauth_init['oauth_response_token_type'] = $this->config['oauth_response_token_type'];

	}

	function get_access_code($oauth_provider) {
	
		global $providers;
	
		if (empty($oauth_provider)) {
			if (empty($this->oauth_provider)) {
				$this->logger->addError(__FILE__.": ".__METHOD__.": missing function parameter: oauth_provider");
				return false;
			} else {
				$oauth_provider = $this->oauth_provider;
			}
		}
		$this->logger->addInfo(__FILE__.": ".__METHOD__.": oauth_provider: $oauth_provider");

		if (empty($this->oauth_init['oauth_init_url'])) {
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": missing class parameter: oauth_init url");
			if (empty($this->config['oauth_init_url'])) {
				$this->logger->addError(__FILE__.": ".__METHOD__.": missing configuration parameter: oauth_init_url");
				return false;
			} else $this->oauth_init['oauth_init_url'] = $this->config['oauth_init_url'];
		}

		if (empty($this->oauth_init['client_id'])) {
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": missing class parameter: oauth_init client_id");
			if (empty($this->config['client_id'])) {
				$this->logger->addError(__FILE__.": ".__METHOD__.": missing configuration parameter: client_id");
				return false;
			} else $this->oauth_init['client_id'] = $this->config['client_id'];
		}

		if (empty($this->oauth_init['oauth_scope'])) {
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": missing class parameter: oauth_init scope");
			if (empty($this->config['oauth_scope'])) {
				$this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: oauth_scope");
			} else $this->oauth_init['oauth_scope'] = $this->config['oauth_scope'];
		}

		if (empty($this->oauth_init['oauth_response_type'])) {
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": missing class parameter: oauth_init response_type");
			if (empty($this->config['oauth_response_type'])) {
				$this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: oauth_response_type");
				return false;
			} else $this->oauth_init['oauth_response_type'] = $this->config['oauth_response_type'];
		}

		if (empty($this->oauth_init['oauth_response_token_type'])) {
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": missing class parameter: oauth_init response_token_type");
			if (empty($this->config['oauth_response_token_type'])) {
				$this->logger->addWarning(__FILE__.": ".__METHOD__.": missing configuration parameter: oauth_response_token_type");
				return false;
			} else $this->oauth_init['oauth_response_token_type'] = $this->config['oauth_response_token_type'];
		}

		if (empty($this->machine_account)) {
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": missing class parameter: machine_account");
			if (empty($this->config['machine_account'])) {
				$this->logger->addError(__FILE__.": ".__METHOD__.": missing configuration parameter: machine_account");
				return false;
			} else $this->machine_account = $this->config['machine_account'];
		}

		if (empty($this->machine_password)) {
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": missing class parameter: machine_password");
			if (empty($this->config['machine_password'])) {
				$this->logger->addError(__FILE__.": ".__METHOD__.": missing configuration parameter: machine_password");
				return false;
			} else $this->machine_password = $this->config['machine_password'];
		}

		if (empty($this->oauth_redirect_uri)) {
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": missing class parameter: oauth_redirect_uri");
			if (empty($this->config['oauth_redirect_uri'])) {
				$this->logger->addError(__FILE__.": ".__METHOD__.": missing configuration parameter: oauth_redirect_uri");
				return false;
			} else $this->oauth_redirect_uri = $this->config['oauth_redirect_uri'];
		}

		if (empty($this->oauth_max_redirects)) {
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": missing class parameter: oauth_max_redirects");
			if (empty($this->config['oauth_max_redirects'])) {
				$this->logger->addError(__FILE__.": ".__METHOD__.": missing configuration parameter: oauth_max_redirects");
				return false;
			} else $this->oauth_max_redirects = $this->config['oauth_max_redirects'];
		}

		$oauth_init_url = $this->oauth_init['oauth_init_url'].'?'.
			'client_id='.urlencode($this->oauth_init['client_id']).'&'.
			'scope='.urlencode($this->oauth_init['oauth_scope']).'&'.
			'redirect_uri='.urlencode($this->oauth_redirect_uri).'&'.
			'response_type='.urlencode($this->oauth_init['oauth_response_type']);
		$machine_account = $this->machine_account;
		$machine_password = $this->machine_password;
		$redirect_uri = $this->oauth_redirect_uri;
		$max_redirects = $this->oauth_max_redirects;
		$response_token_type = $this->oauth_init['oauth_response_token_type'];
		$response_type = $this->oauth_init['oauth_response_type'];
	
		$curl = curl_init();
		$curl_options = array(
			CURLOPT_NOBODY => false,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_BINARYTRANSFER => true,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_VERBOSE => false,
			CURLOPT_HEADER => true,
			CURLINFO_HEADER_OUT => true,
			CURLOPT_TIMEOUT => 90,
			CURLOPT_COOKIEFILE => '',
			);
	
		$oauth_steps = $providers[$oauth_provider]['steps'];
	
		foreach (array_keys($oauth_steps) as $step_no) {
	
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": step: $step_no");
			if (!isset($oauth_steps[$step_no]['url'])) { $this->logger->addError(__FILE__.": ".__METHOD__.": missing oauth step parameter: url"); return false; }
			if (!isset($oauth_steps[$step_no]['post_data'])) { $this->logger->addError(__FILE__.": ".__METHOD__.": missing oauth step parameter: post_data"); return false; }
			if (!isset($oauth_steps[$step_no]['find_form_name'])) { $this->logger->addError(__FILE__.": ".__METHOD__.": missing oauth step parameter: find_form_name"); return false; }
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": step details: ".json_encode($oauth_steps[$step_no]));
		
			$url = ($step_no == 0) ? $oauth_init_url : $oauth_steps[$step_no]['url'];
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": url: $url");
		
			$post_data_string = '';
			$search = array('%machine_account%', '%machine_password%', '%redirect_uri%', '%response_type%', '%response_token_type%');
			$replace = array($machine_account, $machine_password, $redirect_uri, $response_type, $response_token_type);
			foreach (array_keys($oauth_steps[$step_no]['post_data']) as $post_data_name) {
				$post_data_value = str_replace($search, $replace, $oauth_steps[$step_no]['post_data'][$post_data_name]);
				$post_data_string .= urlencode($post_data_name).'='.urlencode($post_data_value).'&';
			}
			$post_data_string = rtrim($post_data_string, '&');
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": post_data_string: $post_data_string");
		
			for ($redirect_count = 0; $url; $redirect_count++) {
		
				if ($redirect_count == $max_redirects) {
					$this->logger->addError(__FILE__.": ".__METHOD__.": exceeded max redirects: $redirect_count");
					curl_close($curl);
					return false;
				}
		
				$curl_options[CURLOPT_URL] = $url;
				if (!empty($post_data_string)) $curl_options[CURLOPT_POSTFIELDS] = $post_data_string;
				if (!empty($url_referer)) $curl_options[CURLOPT_REFERER] = $url_referer;
				curl_setopt_array($curl, $curl_options);
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": curl_options: ".json_encode($curl_options));
		
				if(empty($response = curl_exec($curl))) {
					$this->logger->addError(__FILE__.": ".__METHOD__.": failed to connect: $url");
					return false;
				}
		
				$curl_info = curl_getinfo($curl);
				$request_header = $curl_info['request_header'];
				$this->logger->addInfo(__FILE__.": ".__METHOD__.": request_header: $request_header");
				$response_header = substr($response, 0, $i = $curl_info['header_size']);
				$this->logger->addInfo(__FILE__.": ".__METHOD__.": response_header: $response_header");
		
				$url_referer = $url;
				$url = '';
				if (
					$curl_info['http_code'] == 302 && 
					preg_match('/\nlocation:\s*(\S+)\s/i', $response_header, $matches)) {
						$this->logger->addDebug(__FILE__.": ".__METHOD__.": redirect location: ".$matches[1]);
						if (empty(preg_match("/^".urlencode($redirect_uri).".*$/", urlencode($matches[1])))) $url = $matches[1];
						else {
							curl_close($curl);
							parse_str(parse_url($matches[1], PHP_URL_QUERY), $location_params);
							$this->logger->addDebug(__FILE__.": ".__METHOD__.": query location params: ".json_encode($location_params));
							if (empty($location_params)) {
								parse_str(parse_url($matches[1], PHP_URL_FRAGMENT), $location_params);
								$this->logger->addDebug(__FILE__.": ".__METHOD__.": fragment location params: ".json_encode($location_params));
							}
							if (empty($location_params)) {
								$this->logger->addCritical(__FILE__.": ".__METHOD__.": made it through oauth flow and no location params provided: ".$matches[1]);
								return false;
							}
							if (empty($response_token = $location_params[$response_token_type])) {
								$this->logger->addCritical(__FILE__.": ".__METHOD__.": made it through oauth flow and response token type wasn't found: ".$response_token_type);
								return false;
							} else {
								if (!empty($location_params['expires_in'])) $expires_in = $location_params['expires_in'];
								else $expires_in = null;
								if (!empty($location_params['token_type'])) $token_type = $location_params['token_type'];
								else $token_type = null;
								$response = [ 'access_token' => $response_token, 'expires_in' => $expires_in, 'token_type' => $token_type ];
								$this->logger->addInfo(__FILE__.": ".__METHOD__.": response: ".json_encode($response));
								return $response;
							}
						}
				} else {
					$response_body = substr($response, $i);
					$this->logger->addDebug(__FILE__.": ".__METHOD__.": response_body: $response_body");
				}
			}
		
			$dom = new DOMDocument();
			@$dom->loadHTML($response_body);
			$xpath = new DOMXPath($dom);
		
			if (empty($oauth_steps[$step_no]['find_form_name'])) $form_query = "form";
			else $form_query = "form[@name='".$oauth_steps[$step_no]['find_form_name']."']";
			if (empty($oauth_steps[$step_no+1]['url'])) {
				$form = $xpath->query("//$form_query");
				if (empty($form->item(0))) {
					$this->logger->addCritical(__FILE__.": ".__METHOD__.": oauth process has failed. try in this order: 1) confirm config settings, 2) test logging in on the website manually, 3) turn logging level to DEBUG, 4) find a TME who knows oauth");
					return false;
				}
				$url = trim($form->item(0)->getAttribute('action'));
				if (empty(parse_url($url, PHP_URL_HOST))) {
					$host = preg_replace("/^.*host:\s*(\S+)\s.*$/si", "$1", $curl_info['request_header']);
					if ($curl_info['ssl_verify_result'] == 0) $scheme = 'https';
					else $scheme = 'http';
					$url = $scheme.'://'.$host.'/'.ltrim($url, '/');
				}
				$oauth_steps[$step_no+1]['url'] = $url;
			}
	
			$inputs = $xpath->query("//$form_query//input");
			foreach ($inputs as $input) {
				if (
					empty($input_name = trim($input->getAttribute('name'))) ||
					isset($oauth_steps[$step_no+1]['post_data'][$input_name]) ||
					in_array($input_name, $oauth_steps[$step_no]['ignore_input'])
					) continue;
				$input_value = trim($input->getAttribute('value')) ?: '';
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": input found: $input_name = $input_value");
				$oauth_steps[$step_no+1]['post_data'][$input_name] = $input_value;
			}
		
		}
	
		curl_close($curl);
		return false;
	
	}

}

?>
