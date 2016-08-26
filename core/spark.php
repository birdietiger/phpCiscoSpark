<?php

class Spark {

	protected $cache_updated = false;
	protected $access_token;
	protected $is_sparkbot = false;
	protected $sparkbot_domains = [ 'sparkbot.io' ];
	public $existing_rooms;
	public $admins;
	protected $bot_triggers;
	protected $enable_cache = true;
	protected $cache_expires_in = 3600;
	protected $get_room_membership = false;
	protected $show_complete_invalid_command = false;
	protected $room_before_update;
	protected $config_file;
	protected $passive = false;
	protected $is_cli;
   public $token_file;
   public $oauth_provider;
	protected $post_webhook_json;
   protected $access_token_expiration_lookahead = 3600; // secs
   protected $refresh_token_expiration_lookahead = 7; // days
   protected $default_allowed_domains = [];
   protected $default_trusted_domains = [];
	public $logger;
	public $config;
	protected $broker;
	protected $storage;
	protected $spark_endpoints;
	protected $files_storage;
	protected $extensions;
	protected $new_room_announce = true;
	public $api_url;
	protected $message_version;
	public $tokens_url;
	public $oauth_redirect_uri;
	public $client_id;
	public $client_secret;
	public $tokens;
	public $me;
	protected $registered_shutdown_function = false;
	protected $existing_webhooks;
	protected $webhook_target_topic = '/data/[email]/spark/webhooks/[id]';
	protected $bot_webhook_name_prefix = 'phpCiscoSpark BOT for ';
	protected $loop_timers = [
		'sleep' => 100000, // usecs
		'garbage' => 500000, // usecs
		'token' => 1800, // secs
		'storage' => 10, // secs
		'clean_cache' => 300, // secs
		'save_cache' => 60, // secs
		];
	protected $reload_subscriptions = false;
	public $enabled_rooms = [];
	public $super_users_room;
	protected $bot_control_command = 'bot';
	protected $me_mention_regex;
	public $spark_api_slow_time = 5; // secs
	protected $spark_api_slow = 0;
	public $spark_api_slow_max = 30;
	protected $spark_api_slow_reported = false;
	protected $ipc_prefix = 'ipc';
	protected $max_ipc_channel_seed = 1800;
	protected $rotate_ipc_channel_at;
	protected $ipc_channel_name;
	public $curl;
	protected $multithreaded = false;
	protected $threads;
	protected $overclock = 1;
	protected $worker_pool;
	protected $bot = false;
	protected $get_all_webhook_data = true;
	protected $detect_malformed_commands = true;
	protected $delete_last_help = true;
   protected $user_management = false;
	public $backoff = false;
	protected $report_slow = false;
   protected $default_enabled_room = false;
   protected $default_require_mention = false;
	protected $direct_help = false;
	protected $detect_unknown_commands = false;
	protected $delete_invalid_commands = false;
	protected $webhook_direct = true;
	protected $get_room_type = 'all';
	protected $room_types = ['all', 'direct', 'group'];

   public function __construct($logger = null, $config = null, $files_storage = null, $extensions = null, $storage = null, $broker = null) {
		$function_start = \function_start();
		$this->logger = $logger;
		$this->config = $config;
		$this->files_storage = $files_storage;
		$this->extensions = $extensions;
		$this->storage = $storage;
		$this->broker = $broker;
		$this->init();
		register_shutdown_function(array($this, 'bot_init'));
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
   }

	protected function init() {

		$function_start = \function_start();

		if (!class_exists('Curl')) {
			$this->logger->addCritical(__FILE__.": ".__METHOD__.": Curl class is missing. make sure to include Curl handler");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			exit();
		}
		$this->extensions->http = $this->curl = new Curl($this->logger);

		if (class_exists('Callback')) $this->multithreaded = true;

		$this->is_cli = $this->is_cli();
		if (!$this->is_cli) {
			$this->check_post_webhook();
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": this is a web request");
		}

		$this->set_variables();

		if (!empty($this->access_token)) {
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": access_token set in config file, using that to set tokens");
			if (empty($this->set_tokens($this->access_token))) {
				$this->logger->addCritical(__FILE__.": ".__METHOD__.": failed to set tokens");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				exit();
			}
		} else if ($this->is_sparkbot) {
			$this->logger->addWarning(__FILE__.": ".__METHOD__.": sparkbot.io and no access_token, so can't set tokens. must be set in code");
		} else if (
			!empty($this->machine_account)
			&& !empty($this->machine_password)
			) {
			if (!empty($tokens = $this->get_bot_tokens())) {
				if (empty($this->set_tokens($tokens['access_token'], $tokens['expires_in'], $tokens['refresh_token'], $tokens['refresh_token_expires_in'], false))) {
					$this->logger->addCritical(__FILE__.": ".__METHOD__.": failed to set tokens");
					$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
					exit();
				}
			} else {
				$this->logger->addCritical(__FILE__.": ".__METHOD__.": couldn't get bot tokens");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				exit();
			}
		} else
			$this->logger->addWarning(__FILE__.": ".__METHOD__.": nothing to set tokens with. must be set in code");

		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));

	}

	public function bot_init() {
		$function_start = \function_start();

		$this->set_bot_triggers();
		if ($this->bot) $this->set_bot_variables();
		if ($this->enable_cache && !empty($this->super_users_room)) {
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": populating super users room cache");
			$this->memberships('GET', [
				'roomId' => $this->super_users_room,
				]);
		}

		if (!empty($this->bot_triggers['stop']['enabled']['callbacks'])) {
			foreach ($this->bot_triggers['stop']['enabled']['callbacks'] as $callback) {
				register_shutdown_function(function() use ($callback) {
					if ($this->multithreaded) {
						$this->worker_pool->submit(
							new Callback($callback, $this, $this->logger, $this->storage, $this->extensions, null)
							);
					} else {
						$callback($this, $this->logger, $this->storage, $this->extensions, null);
					}
				});
				$this->logger->addInfo(__FILE__.": ".__METHOD__.": registered a stop function");
			}
      }

		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
	}

	protected function is_cli() {
		$function_start = \function_start();
		if (PHP_SAPI === 'cli') {
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": this is cli");
			$return = true;
		} else {
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": this is not cli");
			$return = false;
		}
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $return;
	}

	protected function check_post_webhook() {
		$function_start = \function_start();

		if (empty($post_data = file_get_contents('php://input'))) {
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": no post data provided");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (empty(preg_match("/^application\/json[$;]/i", $_SERVER['CONTENT_TYPE']))) {
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": content type isn't application/json");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (empty($json_data = json_decode($post_data, true))) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": not valid json");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (
			!empty($json_data['name'])
			&& !empty($json_data['targetUrl'])
			&& !empty($json_data['resource'])
			&& !empty($json_data['event'])
			&& !empty($json_data['data'])
			) {
				$this->logger->addInfo(__FILE__.": ".__METHOD__.": appears to be webhook");
				$this->post_webhook_json = $post_data;
		}

		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return true;

	}

	protected function fork_get_bot_tokens() {
		$function_start = \function_start();

		if (file_exists($this->token_file.'.lock')) {
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": token_file lock exists. get_bot_tokens process is already running. hopefully no zombie?");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		} else {
			if (file_put_contents($this->token_file.'.lock', '') !== false) {
				$helper = dirname(__DIR__).'/helper.php';
				$cmd = 'nohup php -r \'$config_file = "'.$this->config_file.'"; require "'.$helper.'";\' > /dev/null 2> /dev/null & echo $!';
				$this->logger->addInfo(__FILE__.": ".__METHOD__.": fork command: $cmd");
				$pid = exec($cmd);
				if (empty($pid))
					$this->logger->addError(__FILE__.": ".__METHOD__.": failed to fork helper.php");
				else
					$this->logger->addInfo(__FILE__.": ".__METHOD__.": forked helper.php PID: $pid");
			} else
				$this->logger->addAlert(__FILE__.": ".__METHOD__.": couldn't create lock file for token_file. didn't get_bot_tokens");
		}

		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
	}

	protected function load_state_file($file_name) {
		$function_start = \function_start();
		if (is_file($this->$file_name)) {
			if (!is_writable($this->$file_name)) $this->logger->addCritical(__FILE__.": ".__METHOD__.": !!! can't write to the $file_name, the bot can't maintain state if restarted. !!!");
			if (empty($state_json = file_get_contents($this->$file_name)))
				$this->logger->addError(__FILE__.": couldn't read $file_name");
			else {
				if (empty($state = json_decode($state_json, true)))
					$this->logger->addError(__FILE__.": couldn't decode $file_name contents");
				else {
					$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
					return $state;
				}
			}
		} else $this->logger->addWarning(__FILE__.": $file_name doesn't exist");
		clearstatcache();
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return false;
	}

   protected function set_variables() {
		$function_start = \function_start();

		require_once(__DIR__.'/spark.api.def.php');

		$this->spark_endpoints = $spark_endpoints;

		if (empty($this->config['config_file'])) $this->logger->addWarning(__FILE__.": ".__METHOD__." missing configuration parameters: config_file");
		else $this->config_file = $this->config['config_file'];

		if (empty($this->config['spark']['machine_account'])) $this->logger->addWarning(__FILE__.": ".__METHOD__." missing configuration parameters: machine_account");
		else {
			$this->machine_account = $this->config['spark']['machine_account'];
			if (preg_match('/@('.implode('|', $this->sparkbot_domains).')$/', $this->machine_account, $matches) > 0) {
				$this->logger->addInfo(__FILE__.": ".__METHOD__.": this is a sparkbot, domain: ".$matches[1]);
				$this->is_sparkbot = true;
			}
		}

		if (empty($this->config['spark']['machine_password'])) $this->logger->addWarning(__FILE__.": ".__METHOD__." missing configuration parameters: machine_password");
		else $this->machine_password = $this->config['spark']['machine_password'];

		if (empty($this->config['spark']['api_url'])) $this->logger->addWarning(__FILE__.": ".__METHOD__." missing configuration parameters: api_url");
		else $this->api_url = $this->config['spark']['api_url'];

		if (empty($this->message_version = preg_replace("/^.*\/v([0-9]+)\/$/", "$1", $this->config['spark']['api_url']))) 
			$this->logger->addWarning(__FILE__.": ".__METHOD__." missing api version from api_url");

		if (empty($this->config['spark']['tokens_url'])) $this->logger->addWarning(__FILE__.": ".__METHOD__." missing configuration parameters: tokens_url");
		else $this->tokens_url = $this->config['spark']['tokens_url'];

		if (empty($this->config['spark']['oauth_redirect_uri'])) $this->logger->addWarning(__FILE__.": ".__METHOD__." missing configuration parameters: oauth_redirect_uri");
		else $this->oauth_redirect_uri = $this->config['spark']['oauth_redirect_uri'];

		if (empty($this->config['spark']['client_id'])) $this->logger->addWarning(__FILE__.": ".__METHOD__." missing configuration parameters: client_id");
		else $this->client_id = $this->config['spark']['client_id'];

		if (empty($this->config['spark']['client_secret'])) $this->logger->addWarning(__FILE__.": ".__METHOD__." missing configuration parameters: client_secret");
		else $this->client_secret = $this->config['spark']['client_secret'];

		if (empty($this->config['spark']['oauth_provider'])) $this->logger->addWarning(__FILE__.": ".__METHOD__." missing configuration parameters: oauth_provider");
		else $this->oauth_provider = $this->config['spark']['oauth_provider'];

		if (empty($this->config['spark']['token_file'])) $this->logger->addWarning(__FILE__.": ".__METHOD__." missing configuration parameter: token_file");
      else $this->token_file = $this->config['spark']['token_file'];

		if (empty($this->config['spark']['access_token'])) {
			$this->logger->addWarning(__FILE__.": ".__METHOD__." missing configuration parameters: access_token");
			if ($this->is_sparkbot) $this->logger->addWarning(__FILE__.": ".__METHOD__." without access_token in config, it must be set in app code");
		} else $this->access_token = $this->config['spark']['access_token'];

		if (!isset($this->config['spark']['backoff']) || !is_bool((bool) $this->config['spark']['backoff'])) $this->logger->addWarning(__FILE__.": ".__METHOD__." missing configuration parameters: backoff");
		else $this->backoff = (bool) $this->config['spark']['backoff'];

	}

	public function listen() {
		$function_start = \function_start();

		if (!$this->bot) {
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": not a bot, so done listening");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}

		if (empty($this->existing_rooms = $this->get_all_rooms())) {
  	   	$this->logger->addWarning(__FILE__.": ".__METHOD__.": not a member of any rooms");
		}

		if ($this->enable_cache) {
			if (!empty($this->cache['rooms'])) {
				foreach ($this->cache['rooms'] as $room_id => $details) {
					if (!isset($this->existing_rooms[$room_id]))
						unset($this->cache['rooms'][$room_id]);
				}
			}
			if (
				$this->get_room_membership
				&& !empty($this->existing_rooms)
				) {
				$this->logger->addInfo(__FILE__.": ".__METHOD__.": getting membership to populate cache");
				foreach ($this->existing_rooms as $room_id => $room_details)
					$this->memberships('GET', ['roomId' => $room_id]);
			}
		}

		if (empty($this->create_all_webhooks())) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": failed to create all webhooks, so can't start listening");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}

		$this->broker->username = $this->me['emails'][0];
		$this->broker->password = $this->tokens['access_token'];
		if (empty($this->broker_client = $this->broker->process_subscribes())) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": failed to create broker client");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}

		$next_token_check = time() + $this->loop_timers['token'];
		$next_garbage_check = microtime(true)*1000000 + $this->loop_timers['garbage'];
		$next_storage_check = time() + $this->loop_timers['storage'];
		$next_timer_check = time() + 1; // not needed unless support for usec timer is added
		$next_clean_cache_check = time() + $this->loop_timers['clean_cache'];
		$next_save_cache_check = time() + $this->loop_timers['save_cache'];

		if ($this->multithreaded) {
			$this->threads = get_cores() * floor($this->overclock);
			$this->worker_pool = new WorkerPool($this->threads, \CallbackWorker::class);//, [$this->config, $this->logger]);
		}

		if (!empty($this->bot_triggers['start']['enabled']['callbacks'])) {
			foreach ($this->bot_triggers['start']['enabled']['callbacks'] as $callback)
				$callback($this, $this->logger, $this->storage, $this->extensions, null);
      }

		$this->save_cache();

		$this->logger->addAlert(__FILE__.": ".__METHOD__.": BOT is running");

		$loop = true;
		while ($loop) {

			// only use for testing performance
			//$this->broker_client->proc(false);
			//usleep($this->loop_timers['sleep']);
			//continue;

			// save storage perm data
			if ($next_storage_check <= time()) {
				$this->storage->save();
				$next_storage_check = time() + $this->loop_timers['storage'];
			}

			//clean up and then save cache
			if ($this->enable_cache) {
				if ($next_clean_cache_check <= time()) {
					$this->clean_cache();
					$next_clean_cache_check = time() + $this->loop_timers['clean_cache'];
				}
				if ($next_save_cache_check <= time()) {
					$this->save_cache();
					$next_save_cache_check = time() + $this->loop_timers['save_cache'];
				}
			}

			// keep tokens fresh
			if (
				$next_token_check <= time()
				&& (
					!$this->is_sparkbot
					|| !empty($this->access_token)
					)
				) {
				if (!empty($tokens = $this->get_bot_tokens()))
					$this->set_tokens($tokens['access_token'], $tokens['expires_in'], $tokens['refresh_token'], $tokens['refresh_token_expires_in'], false);
				else
					$this->logger->addError(__FILE__.": ".__METHOD__.": couldn't get bot tokens");
				$next_token_check = time() + $this->loop_timers['token'];
			}

			// check if ipc channel exists and needs to be rotated
			if (!empty($this->rotate_ipc_channel_at)) {
				if ($this->rotate_ipc_channel_at <= time()) {
					$this->logger->addInfo(__FILE__.": ".__METHOD__.": Time to rotate IPC channel name");
					if (!empty($this->subscribe_ipc_channel()))
						$this->logger->addInfo(__FILE__.": ".__METHOD__.": Rotated IPC channel name");
					else
						$this->logger->addCritical(__FILE__.": ".__METHOD__.": Failed to rotate IPC channel name. will try again");
				}
			}

			// bot timer triggers
			if ($next_timer_check <= time() && !empty($this->bot_triggers['at'])) {
				foreach ($this->bot_triggers['at'] as $timer_time => $timer_params) {
					if ($timer_time <= time()) {
						$event = new StdClass();
						$event->at = $timer_time;
						foreach ($timer_params['callbacks'] as $callback_key => $callback) {
							if (!empty($new_timer_time = $callback($this, $this->logger, $this->storage, $this->extensions, $event))) {
								if ($new_timer_time > time()) {
									if (empty($this->bot_triggers['at'][$new_timer_time])) {
										$this->bot_triggers['at'][$new_timer_time] = $timer_params;
										$this->bot_triggers['at'][$new_timer_time]['callbacks'] = array($callback);
									} else $this->bot_triggers['at'][$new_timer_time]['callbacks'][] = $callback;
									$this->logger->addInfo(__FILE__.": ".__METHOD__.": snoozed timer: $new_timer_time callback: ".json_encode($callback));
								} else $this->logger->addError(__FILE__.": ".__METHOD__.": new timer is in the past: $new_timer_time callback: ".json_encode($callback));
							}
							unset($this->bot_triggers['at'][$timer_time]['callbacks'][$callback_key]);
							if (count($this->bot_triggers['at'][$timer_time]['callbacks']) == 0) unset($this->bot_triggers['at'][$timer_time]);
						}
					}
				}
				$next_timer_check = time() + 1;
			}

			// check for webhook messages
			$this->broker_client->proc(false);

			// collect worker garbage
			if ($this->multithreaded && $next_garbage_check <= microtime(true)*1000000) {
				$this->collect_worker_garbage();
				$next_garbage_check = microtime(true)*1000000 + $this->loop_timers['garbage'];
			}

			// update message subscriptions
			if ($this->reload_subscriptions) {
				foreach ($this->broker->subscribe_topics as $topic => $topic_details) {
					if ($topic_details === false) {
						if ($this->broker_client->unsubscribe(array($topic => $topic_details))) {
							unset($this->broker->subscribe_topics[$topic]);
							unset($this->broker->subscribed_to_topics[$topic]);
							$this->logger->addInfo(__FILE__.": ".__METHOD__.": unsubscribed from topic: ".$topic);
							continue;
						} else
							$this->logger->addError(__FILE__.": ".__METHOD__.": failed to unsubscribe to topic: ".$topic);
					}
	            if (!empty($this->broker->subscribed_to_topics[$topic])) continue;
					if (!$this->broker_client->subscribe(array($topic => $topic_details)))
						$this->logger->addError(__FILE__.": ".__METHOD__.": failed to subscribe to topic: ".$topic);
					else $this->subscribed_to_topics[$topic] = true;
				}
				$this->reload_subscriptions = false;
			}

			// sleep before looping
			usleep($this->loop_timers['sleep']);

		}

      $broker_client->close();
      unset($broker_client);

		$this->logger->addInfo(__FILE__.": ".__METHOD__.": No longer listening for bot triggers");

	}

	protected function collect_worker_garbage() {
		$this->worker_pool->collect(function(Callback $job){
			if ($job->isGarbage()) {
				$perm_diff = array_diff_assoc_recursive($job->storage->perm, $job->storage_perm_orig);
				$this->storage->perm = array_replace_recursive($this->storage->perm, $perm_diff);
				$temp_diff = array_diff_assoc_recursive($job->storage->temp, $job->storage_temp_orig);
				$this->storage->temp = array_replace_recursive($this->storage->temp, $temp_diff);
				foreach ($this->config['extensions'] as $extension => $extension_state) {
					if (!empty($extension_state)) {
						$this->storage->$extension = $job->storage->$extension;
						unset($job->storage->$extension);
					}
				}
				unset($job->storage);
			}
			return $job->isGarbage();
		});
	}

	protected function set_me_mention_regex() {
		$this->me_mention_regex = '('.
			preg_quote($this->me['displayName'], '/').'|'.
			preg_quote(implode('|', $this->me['emails']), '/').'|'.
			preg_quote(preg_replace("/^([^\s]+\s[^\s]).*/", '$1', $this->me['displayName']), '/').'|'.
			preg_quote(preg_replace("/^([^\s]+).*/", '$1', $this->me['displayName']), '/').
			')';
	}

	public function people($method, $params = null) {
		$function_start = \function_start();
		$api = 'people';
      if (empty(list($api_url, $params) = $this->prepare_params($api, $method, $params))) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
      if (!$this->validate_params($api, $method, $params)) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $this->spark_api($method, $api, $api_url, $params);
	}

	public function rooms($method, $params = null) {
		$function_start = \function_start();
		$api = 'rooms';
      if (empty(list($api_url, $params) = $this->prepare_params($api, $method, $params))) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
      if (!$this->validate_params($api, $method, $params)) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $this->spark_api($method, $api, $api_url, $params);
	}

	public function memberships($method, $params = null) {
		$function_start = \function_start();
		$api = 'memberships';
      if (empty(list($api_url, $params) = $this->prepare_params($api, $method, $params))) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
      if (!$this->validate_params($api, $method, $params)) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $this->spark_api($method, $api, $api_url, $params);
	}

	public function messages($method, $params = null) {
		$function_start = \function_start();
		$api = 'messages';
      if (empty(list($api_url, $params) = $this->prepare_params($api, $method, $params))) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
      if (!is_array($params = $this->prepare_message_text($params))) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
      if (!is_array($params = $this->prepare_message_files($params))) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
      if (!$this->validate_params($api, $method, $params)) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $this->spark_api($method, $api, $api_url, $params);
	}

	public function webhooks($method, $params = null, $get_all_webhooks = true) {
		$function_start = \function_start();
		$api = 'webhooks';
      if (empty(list($api_url, $params) = $this->prepare_params($api, $method, $params))) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (!empty($webhook_details = $this->validate_webhook($method, $params, $get_all_webhooks))) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return $webhook_details;
		}
      list($params, $orig_target_url) = $this->prepare_webhook_target_url($method, $params);
      if (!$this->validate_params($api, $method, $params)) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (!empty($result = $this->spark_api($method, $api, $api_url, $params))) 
			$this->process_webhook($method, $params, $orig_target_url, $result);
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $result;
	}

	protected function process_webhook($method, $params, $orig_target_url, $result) {
		$function_start = \function_start();
		if ($method == 'POST') {
			$this->existing_webhooks[$result['id']] = $result;
			if (!empty($this->broker)) {
				$new_topic = $this->encode_topic($result['id'], $this->me['emails'][0]);
				if (!empty($this->broker->subscribe_topics[$new_topic])) {
					if (!in_array($orig_target_url, $this->broker->subscribe_topics[$new_topic]['params']['callbacks']))
						$this->broker->subscribe_topics[$new_topic]['params']['callbacks'][] = $orig_target_url;
				} else {
					$this->broker->subscribe_topics[$new_topic] = array(
						'qos' => 0,
						'function' => array($this, 'parse_webhook_message'), 
						'params' => array(
							'callbacks' => array($orig_target_url),
							),
						);
				}
				$this->logger->addInfo(__FILE__.": ".__METHOD__.": added callback: ".$params['targetUrl']." to topic: $new_topic");
				$this->reload_subscriptions = true;
			}
		} else if ($method == 'PUT') {
			$this->existing_webhooks[$result['id']] = $result;
		} else if ($method == 'DELETE') {
			if (isset($this->existing_webhooks[$params['webhookId']])) {
				$deleted_topic = $this->encode_topic($params['webhookId'], $this->me['emails'][0]);
				if (isset($this->broker->subscribe_topics[$deleted_topic])) {
					unset($this->broker->subscribe_topics[$deleted_topic]);
					$this->reload_subscriptions = true;
				}
				unset($this->existing_webhooks[$params['webhookId']]);
			}
		}
	}

	protected function validate_webhook($method, $params, $get_all_webhooks = true) {
		$function_start = \function_start();
		if ($method != 'GET') {
			if ($get_all_webhooks) $this->existing_webhooks = $this->get_all_webhooks();
			if ($method == 'POST') {
				list($webhook_details, $topic) = $this->does_webhook_exist($params, $this->existing_webhooks);
				if (!empty($webhook_details) && !empty($topic)) {
					if (!empty($topic) && !empty($this->broker)) {
						$this->logger->addInfo(__FILE__.": ".__METHOD__.": adding webhook to subscribe topics: $topic");
						if (!empty($this->broker->subscribe_topics[$topic])) {
							if (!in_array($params['targetUrl'], $this->broker->subscribe_topics[$topic]['params']['callbacks']))
								$this->broker->subscribe_topics[$topic]['params']['callbacks'][] = $params['targetUrl'];
						} else {
							$this->broker->subscribe_topics[$topic] = array(
								'qos' => 0,
								'function' => array($this, 'parse_webhook_message'), 
								'params' => array(
									'callbacks' => array($params['targetUrl']),
									),
								);
						}
						$this->reload_subscriptions = true;
					}
					$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
					return $webhook_details;
				}
			}
		}
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return '';
	}

	protected function does_webhook_exist($params, $existing_webhooks) {
		$function_start = \function_start();
		if (!empty($params['targetUrl']) && is_callable($params['targetUrl'])) {
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": webhook targetUrl is a function");
			$has_topic = true;
			$webhook_target_topic = str_replace("[email]", $this->me['emails'][0], $this->webhook_target_topic);
			$target_url = preg_replace("/{topic}/", $webhook_target_topic, $this->broker->target_url);
		} else $target_url = $params['targetUrl'];
		foreach (array_keys($existing_webhooks) as $existing_webhook_key) {
			if (empty($params['filter'])) $params['filter'] = null;
			if (
				$existing_webhooks[$existing_webhook_key]['targetUrl'] == $target_url &&
				$existing_webhooks[$existing_webhook_key]['name'] == $params['name'] &&
				$existing_webhooks[$existing_webhook_key]['resource'] == $params['resource'] &&
				$existing_webhooks[$existing_webhook_key]['event'] == $params['event'] &&
				$existing_webhooks[$existing_webhook_key]['filter'] == $params['filter']
				) {
				$this->logger->addInfo(__FILE__.": ".__METHOD__.": webhook already exists");
				if (!empty($has_topic)) $topic = $this->encode_topic($existing_webhook_key, $this->me['emails'][0]);
				else $topic='';
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return array($existing_webhooks[$existing_webhook_key], $topic);
			}
		}
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return array('', '');
	}

	public function get_all_rooms() {
		$function_start = \function_start();
		$existing_rooms = array();
		if ($this->get_room_type == 'all')
			$result = $this->rooms('GET', []);
		else
			$result = $this->rooms('GET', ['type' => $this->get_room_type]);
		if (!empty($result)) {
			foreach ($result['items'] as $room_details) {
				$existing_rooms[$room_details['id']] = $room_details;
				if (empty($this->existing_rooms[$room_details['id']])) {
					if (empty($this->enabled_rooms[$room_details['id']])) $this->enabled_rooms[$room_details['id']] = $this->default_enabled_room;
					if (empty($this->allowed_domains[$room_details['id']])) $this->allowed_domains[$room_details['id']] = $this->default_allowed_domains;
					if (empty($this->trusted_domains[$room_details['id']])) $this->trusted_domains[$room_details['id']] = $this->default_trusted_domains;
					if (empty($this->require_mention[$room_details['id']])) $this->require_mention[$room_details['id']] = $this->default_require_mention;
				}
			}
		}
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $existing_rooms;
	}

	protected function get_all_webhooks() {
		$function_start = \function_start();
		$existing_webhooks = array();
		$result = $this->webhooks('GET', []);
		if (!is_array($result)) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": didn't get all webhooks");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (count($result) > 0) {
			foreach ($result['items'] as $webhook_details) {
				if (empty($webhook_details['filter'])) $webhook_details['filter'] = null;
				$existing_webhooks[$webhook_details['id']] = $webhook_details;
			}
		}
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $existing_webhooks;
	}

	public function set_bot_trigger_at($time, $callback, $params = array()) {
		$function_start = \function_start();
		if ($time <= time()) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": timer is set for the past");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (!is_callable($callback)) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": callback isn't callable: ".json_encode($callback));
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (empty($this->bot_triggers['at'])) $this->bot_triggers['at'] = array(); 
		if (empty($this->bot_triggers['at'][$time])) $this->bot_triggers['at'][$time] = array();
		if (empty($this->bot_triggers['at'][$time]['callbacks'])) {
			$this->bot_triggers['at'][$time] = array(
				'callbacks' => array(
					$callback,
					),
				);
		} else $this->bot_triggers['at'][$time]['callbacks'][] = $callback;
		unset($params['callbacks']);
		$this->bot_triggers['at'][$time] = array_merge($this->bot_triggers['at'][$time], $params);
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return true;
	}

	public function set_bot_triggers() {
		$function_start = \function_start();

		if (empty($this->bot_triggers)) {
  		   $this->logger->addInfo(__FILE__.": ".__METHOD__.": no bot triggers provided");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}

		foreach ($this->bot_triggers as $bot_trigger_type => $bot_trigger_type_items) {
			foreach ($bot_trigger_type_items as $bot_trigger => $bot_trigger_params) {
				if ($bot_trigger_type == 'command') {
					if (preg_match("/^".$this->bot_control_command."[\/$]/", $bot_trigger) > 0) {
  	   				$this->logger->addCritical(__FILE__.": ".__METHOD__.": can't use bot control command. its reserved. bot control command: ".$this->bot_control_command);
						$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
						die();
					}
				}
  	   		$this->logger->addInfo(__FILE__.": ".__METHOD__.": new bot trigger $bot_trigger_type: $bot_trigger");
			}
		}

		$this->bot = true;
		$this->storage->me = $this->me;
		if (!isset($this->storage->perm['last_seen'])) $this->storage->perm['last_seen'] = array();
		if (!isset($this->storage->perm['message_count'])) $this->storage->perm['message_count'] = array();

		$this->set_default_bot_commands();

		if (!empty($this->post_webhook_json)) {
			
			register_shutdown_function(
				array($this, 'parse_webhook_message'), 
				null, 
				$post_webhook_json, 
				array(
					'callbacks' => array($this, 'bot_process_webhook'),
					)
				);
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": registered parse_webhook_message for post_webhook_json function");
	
		} else {

			if (!empty($this->bot_triggers['ipc'])) {
				if (empty($this->ipc_channel_seed)) {
					$this->logger->addError(__FILE__.": ".__METHOD__.": missing class parameter: ipc_channel_seed");
					$this->logger->addCritical(__FILE__.": ".__METHOD__.": IPC trigger was requested but don't have channel seed. IPC will not be established");
				} else if ($this->ipc_channel_seed > $this->max_ipc_channel_seed) {
					$this->logger->addCritical(__FILE__.": ".__METHOD__.": IPC channel seed is too large. value must be <= ".$this->max_ipc_channel_seed.". IPC will not be established.");
				} else if (empty($this->ipc_channel_psk)) {
					$this->logger->addError(__FILE__.": ".__METHOD__.": missing class parameter: ipc_channel_psk");
					$this->logger->addCritical(__FILE__.": ".__METHOD__.": IPC trigger was requested but don't have channel psk. IPC will not be established");
				} else
					$this->subscribe_ipc_channel();
			}
			if (!empty($this->bot_triggers['mqtt'])) {
				foreach ($this->bot_triggers['mqtt'] as $topic => $topic_params) {
					if (!empty($this->broker->subscribe_topics[$topic]['params']['callbacks'])) {
						foreach ($topic_params['callbacks'] as $callback) {
							if (!in_array($callback, $this->broker->subscribe_topics[$topic]['params']['callbacks'])) {
								$this->broker->subscribe_topics[$topic]['params']['callbacks'][] = $callback;
							}
						}
					} else {
						$this->broker->subscribe_topics[$topic] = array(
							'qos' => 0,
							'function' => array($this, 'parse_mqtt_message'),
							'params' => array(
								'callbacks' => $topic_params['callbacks'],
								),
							);
					}
				}
				$this->reload_subscriptions = true;
			}
	
			register_shutdown_function(array($this, 'listen'));
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": registered listen function");
	
		}

	}

	protected function subscribe_ipc_channel() {
		$function_start = \function_start();
		if (!empty($this->ipc_channel_name)) $current_ipc_channel_topic = $this->ipc_prefix.'/'.$this->ipc_channel_name;
		if (!empty($this->set_ipc_channel_name())) {
			$topic = $this->ipc_prefix.'/'.$this->ipc_channel_name;
			if (!empty($current_ipc_channel_topic)) $this->broker->subscribe_topics[$current_ipc_channel_topic] = false;
			$this->broker->subscribe_topics[$topic] = array(
				'qos' => 0,
				'function' => array($this, 'parse_ipc_message'),
				'params' => array(
					'callbacks' => $this->bot_triggers['ipc']['enabled']['callbacks'],
					),
				);
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": IPC channel topic: ".$topic);
			$this->reload_subscriptions = true;
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return true;
		} else {
			$this->logger->addCritical(__FILE__.": ".__METHOD__.": IPC trigger was requested, but can't set ipc channel name. IPC will not be established");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
	}

	protected function set_ipc_channel_name() {
		$function_start = \function_start();

		$symenc = new \SymmetricEncryption;
		$time = time();
		$hours_since_epoch = floor($time / 3600);
		$this->rotate_ipc_channel_at = $hours_since_epoch * 3600 + 3600 + $this->ipc_channel_seed;
	   $time_left = $this->rotate_ipc_channel_at - $time;
   	$this->logger->addInfo(__FILE__.": ".__METHOD__.": $time_left secs left until time to rotate ipc channel name.");
		if (empty(list($ipc_channel_name, $ignore_iv, $ignore_hmac) = explode(':', $symenc->encrypt($this->rotate_ipc_channel_at, $this->ipc_channel_psk, $this->ipc_channel_seed)))) {
   		$this->logger->addError(__FILE__.": ".__METHOD__.": couldn't create ipc channel name");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		} else { 
			$this->ipc_channel_name = str_replace(array('/','+'), '_', rtrim($ipc_channel_name, '='));
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return true;
		}

	}

	protected function set_default_bot_commands() {
		$function_start = \function_start();

		if (empty($this->bot_triggers['command'])) $this->bot_triggers['command'] = array();
		if (empty($this->bot_triggers['modcommand'])) $this->bot_triggers['modcommand'] = array();
		if (empty($this->bot_triggers['admincommand'])) $this->bot_triggers['admincommand'] = array();
		if (empty($this->bot_triggers['sucommand'])) $this->bot_triggers['sucommand'] = array();
		if (empty($this->bot_triggers['command']['help'])) {
	  	  	$this->logger->addInfo(__FILE__.": ".__METHOD__.": adding bot command help");
			$this->bot_triggers['command']['help'] = array('callbacks' => array(array($this, 'bot_command_help')), 'description' => 'This list of commands', 'label' => '/help');
		}
		if (empty($this->bot_triggers['modcommand']['help\/mod'])) {
	  	  	$this->logger->addInfo(__FILE__.": ".__METHOD__.": adding bot command help/mod");
			$this->bot_triggers['modcommand']['help\/mod'] = array('callbacks' => array(array($this, 'bot_command_help_mod')), 'description' => 'List of moderator commands', 'label' => '/help/mod');
		}
		if (empty($this->bot_triggers['sucommand']['help\/superuser'])) {
	  	  	$this->logger->addInfo(__FILE__.": ".__METHOD__.": adding bot command help/superuser");
			$this->bot_triggers['sucommand']['help\/superuser'] = array('callbacks' => array(array($this, 'bot_command_help_superuser')), 'description' => 'List of super user commands', 'label' => '/help/superuser');
		}
		if (empty($this->bot_triggers['admincommand']['help\/admin'])) {
	  	  	$this->logger->addInfo(__FILE__.": ".__METHOD__.": adding bot command help/admin");
			$this->bot_triggers['admincommand']['help\/admin'] = array('callbacks' => array(array($this, 'bot_command_help_admin')), 'description' => 'List of admin commands', 'label' => '/help/admin');
		}
		$this->bot_triggers['sucommand'][$this->bot_control_command.'\/on'] = array(
			'callbacks' => array(array($this, 'bot_command_control_command_on')), 
			'description' => 'Enable my features in this room.', 
			'label' => '/'.$this->bot_control_command.'/on'); 
		$this->bot_triggers['sucommand'][$this->bot_control_command.'\/off'] = array(
			'callbacks' => array(array($this, 'bot_command_control_command_off')), 
			'description' => 'Disable my features in this room.', 
			'label' => '/'.$this->bot_control_command.'/off'); 
		$this->bot_triggers['admincommand'][$this->bot_control_command.'\/superusers'] = array(
			'callbacks' => array(array($this, 'bot_command_control_command_superusers')), 
			'description' => 'Get super users room details in private message.',
			'label' => '/'.$this->bot_control_command.'/superusers'); 
		$this->bot_triggers['admincommand'][$this->bot_control_command.'\/superusers\/on'] = array(
			'callbacks' => array(array($this, 'bot_command_control_command_superusers_on')), 
			'description' => 'Members of the super users room can enable this bot in new rooms. Only one super users room allowed at a time.',
			'label' => '/'.$this->bot_control_command.'/superusers/on'); 
		$this->bot_triggers['admincommand'][$this->bot_control_command.'\/superusers\/off'] = array(
			'callbacks' => array(array($this, 'bot_command_control_command_superusers_off')), 
			'description' => 'Disables super user authorization based on room membership.',
			'label' => '/'.$this->bot_control_command.'/superusers/off'); 
		$this->bot_triggers['admincommand'][$this->bot_control_command.'\/trust'] = array(
			'callbacks' => array(array($this, 'bot_command_control_command_trust')), 
			'description' => "List trusted domains.",
			'label' => '/'.$this->bot_control_command.'/trust');
		$this->bot_triggers['admincommand'][$this->bot_control_command.'\/trust\/add'] = array(
			'callbacks' => array(array($this, 'bot_command_control_command_trust_add')), 
			'description' => "Add a domain that would trigger me to do something.", 
			'label' => '/'.$this->bot_control_command.'/trust/add');
		$this->bot_triggers['admincommand'][$this->bot_control_command.'\/trust\/remove'] = array(
			'callbacks' => array(array($this, 'bot_command_control_command_trust_remove')), 
			'description' => "Remove a domain that that would trigger me to do something.",
			'label' => '/'.$this->bot_control_command.'/trust/remove'); 
		$this->bot_triggers['admincommand'][$this->bot_control_command.'\/mention\/off'] = array(
			'callbacks' => array(array($this, 'bot_command_control_command_mention_off')), 
			'description' => "Listen to commands even if you don't mention me first.", 
			'label' => '/'.$this->bot_control_command.'/mention/off'); 
		$this->bot_triggers['admincommand'][$this->bot_control_command.'\/mention\/on'] = array(
			'callbacks' => array(array($this, 'bot_command_control_command_mention_on')), 
			'description' => "I'll only listen to commands if you mention me first", 
			'label' => '/'.$this->bot_control_command.'/mention/on'); 
		$this->bot_triggers['command'][$this->bot_control_command.'\/admin'] = array(
			'callbacks' => array(array($this, 'bot_command_control_command_admin')), 
			'description' => "List all admins that can issue /bot commands", 
			'label' => '/'.$this->bot_control_command.'/admin'); 
		$this->bot_triggers['admincommand'][$this->bot_control_command.'\/admin\/add'] = array(
			'callbacks' => array(array($this, 'bot_command_control_command_admin_add')), 
			'description' => 'Add a new admin by email address.', 
			'label' => '/'.$this->bot_control_command.'/admin/add'); 
		$this->bot_triggers['admincommand'][$this->bot_control_command.'\/admin\/remove'] = array(
			'callbacks' => array(array($this, 'bot_command_control_command_admin_remove')), 
			'description' => 'Remove an email of a existing admin.', 
			'label' => '/'.$this->bot_control_command.'/admin/remove'); 
		$this->bot_triggers['admincommand'][$this->bot_control_command.'\/mod\/add'] = array(
			'callbacks' => array(array($this, 'bot_command_control_command_mod_add')), 
			'description' => "Add a moderator to this room.", 
			'label' => '/'.$this->bot_control_command.'/mod/add'); 
		$this->bot_triggers['admincommand'][$this->bot_control_command.'\/mod\/remove'] = array(
			'callbacks' => array(array($this, 'bot_command_control_command_mod_remove')), 
			'description' => "Remove a moderator from this room.", 
			'label' => '/'.$this->bot_control_command.'/mod/remove'); 
		if ($this->user_management) {
			$this->bot_triggers['admincommand'][$this->bot_control_command.'\/allow'] = array(
				'callbacks' => array(array($this, 'bot_command_control_command_allow')), 
				'description' => "Add a domain I'll use to filter new room members. Requires locked room and bot as moderator", 
				'label' => '/'.$this->bot_control_command.'/allow'); 
			$this->bot_triggers['admincommand'][$this->bot_control_command.'\/disallow'] = array(
				'callbacks' => array(array($this, 'bot_command_control_command_disallow')), 
				'description' => "Remove a domain I'll use to filter new room members. Requires locked room and bot as moderator", 
				'label' => '/'.$this->bot_control_command.'/disallow'); 
			$this->bot_triggers['admincommand'][$this->bot_control_command.'\/user\/add'] = array(
				'callbacks' => array(array($this, 'bot_command_control_command_user_add')), 
				'description' => 'Adds new user(s) to the room.', 
				'label' => '/'.$this->bot_control_command.'/user/add'); 
			$this->bot_triggers['admincommand'][$this->bot_control_command.'\/user\/remove'] = array(
				'callbacks' => array(array($this, 'bot_command_control_command_user_remove')), 
				'description' => 'Removes user(s) from this room.', 
				'label' => '/'.$this->bot_control_command.'/user/remove'); 
		}

		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return true;

	}

	protected function create_all_webhooks() {
		$function_start = \function_start();

		$this->existing_webhooks = $this->delete_all_webhooks([
			'name' => '^'.$this->bot_webhook_name_prefix.$this->me['id'].'$',
			'resource' => '^messages$',
			'event' => '^created$'
			]);
		$this->existing_webhooks = $this->delete_all_webhooks([
			'name' => '^'.$this->bot_webhook_name_prefix.$this->me['id'].'$',
			'targetUrl' => '^https://api.incis.co/mqtt/\?topic='.$this->me['emails'][0].'/webhooks/$',
			'resource' => '^all$',
			'event' => '^all$'
			]);
		if ($this->existing_webhooks === false) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": failed to get webhooks");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		$params = array(
			'name'=>$this->bot_webhook_name_prefix.$this->me['id'],
			'targetUrl'=>array($this, 'bot_process_webhook'),
			'resource'=>'all',
			'event'=>'all',
			);
		list($webhook_details, $topic) = $this->does_webhook_exist($params, $this->existing_webhooks);
		if (empty($webhook_details) || empty($topic)) {
			if (empty($this->webhooks('POST', $params, false))) {
				$this->logger->addError(__FILE__.": ".__METHOD__.": couldn't create webhook");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return false;
			}
		} else {
			if (!empty($topic) && !empty($this->broker)) {
				$this->logger->addInfo(__FILE__.": ".__METHOD__.": adding webhook to subscribe topics: $topic");
				if (!empty($this->broker->subscribe_topics[$topic])) {
					if (!in_array($params['targetUrl'], $this->broker->subscribe_topics[$topic]['params']['callbacks'])) {
						$this->broker->subscribe_topics[$topic]['params']['callbacks'][] = $params['targetUrl'];
					}
				} else {
					$this->broker->subscribe_topics[$topic] = array(
						'qos' => 0,
						'function' => array($this, 'parse_webhook_message'), 
						'params' => array(
							'callbacks' => array($params['targetUrl']),
							),
						);
				}
				$this->logger->addInfo(__FILE__.": ".__METHOD__.": added callback: ".$params['targetUrl'][1]." to topic: $topic");
				$this->reload_subscriptions = true;
			}
		}

		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return true;
	}

	protected function is_valid_domain_name($domain_name) {
		$function_start = \function_start();
		$domain_check = (
			preg_match("/^([a-z\d](-*[a-z\d])*)(\.([a-z\d](-*[a-z\d])*))*$/i", $domain_name) && //valid chars check
			preg_match("/^.{1,253}$/", $domain_name) && //overall length check
			preg_match("/^[^\.]{1,63}(\.[^\.]{1,63})*$/", $domain_name)   
			); //length of each label
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $domain_check;
	}


	protected function bot_command_control_command_mention_off($spark, $logger, $storage, $extensions, $event) {
		$function_start = \function_start();

		if (empty($this->admins)) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return;
		} else {
			if (empty($this->require_mention_file)) {
				$this->logger->addError(__FILE__.": ".__METHOD__.": missing class parameter: require_mention_file");
				$this->logger->addCritical(__FILE__.": ".__METHOD__.": !!! without the require_mention_file, the bot can't maintain state if restarted. !!!");
			}
		}
			
		$this->require_mention[$event->rooms['id']] = false;
		$this->logger->addInfo(__FILE__.": ".__METHOD__.": required_mention set to false in room: ".$event->rooms['id']." by: ".$event->webhooks['data']['personEmail']);

		$text = "I'm part of the borg. I'll listen for commands even if you don't mention me.";
		$this->messages('POST', array('text' => $text, 'roomId' => $event->rooms['id']));

		if (!empty($this->require_mention_file)) $this->save_state_file($this->require_mention_file, $this->require_mention);

	}

	protected function bot_command_control_command_mention_on($spark, $logger, $storage, $extensions, $event) {
		$function_start = \function_start();

		if (empty($this->admins)) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return;
		} else {
			if (empty($this->require_mention_file)) {
				$this->logger->addError(__FILE__.": ".__METHOD__.": missing class parameter: require_mention_file");
				$this->logger->addCritical(__FILE__.": ".__METHOD__.": !!! without the require_mention_file, the bot can't maintain state if restarted. !!!");
			}
		}
			
		$this->require_mention[$event->rooms['id']] = true;
		$this->logger->addInfo(__FILE__.": ".__METHOD__.": required_mention set to true in room: ".$event->rooms['id']." by: ".$event->webhooks['data']['personEmail']);

		$text = "Alright, I'll only listen when someone mentions me directly before a command.";
		$this->messages('POST', array('text' => $text, 'roomId' => $event->rooms['id']));

		if (!empty($this->require_mention_file)) $this->save_state_file($this->require_mention_file, $this->require_mention);

	}

	protected function bot_command_control_command_allow($spark, $logger, $storage, $extensions, $event) {
		$function_start = \function_start();

		if (empty($this->admins)) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return;
		} else {
			if (empty($this->allowed_domains_file)) {
				$this->logger->addError(__FILE__.": ".__METHOD__.": missing class parameter: allowed_domains_file");
				$this->logger->addCritical(__FILE__.": ".__METHOD__.": !!! without the allowed_domains_file, the bot can't maintain state if restarted. !!!");
			}
		}
			
		if (empty($event->command['data'])) {
			$text = "Usage: /bot/allow [domain] [domain] [...]\n";
			if (empty($this->allowed_domains[$event->rooms['id']]))
				$text .= "Any user can be added right now.";
			else
				$text .= "Currently allowed domains: ".implode(", ", $this->allowed_domains[$event->rooms['id']]);
			$this->messages('POST', array('text' => $text, 'roomId' => $event->rooms['id']));
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return;
		}

		$possible_domains = explode(' ', $event->command['data']);
		foreach ($possible_domains as $domain) {
			if (!empty($this->is_valid_domain_name($domain))) {
				if (!in_array($domain, $this->allowed_domains[$event->rooms['id']])) {
					$this->allowed_domains[$event->rooms['id']][] = $domain;
					$new_allowed_domains[] = $domain;
				} else $already_allowed[] = $domain;
			} else $invalid_domains[] = $domain;
		}

		$text = '';
		if (!empty($new_allowed_domains)) {
			$text .= "I added the following allowed domains: ".implode(", ", $new_allowed_domains)."\n";
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": ".$event->webhooks['data']['personEmail']." added allowed domains: ".json_encode($new_allowed_domains));
		}
		if (!empty($already_allowed)) $text .= "These are already allowed: ".implode(", ", $already_allowed)."\n";
		if (!empty($invalid_domains)) {
			$text .= "Pretty sure these aren't real domains: ".implode(", ", $invalid_domains)."\n";
			$this->logger->addWarning(__FILE__.": ".__METHOD__.": ".$event->webhooks['data']['personEmail']." tried to add invalid allowed domains: ".json_encode($invalid_domains));
		}
		if (empty($this->allowed_domains[$event->rooms['id']])) $text .= "No allowed domains set, so any user can be added.";

		$this->messages('POST', array('text' => $text, 'roomId' => $event->rooms['id']));

		if (!empty($this->allowed_domains_file)) $this->save_state_file($this->allowed_domains_file, $this->allowed_domains);

	}

	protected function bot_command_control_command_disallow($spark, $logger, $storage, $extensions, $event) {
		$function_start = \function_start();

		if (empty($this->admins)) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return;
		} else {
			if (empty($this->allowed_domains_file)) {
				$this->logger->addError(__FILE__.": ".__METHOD__.": missing class parameter: allowed_domains_file");
				$this->logger->addCritical(__FILE__.": ".__METHOD__.": !!! without the allowed_domains_file, the bot can't maintain state if restarted. !!!");
			}
		}

		if (empty($event->command['data'])) {
			$text = "Usage: /bot/disallow [domain] [domain] [...]\n";
			if (empty($this->allowed_domains[$event->rooms['id']]))
				$text .= "Any user can be added right now.";
			else
				$text .= "Currently allowed domains: ".implode(", ", $this->allowed_domains[$event->rooms['id']]);
			$this->messages('POST', array('text' => $text, 'roomId' => $event->rooms['id']));
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return;
		}

		$possible_domains = explode(' ', $event->command['data']);
		foreach ($possible_domains as $domain) {
			if (!empty($this->is_valid_domain_name($domain))) {
				if (($domain_key = array_search($domain, $this->allowed_domains[$event->rooms['id']])) !== false) {
					array_splice($this->allowed_domains[$event->rooms['id']], $domain_key, 1);
					$new_disallowed_domains[] = $domain;
				} else $not_allowed[] = $domain;
			} else $invalid_domains[] = $domain;
		}

		$text = '';
		if (!empty($new_disallowed_domains)) {
			$text .= "I removed the following allowed domains: ".implode(", ", $new_disallowed_domains)."\n";
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": ".$event->webhooks['data']['personEmail']." removed allowed domains: ".json_encode($new_disallowed_domains));
		}
		if (!empty($not_allowed)) $text .= "I didn't allow these already: ".implode(", ", $not_allowed)."\n";
		if (!empty($invalid_domains)) {
			$text .= "Pretty sure these aren't real domains: ".implode(", ", $invalid_domains)."\n";
			$this->logger->addWarning(__FILE__.": ".__METHOD__.": ".$event->webhooks['data']['personEmail']." tried to remove invalid allowed domains: ".json_encode($invalid_domains));
		}
		if (empty($this->allowed_domains[$event->rooms['id']])) $text .= "No allowed domains set, so any user can be added.";

		$this->messages('POST', array('text' => $text, 'roomId' => $event->rooms['id']));

		if (!empty($this->allowed_domains_file)) $this->save_state_file($this->allowed_domains_file, $this->allowed_domains);

	}

	protected function bot_command_control_command_user_add($spark, $logger, $storage, $extensions, $event) {
		$function_start = \function_start();

		if (empty($this->admins)) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return;
		}

		if (empty($event->command['data'])) {
			$text = "Usage: /bot/user/add [email address] [email address] [...]";
			$this->messages('POST', array('text' => $text, 'roomId' => $event->rooms['id']));
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return;
		}

		$possible_users = explode(' ', $event->command['data']);
		foreach ($possible_users as $email) {
			if (!empty(filter_var($email, FILTER_VALIDATE_EMAIL))) {

				if ($event->rooms['isLocked'] == true && !empty($this->allowed_domains[$event->rooms['id']])) {
					$email_domain = preg_replace("/^.*@(.*)$/", "$1", $email);
					$allowed = false;
					foreach ($this->allowed_domains[$event->rooms['id']] as $allowed_domain) {
						if (preg_match("/(^|\.)".preg_quote($allowed_domain, '/')."$/", $email_domain) > 0) {
							$allowed = true;
							break;
						}
					}
					if ($allowed == false) {
						$not_allowed_emails[] = $email;
						$this->logger->addWarning(__FILE__.": ".__METHOD__.": ".$event->webhooks['data']['personEmail']." tried to add a non-allowed email: $email");
						continue;
					}
				}

				$membership = $this->memberships('GET', array('roomId' => $event->rooms['id'], 'personEmail' => $email));
				if (count($membership['items']) != 1) {
					$this->logger->addWarning(__FILE__.": ".__METHOD__.": no membership for room: ".$event->rooms['id']." email: ".$email);
					if (empty($this->memberships('POST', array('roomId' => $event->rooms['id'], 'personEmail' => $email)))) {
						$this->logger->addWarning(__FILE__.": ".__METHOD__.": couldn't create membership for room: ".$event->rooms['id']." email: ".$email);
						$failed_users[] = $email;
					} else $new_users[] = $email;
				} else $already_user[] = $email;
			} else $invalid_email[] = $email;
		}

		if (!empty($failed_users) && $event->rooms['isLocked'] == true)
			$me_membership = $this->memberships('GET', array('roomId' => $event->rooms['id'], 'personId' => $this->me['id']));

		if (isset($me_membership) && empty($me_membership['items'][0]['isModerator'])) {
			$text = 'I want to add users for you, but someone needs to make me a moderator first.';
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": ".$event->webhooks['data']['personEmail']." asked to add users but bot isn't moderator");
		} else {
			$text = '';
			if (!empty($new_users)) {
				$this->logger->addInfo(__FILE__.": ".__METHOD__.": ".$event->webhooks['data']['personEmail']." added users: ".json_encode($new_users));
			}
			if ($event->rooms['isLocked'] == true) {
				if (!empty($not_allowed_emails)) $text .= "These emails are not allowed in this room: ".implode(", ", $not_allowed_emails)."\n";
			}
			if (!empty($already_user)) $text .= "These are already members: ".implode(", ", $already_user)."\n";
			if (!empty($failed_users)) $text .= "I failed to add these users: ".implode(", ", $failed_users)."\n";
			if (!empty($invalid_email)) {
				$text .= "Pretty sure these aren't real email addresses: ".implode(", ", $invalid_email)."\n";
				$this->logger->addWarning(__FILE__.": ".__METHOD__.": ".$event->webhooks['data']['personEmail']." tried to add invalid users: ".json_encode($invalid_email));
			}
		}

		if (!empty($text)) $this->messages('POST', array('text' => $text, 'roomId' => $event->rooms['id']));

	}

	protected function bot_command_control_command_user_remove($spark, $logger, $storage, $extensions, $event) {
		$function_start = \function_start();

		if (empty($this->admins)) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return;
		}

		if (empty($event->command['data'])) {
			$text = "Usage: /bot/user/remove [email address] [email address] [...]";
			$this->messages('POST', array('text' => $text, 'roomId' => $event->rooms['id']));
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return;
		}

		$possible_users = explode(' ', $event->command['data']);
		foreach ($possible_users as $email) {
			if (!empty(filter_var($email, FILTER_VALIDATE_EMAIL))) {
				$membership = $this->memberships('GET', array('roomId' => $event->rooms['id'], 'personEmail' => $email));
				if (count($membership['items']) == 1) {
					if (empty($this->memberships('DELETE', array('membershipId' => $membership['items'][0]['id'])))) {
						$this->logger->addWarning(__FILE__.": ".__METHOD__.": couldn't delete membership for room: ".$event->rooms['id']." email: ".$email);
						$failed_users[] = $email;
					} else $removed_users[] = $email;
				} else $already_not_user[] = $email;
			} else $invalid_email[] = $email;
		}

		if (!empty($failed_users) && $event->rooms['isLocked'] == true)
			$me_membership = $this->memberships('GET', array('roomId' => $event->rooms['id'], 'personId' => $this->me['id']));

		if (isset($me_membership) && empty($me_membership['items'][0]['isModerator'])) {
			$text = 'I want to remove users for you, but someone needs to make me a moderator first.';
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": ".$event->webhooks['data']['personEmail']." asked to remove users but bot isn't moderator");
		} else {
			$text = '';
			if (!empty($removed_users)) {
				$this->logger->addInfo(__FILE__.": ".__METHOD__.": ".$event->webhooks['data']['personEmail']." removed users: ".json_encode($removed_users));
			}
			if (!empty($already_not_user)) $text .= "These aren't existing members: ".implode(", ", $already_not_user)."\n";
			if (!empty($failed_users)) $text .= "I failed to remove these users: ".implode(", ", $failed_users)."\n";
			if (!empty($invalid_email)) {
				$text .= "Pretty sure these aren't real email addresses: ".implode(", ", $invalid_email)."\n";
				$this->logger->addWarning(__FILE__.": ".__METHOD__.": ".$event->webhooks['data']['personEmail']." tried to add invalid users: ".json_encode($invalid_email));
			}
		}

		if (!empty($text)) $this->messages('POST', array('text' => $text, 'roomId' => $event->rooms['id']));

	}

	protected function bot_command_control_command_mod_add($spark, $logger, $storage, $extensions, $event) {
		$function_start = \function_start();

		if (empty($this->admins)) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return;
		}

		if ($event->rooms['isLocked'] == false) {
			$text = "This room isn't locked.";
			$this->messages('POST', array('text' => $text, 'roomId' => $event->rooms['id']));
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return;
		}
		
		$me_membership = $this->memberships('GET', array('roomId' => $event->rooms['id'], 'personId' => $this->me['id']));
		if (isset($me_membership) && $me_membership['items'][0]['isModerator'] == false) {
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": ".$event->webhooks['data']['personEmail']." asked to add moderators but bot isn't moderator");
			$text = 'I want to add moderators for you, but someone needs to make me a moderator first.';
			$this->messages('POST', array('text' => $text, 'roomId' => $event->rooms['id']));
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return;
		}

		if (empty($event->command['data'])) {
			$text = "Usage: /bot/mod/add [email address] [email address] [...]";
			$this->messages('POST', array('text' => $text, 'roomId' => $event->rooms['id']));
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return;
		}

		$possible_moderators = explode(' ', $event->command['data']);
		foreach ($possible_moderators as $email) {
			if (!empty(filter_var($email, FILTER_VALIDATE_EMAIL))) {
				$membership = $this->memberships('GET', array('roomId' => $event->rooms['id'], 'personEmail' => $email));
				if (count($membership['items']) != 1) {
					$this->logger->addWarning(__FILE__.": ".__METHOD__.": no membership for room: ".$event->rooms['id']." email: ".$email);
					if (empty($this->memberships('POST', array('roomId' => $event->rooms['id'], 'personEmail' => $email, 'isModerator' => true)))) {
						$this->logger->addWarning(__FILE__.": ".__METHOD__.": couldn't create moderator membership for room: ".$event->rooms['id']." email: ".$email);
						$failed_moderators[] = $email;
					} else $new_moderators[] = $email;
				} else {
					if ($membership['items'][0]['isModerator'] == false) {
						if (empty($this->memberships('PUT', array('membershipId' => $membership['items'][0]['id'], 'isModerator' => true)))) {
							$this->logger->addWarning(__FILE__.": ".__METHOD__.": couldn't update moderator membership to true for room: ".$event->rooms['id']." email: ".$email);
							$failed_moderators[] = $email;
						} else $new_moderators[] = $email;
					} else $already_moderator[] = $email;
				}
			} else $invalid_email[] = $email;
		}

		$text = '';
		if (!empty($new_moderators)) {
			$text .= "I added the following moderators: ".implode(", ", $new_moderators)."\n";
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": ".$event->webhooks['data']['personEmail']." added moderators: ".json_encode($new_moderators));
		}
		if (!empty($already_moderator)) $text .= "These are already moderators: ".implode(", ", $already_moderator)."\n";
		if (!empty($failed_moderators)) $text .= "I failed to make these moderators: ".implode(", ", $failed_moderators)."\n";
		if (!empty($invalid_email)) {
			$text .= "Pretty sure these aren't real email addresses: ".implode(", ", $invalid_email)."\n";
			$this->logger->addWarning(__FILE__.": ".__METHOD__.": ".$event->webhooks['data']['personEmail']." tried to add invalid moderators: ".json_encode($invalid_email));
		}

		$this->messages('POST', array('text' => $text, 'roomId' => $event->rooms['id']));

	}

	protected function bot_command_control_command_mod_remove($spark, $logger, $storage, $extensions, $event) {
		$function_start = \function_start();

		if (empty($this->admins)) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return;
		}

		if ($event->rooms['isLocked'] == false) {
			$text = "This room isn't locked.";
			$this->messages('POST', array('text' => $text, 'roomId' => $event->rooms['id']));
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return;
		}

		$me_membership = $this->memberships('GET', array('roomId' => $event->rooms['id'], 'personId' => $this->me['id']));
		if (isset($me_membership) && $me_membership['items'][0]['isModerator'] == false) {
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": ".$event->webhooks['data']['personEmail']." asked to remove moderators but bot isn't moderator");
			$text = 'I want to remove moderators for you, but someone needs to make me a moderator first.';
			$this->messages('POST', array('text' => $text, 'roomId' => $event->rooms['id']));
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return;
		}

		if (empty($event->command['data'])) {
			$text = "Usage: /bot/mod/remove [email address] [email address] [...]";
			$this->messages('POST', array('text' => $text, 'roomId' => $event->rooms['id']));
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return;
		}

		$possible_removed_moderators = explode(' ', $event->command['data']);
		foreach ($possible_removed_moderators as $email) {
			if (!empty(filter_var($email, FILTER_VALIDATE_EMAIL))) {
				$membership = $this->memberships('GET', array('roomId' => $event->rooms['id'], 'personEmail' => $email));
				if (count($membership['items']) != 1) {
					$not_in_the_room[] = $email;
				} else {
					if ($membership['items'][0]['isModerator'] == true) {
						if (empty($this->memberships('PUT', array('membershipId' => $membership['items'][0]['id'], 'isModerator' => false)))) {
							$this->logger->addWarning(__FILE__.": ".__METHOD__.": couldn't update moderator membership to false for room: ".$event->rooms['id']." email: ".$email);
							$failed_moderators[] = $email;
						} else $removed_moderators[] = $email;
					} else $already_not_moderator[] = $email;
				}
			} else $invalid_email[] = $email;
		}

		$text = '';
		if (!empty($removed_moderators)) {
			$text .= "I removed the following moderators: ".implode(", ", $removed_moderators)."\n";
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": ".$event->webhooks['data']['personEmail']." removed moderators: ".json_encode($removed_moderators));
		}
		if (!empty($already_not_moderator)) $text .= "These are already not moderators: ".implode(", ", $already_not_moderator)."\n";
		if (!empty($not_in_the_room)) $text .= "These aren't in the room: ".implode(", ", $not_in_the_room)."\n";
		if (!empty($failed_moderators)) $text .= "I failed to remove these moderators: ".implode(", ", $failed_moderators)."\n";
		if (!empty($invalid_email)) {
			$text .= "Pretty sure these aren't real email addresses: ".implode(", ", $invalid_email)."\n";
			$this->logger->addWarning(__FILE__.": ".__METHOD__.": ".$event->webhooks['data']['personEmail']." tried to remove invalid moderators: ".json_encode($invalid_email));
		}

		$this->messages('POST', array('text' => $text, 'roomId' => $event->rooms['id']));

	}

	protected function bot_command_control_command_admin_add($spark, $logger, $storage, $extensions, $event) {
		$function_start = \function_start();

		if (empty($this->admins)) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return;
		} else {
			if (empty($this->admins_file)) {
				$this->logger->addError(__FILE__.": ".__METHOD__.": missing class parameter: admins_file");
				$this->logger->addCritical(__FILE__.": ".__METHOD__.": !!! without the admins_file, the bot can't maintain state if restarted. !!!");
			}
		}

		if (empty($event->command['data'])) {
			$text = "Usage: /bot/admin/add [email address] [email address] [...]\n";
			$text .= "My current admins: ";
			foreach ($this->admins as $admin) {
				if (!empty(in_array($admin, $this->super_admins))) $text .= $admin."*, ";
				else $text .= $admin.", ";
			}
			$text = rtrim($text, ', ');
			$this->messages('POST', array('text' => $text, 'roomId' => $event->rooms['id']));
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return;
		}

		$possible_admins = explode(' ', $event->command['data']);
		foreach ($possible_admins as $email) {
			if (!empty(filter_var($email, FILTER_VALIDATE_EMAIL))) {
				if (!in_array($email, $this->admins)) {
					$this->admins[] = $email;
					$new_admins[] = $email;
				} else $already_admin[] = $email;
			} else $invalid_email[] = $email;
		}

		$text = '';
		if (!empty($new_admins)) {
			$text .= "I added the following admins: ".implode(", ", $new_admins)."\n";
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": ".$event->webhooks['data']['personEmail']." added admins: ".json_encode($new_admins));
		}
		if (!empty($already_admin)) $text .= "These are already admins: ".implode(", ", $already_admin)."\n";
		if (!empty($invalid_email)) {
			$text .= "Pretty sure these aren't real email addresses: ".implode(", ", $invalid_email)."\n";
			$this->logger->addWarning(__FILE__.": ".__METHOD__.": ".$event->webhooks['data']['personEmail']." tried to add invalid admins: ".json_encode($invalid_email));
		}

		$this->messages('POST', array('text' => $text, 'roomId' => $event->rooms['id']));

		if (!empty($this->admins_file)) $this->save_state_file($this->admins_file, $this->admins);

	}

	protected function bot_command_control_command_admin_remove($spark, $logger, $storage, $extensions, $event) {
		$function_start = \function_start();

		if (empty($this->admins)) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return;
		} else {
			if (empty($this->admins_file)) {
				$this->logger->addError(__FILE__.": ".__METHOD__.": missing class parameter: admins_file");
				$this->logger->addCritical(__FILE__.": ".__METHOD__.": !!! without the admins_file, the bot can't maintain state if restarted. !!!");
			}
		}

		if (empty($event->command['data'])) {
			$text = "Usage: /bot/admin/remove [email address] [email address] [...]";
			$text .= "My current admins: ";
			foreach ($this->admins as $admin) {
				if (!empty(in_array($admin, $this->super_admins))) $text .= $admin."*, ";
				else $text .= $admin.", ";
			}
			$text = rtrim($text, ', ');
			$this->messages('POST', array('text' => $text, 'roomId' => $event->rooms['id']));
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return;
		}

		$possible_admins = explode(' ', $event->command['data']);
		foreach ($possible_admins as $email) {
			if (!empty(filter_var($email, FILTER_VALIDATE_EMAIL))) {
				if (($email_key = array_search($email, $this->admins)) !== false) {
					if (in_array($email, $this->super_admins)) $super_admins[] = $email;
					else {
						array_splice($this->admins, $email_key, 1);
						$removed_admins[] = $email;
					}
				} else $not_an_admin[] = $email;
			} else $invalid_email[] = $email;
		}

		$text = '';
		if (!empty($removed_admins)) {
			$text .= "I removed the following admins: ".implode(", ", $removed_admins)."\n";
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": ".$event->webhooks['data']['personEmail']." removed admins: ".json_encode($removed_admins));
		}
		if (!empty($super_admins)) {
			$text .= "I'm not allowed to ever remove these admins: ".implode(", ", $super_admins)."\n";
			$this->logger->addWarning(__FILE__.": ".__METHOD__.": ".$event->webhooks['data']['personEmail']." tried to remove super admins: ".json_encode($super_admins));
		}
		if (!empty($not_an_admin)) $text .= "These aren't admins: ".implode(", ", $not_an_admin)."\n";
		if (!empty($invalid_email)) {
			$text .= "Pretty sure these aren't real email addresses: ".implode(", ", $invalid_email)."\n";
			$this->logger->addWarning(__FILE__.": ".__METHOD__.": ".$event->webhooks['data']['personEmail']." tried to remove invalid admins: ".json_encode($invalid_email));
		}

		$this->messages('POST', array('text' => $text, 'roomId' => $event->rooms['id']));

		if (!empty($this->admins_file)) $this->save_state_file($this->admins_file, $this->admins);

	}

	protected function bot_command_control_command_trust($spark, $logger, $storage, $extensions, $event) {
		$function_start = \function_start();

		if (empty($this->admins)) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return;
		}

		if (empty($this->trusted_domains[$event->rooms['id']]))
			$text = "All messages are trusted right now.";
		else
			$text = "Currently trusted domains: ".implode(", ", $this->trusted_domains[$event->rooms['id']]);
		$this->messages('POST', array('text' => $text, 'roomId' => $event->rooms['id']));

		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
	}

	protected function bot_command_control_command_trust_add($spark, $logger, $storage, $extensions, $event) {
		$function_start = \function_start();

		if (empty($this->admins)) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return;
		} else {
			if (empty($this->trusted_domains_file)) {
				$this->logger->addError(__FILE__.": ".__METHOD__.": missing class parameter: trusted_domains_file");
				$this->logger->addCritical(__FILE__.": ".__METHOD__.": !!! without the trusted_domains_file, the bot can't maintain state if restarted. !!!");
			}
		}

		if (empty($event->command['data'])) {
			$text = "Usage: /bot/trust/add [domain] [domain] [...]\n";
			if (empty($this->trusted_domains[$event->rooms['id']]))
				$text .= "All messages are trusted right now.";
			else
				$text .= "Currently trusted domains: ".implode(", ", $this->trusted_domains[$event->rooms['id']]);
			$this->messages('POST', array('text' => $text, 'roomId' => $event->rooms['id']));
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return;
		}

		$possible_domains = explode(' ', $event->command['data']);
		foreach ($possible_domains as $domain) {
			if (!empty($this->is_valid_domain_name($domain))) {
				if (!in_array($domain, $this->trusted_domains[$event->rooms['id']])) {
					$this->trusted_domains[$event->rooms['id']][] = $domain;
					$new_trusted_domains[] = $domain;
				} else $already_trusted[] = $domain;
			} else $invalid_domains[] = $domain;
		}

		$text = '';
		if (!empty($new_trusted_domains)) {
			$text .= "I added the following trusted domains: ".implode(", ", $new_trusted_domains)."\n";
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": ".$event->webhooks['data']['personEmail']." added trusted domains: ".json_encode($new_trusted_domains));
		}
		if (!empty($already_trusted)) $text .= "These are already trusted: ".implode(", ", $already_trusted)."\n";
		if (!empty($invalid_domains)) {
			$text .= "Pretty sure these aren't real domains: ".implode(", ", $invalid_domains)."\n";
			$this->logger->addWarning(__FILE__.": ".__METHOD__.": ".$event->webhooks['data']['personEmail']." tried to add invalid trusted domains: ".json_encode($invalid_domains));
		}
		if (empty($this->trusted_domains[$event->rooms['id']])) $text .= "No trusted domains set, so all messages are trusted.";

		$this->messages('POST', array('text' => $text, 'roomId' => $event->rooms['id']));

		if (!empty($this->trusted_domains_file)) $this->save_state_file($this->trusted_domains_file, $this->trusted_domains);

	}

	protected function bot_command_control_command_trust_remove($spark, $logger, $storage, $extensions, $event) {
		$function_start = \function_start();

		if (empty($this->admins)) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return;
		} else {
			if (empty($this->trusted_domains_file)) {
				$this->logger->addError(__FILE__.": ".__METHOD__.": missing class parameter: trusted_domains_file");
				$this->logger->addCritical(__FILE__.": ".__METHOD__.": !!! without the trusted_domains_file, the bot can't maintain state if restarted. !!!");
			}
		}

		if (empty($event->command['data'])) {
			$text = "Usage: /bot/trust/remove [domain] [domain] [...]\n";
			if (empty($this->trusted_domains[$event->rooms['id']]))
				$text .= "All messages are trusted right now.";
			else
				$text .= "Currently trusted domains: ".implode(", ", $this->trusted_domains[$event->rooms['id']]);
			$this->messages('POST', array('text' => $text, 'roomId' => $event->rooms['id']));
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return;
		}

		$possible_domains = explode(' ', $event->command['data']);
		foreach ($possible_domains as $domain) {
			if (!empty($this->is_valid_domain_name($domain))) {
				if (($domain_key = array_search($domain, $this->trusted_domains[$event->rooms['id']])) !== false) {
					array_splice($this->trusted_domains[$event->rooms['id']], $domain_key, 1);
					$new_distrusted_domains[] = $domain;
				} else $not_trusted[] = $domain;
			} else $invalid_domains[] = $domain;
		}

		$text = '';
		if (!empty($new_distrusted_domains)) {
			$text .= "I removed the following trusted domains: ".implode(", ", $new_distrusted_domains)."\n";
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": ".$event->webhooks['data']['personEmail']." removed trusted domains: ".json_encode($new_distrusted_domains));
		}
		if (!empty($not_trusted)) $text .= "I didn't trust these already: ".implode(", ", $not_trusted)."\n";
		if (!empty($invalid_domains)) {
			$text .= "Pretty sure these aren't real domains: ".implode(", ", $invalid_domains)."\n";
			$this->logger->addWarning(__FILE__.": ".__METHOD__.": ".$event->webhooks['data']['personEmail']." tried to remove invalid trusted domains: ".json_encode($invalid_domains));
		}
		if (empty($this->trusted_domains[$event->rooms['id']])) $text .= "No trusted domains set, so all messages are trusted.";

		$this->messages('POST', array('text' => $text, 'roomId' => $event->rooms['id']));

		if (!empty($this->trusted_domains_file)) $this->save_state_file($this->trusted_domains_file, $this->trusted_domains);

	}

	protected function bot_command_control_command_superusers($spark, $logger, $storage, $extensions, $event) {
		$function_start = \function_start();

		if (empty($this->admins)) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return;
		}

		if (!empty($this->super_users_room)) {
			if (!empty($room_details = $this->rooms('GET', [ 'roomId' => $this->super_users_room ])))
				$text = "My super users room is **".$room_details['title']."** (".$this->super_users_room.").";
			else 
				$text = "My super users room is **".$this->super_users_room."**.";
		} else $text = "No super users room set.";
		$this->messages('POST', array('markdown' => $text, 'toPersonId' => $event->people['id']));

		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
	}

	protected function bot_command_control_command_superusers_on($spark, $logger, $storage, $extensions, $event) {
		$function_start = \function_start();

		if (empty($this->admins)) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return;
		} else {
			if (empty($this->super_users_room_file)) {
				$this->logger->addError(__FILE__.": ".__METHOD__.": missing class parameter: super_users_room_file");
				$this->logger->addCritical(__FILE__.": ".__METHOD__.": !!! without the super_users_room_file, the bot can't maintain state if restarted. super users room would need to be set again !!!");
			}
		}

		if ($this->super_users_room != $event->rooms['id']) {
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": super users room is changing orig: ".$this->super_users_room." new: ".$event->rooms['id']);
			if (!empty($this->super_users_room)) {

				$text = "This room is no longer being used to authorize my super users.";
				$this->messages('POST', array('text' => $text, 'roomId' => $this->super_users_room));

				if (!empty($room_details = $this->rooms('GET', [ 'roomId' => $this->super_users_room ])))
					$text = "Changing super users room from **".$room_details['title']."** (".$this->super_users_room.") to **".$event->rooms['title']."** (".$event->rooms['id'].").";
				else 
					$text = "Changing super users room from **".$this->super_users_room."** to **".$event->rooms['title']."** (".$event->rooms['id'].").";
				$this->messages('POST', array('text' => $text, 'toPersonId' => $event->people['id']));

			}
			$text = "Any users in this room are now considered super users and can enable me in other rooms with /bot/on.";
			$this->super_users_room = $event->rooms['id'];
		} else {
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": no change to super users room");
			$text = "The members of this room are already super users.";
		}
		$this->messages('POST', array('markdown' => $text, 'roomId' => $event->rooms['id']));

		if (!empty($this->super_users_room_file)) $this->save_state_file($this->super_users_room_file, $this->super_users_room);

		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
	}

	protected function bot_command_control_command_superusers_off($spark, $logger, $storage, $extensions, $event) {
		$function_start = \function_start();

		if (empty($this->admins)) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return;
		} else {
			if (empty($this->super_users_room_file)) {
				$this->logger->addError(__FILE__.": ".__METHOD__.": missing class parameter: super_users_room_file");
				$this->logger->addCritical(__FILE__.": ".__METHOD__.": !!! without the super_users_room_file, the bot can't maintain state if restarted. super users room would need to be unset again !!!");
			}
		}

		if (empty($this->super_users_room))
			$text = "No super users room set. There's nothing for me to do.";
		else {

			$this->logger->addInfo(__FILE__.": ".__METHOD__.": removing super users room: ".$this->super_users_room);

			$text = "This room is no longer being used to authorize my super users.";
			$this->messages('POST', array('text' => $text, 'roomId' => $this->super_users_room));

			if (!empty($room_details = $this->rooms('GET', [ 'roomId' => $this->super_users_room ])))
				$text = "Disabling all super users in **".$room_details['title']."** (".$this->super_users_room.").";
			else
				$text = "Disabling all super users in room **".$this->super_users_room."**.";
			$this->messages('POST', array('markdown' => $text, 'toPersonId' => $event->people['id']));

			$this->super_users_room = '';
			$text = "I've removed all super users.";

		}
		$this->messages('POST', array('markdown' => $text, 'roomId' => $event->rooms['id']));

		if (!empty($this->super_users_room_file)) $this->save_state_file($this->super_users_room_file, $this->super_users_room);

		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
	}

	protected function bot_command_control_command_on($spark, $logger, $storage, $extensions, $event) {
		$function_start = \function_start();

		if (empty($this->admins)) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return;
		} else {
			if (empty($this->enabled_rooms_file)) {
				$this->logger->addError(__FILE__.": ".__METHOD__.": missing class parameter: enabled_rooms_file");
				$this->logger->addCritical(__FILE__.": ".__METHOD__.": !!! without the enabled_rooms_file, the bot can't maintain state if restarted. the bot would need to be enabled again in each room !!!");
			}
		}

		$this->enabled_rooms[$event->rooms['id']] = true;
		$this->logger->addInfo(__FILE__.": ".__METHOD__.": enabled room: ".$event->rooms['id']);

		if (!empty($this->bot_triggers['boton']['enabled']['callbacks'])) {
			foreach ($this->bot_triggers['boton']['enabled']['callbacks'] as $callback) {
				if ($this->multithreaded) {
					$this->worker_pool->submit(
						new Callback($callback, $this, $this->logger, $this->storage, $this->extensions, $event)
						);
				} else {
					$callback($this, $this->logger, $this->storage, $this->extensions, $event);
				}
			}
      }

		$text = "I'm ready to go. /help to get started.";
		$this->messages('POST', array('text' => $text, 'roomId' => $event->rooms['id']));

		if (!empty($this->enabled_rooms_file)) $this->save_state_file($this->enabled_rooms_file, $this->enabled_rooms);

		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
	}

	protected function bot_command_control_command_off($spark, $logger, $storage, $extensions, $event) {
		$function_start = \function_start();

		if (empty($this->admins)) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return;
		} else {
			if (empty($this->enabled_rooms_file)) {
				$this->logger->addError(__FILE__.": ".__METHOD__.": missing class parameter: enabled_rooms_file");
				$this->logger->addCritical(__FILE__.": ".__METHOD__.": !!! without the enabled_rooms_file, the bot can't maintain state if restarted. the bot would need to be enabled again in each room !!!");
			}
		}

		$this->enabled_rooms[$event->rooms['id']] = false;
		$this->logger->addInfo(__FILE__.": ".__METHOD__.": disabled room: ".$event->rooms['id']);

		$text = "Alright, I'll stop helping out. Let me know if you want me back with /bot/on";
		$this->messages('POST', array('text' => $text, 'roomId' => $event->rooms['id']));

		if (!empty($this->enabled_rooms_file)) $this->save_state_file($this->enabled_rooms_file, $this->enabled_rooms);

		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
	}

	protected function bot_command_control_command_admin($spark, $logger, $storage, $extensions, $event) {
		$function_start = \function_start();

		if (empty($this->admins)) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return;
		}

		$text = "Here are my admins: ";
		foreach ($this->admins as $admin) {
			if (!empty(in_array($admin, $this->super_admins))) $text .= $admin."*, ";
			else $text .= $admin.", ";
		}
		$text = rtrim($text, ', ');
		$this->messages('POST', array('text' => $text, 'roomId' => $event->rooms['id']));

		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
	}

	protected function save_state_file($file, $data) {
		$function_start = \function_start();

		if (empty(file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX)))
			$this->logger->addCritical(__FILE__.": ".__METHOD__.": !!! couldn't write to $file, bot can't maintain state if restarted. !!!");

		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
	}

	public function bot_command_help_mod($spark, $logger, $storage, $extensions, $event) {
		$function_start = \function_start();

		if (empty($event->memberships['items'][0]['isModerator'])) return false;
		// order of commands is based on location of functions in file
		$max_command_length = 0;
		foreach ($spark->bot_triggers['modcommand'] as $bot_command => $bot_command_details) {
			if ($max_command_length < strlen($bot_command_details['label'])) $max_command_length = strlen($bot_command_details['label']);
		}
		$text = '';
		if ($event->command['name'] != 'help') {
			if ($this->show_complete_invalid_command)
				$text = "I'm not sure what you meant with the command, **".$event->messages['text']."**\n\n";
			else
				$text = "I'm not sure what you meant with the command, **".$event->command['name']."**. ";
		}
		if ($this->direct_help) $text .= "Since you're a **moderator**, you can use these commands in **".$event->rooms['title']."**\n\n";
		else $text .= "Since you're a **moderator**, you can use these commands\n\n";
		foreach ($spark->bot_triggers['modcommand'] as $bot_command => $bot_command_details) {
			if (empty($bot_command_details['label'])) continue;
			$spacer_length = $max_command_length - strlen($bot_command_details['label']);
			$spacer = '';
			for ($i=0; $i<$spacer_length; $i++) $spacer .= ' ';
			$description = (!empty($bot_command_details['description'])) ? $bot_command_details['description'] : '';
			$text .= '> `'.$bot_command_details['label'].'`'.$spacer."\t".$description."\n\n";
		}
		if ($this->direct_help) $post_result = $spark->messages('POST', array('toPersonId' => $event->people['id'], 'markdown' => $text));
		else $post_result = $spark->messages('POST', array('roomId' => $event->messages['roomId'], 'markdown' => $text));
		if (empty($post_result))
			$this->logger->addError(__FILE__.": ".__METHOD__.": failed to post help message");
		else {
			if ($this->delete_last_help && !$this->direct_help) {
				if (!empty($this->storage->perm['last_seen'][$event->messages['roomId']]['command']['help\/mod']['messageId']))
					$this->messages('DELETE', array('messageId' => $this->storage->perm['last_seen'][$event->messages['roomId']]['command']['help\/mod']['messageId']));
				$this->storage->perm['last_seen'][$event->messages['roomId']]['command']['help\/mod']['messageId'] = $post_result['id'];
			}
			if (
				($spark->direct_help && $event->command['name'] == 'help') ||
				($spark->delete_invalid_commands && $event->command['name'] != 'help')
				) $spark->messages('DELETE', array('messageId' => $event->webhooks['data']['id']));
		}
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
	}

	public function bot_command_help_superuser($spark, $logger, $storage, $extensions, $event) {
		$function_start = \function_start();

		if (empty($event->permissions['admin']) && empty($event->permissions['superuser'])) return false;
		// order of commands is based on location of functions in file
		$max_command_length = 0;
		foreach ($spark->bot_triggers['sucommand'] as $bot_command => $bot_command_details) {
			if ($max_command_length < strlen($bot_command_details['label'])) $max_command_length = strlen($bot_command_details['label']);
		}
		$text = '';
		if ($event->command['name'] != 'help') {
			if ($this->show_complete_invalid_command)
				$text = "I'm not sure what you meant with the command, **".$event->messages['text']."**\n\n";
			else
				$text = "I'm not sure what you meant with the command, **".$event->command['name']."**. ";
		}
		if ($this->direct_help) $text .= "Since you're a **super user**, you can use these commands in **".$event->rooms['title']."**\n\n";
		else $text .= "Since you're a **super user**, you can use these commands:\n\n";
		foreach ($spark->bot_triggers['sucommand'] as $bot_command => $bot_command_details) {
			if (empty($bot_command_details['label'])) continue;
			$spacer_length = $max_command_length - strlen($bot_command_details['label']);
			$spacer = '';
			for ($i=0; $i<$spacer_length; $i++) $spacer .= ' ';
			$description = (!empty($bot_command_details['description'])) ? $bot_command_details['description'] : '';
			$text .= '> `'.$bot_command_details['label'].'`'.$spacer."\t".$description."\n\n";
		}
		if ($this->direct_help) $post_result = $spark->messages('POST', array('toPersonId' => $event->people['id'], 'markdown' => $text));
		else $post_result = $spark->messages('POST', array('roomId' => $event->messages['roomId'], 'markdown' => $text));
		if (empty($post_result))
			$this->logger->addError(__FILE__.": ".__METHOD__.": failed to post help message");
		else {
			if ($this->delete_last_help && !$this->direct_help) {
				if (!empty($this->storage->perm['last_seen'][$event->messages['roomId']]['command']['help\/superuser']['messageId']))
					$this->messages('DELETE', array('messageId' => $this->storage->perm['last_seen'][$event->messages['roomId']]['command']['help\/superuser']['messageId']));
				$this->storage->perm['last_seen'][$event->messages['roomId']]['command']['help\/superuser']['messageId'] = $post_result['id'];
			}
			if (
				($spark->direct_help && $event->command['name'] == 'help') ||
				($spark->delete_invalid_commands && $event->command['name'] != 'help')
				) $spark->messages('DELETE', array('messageId' => $event->webhooks['data']['id']));
		}
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
	}

	public function bot_command_help_admin($spark, $logger, $storage, $extensions, $event) {
		$function_start = \function_start();

		if (!$this->is_admin($event->people['emails'])) return false;
		// order of commands is based on location of functions in file
		$max_command_length = 0;
		foreach ($spark->bot_triggers['admincommand'] as $bot_command => $bot_command_details) {
			if ($max_command_length < strlen($bot_command_details['label'])) $max_command_length = strlen($bot_command_details['label']);
		}
		$text = '';
		if ($event->command['name'] != 'help') {
			if ($this->show_complete_invalid_command)
				$text = "I'm not sure what you meant with the command, **".$event->messages['text']."**\n\n";
			else
				$text = "I'm not sure what you meant with the command, **".$event->command['name']."**. ";
		}
		if ($this->direct_help) $text .= "Since you're an **admin**, you can use these commands in **".$event->rooms['title']."**\n\n";
		else $text .= "Since you're an **admin**, you can use these commands:\n\n";
		foreach (array_merge($spark->bot_triggers['sucommand'], $spark->bot_triggers['admincommand']) as $bot_command => $bot_command_details) {
			if (empty($bot_command_details['label'])) continue;
			$spacer_length = $max_command_length - strlen($bot_command_details['label']);
			$spacer = '';
			for ($i=0; $i<$spacer_length; $i++) $spacer .= ' ';
			$description = (!empty($bot_command_details['description'])) ? $bot_command_details['description'] : '';
			$text .= '> `'.$bot_command_details['label'].'`'.$spacer."\t".$description."\n\n";
		}
		if ($this->direct_help) $post_result = $spark->messages('POST', array('toPersonId' => $event->people['id'], 'markdown' => $text));
		else $post_result = $spark->messages('POST', array('roomId' => $event->messages['roomId'], 'markdown' => $text));
		if (empty($post_result))
			$this->logger->addError(__FILE__.": ".__METHOD__.": failed to post help message");
		else {
			if ($this->delete_last_help && !$this->direct_help) {
				if (!empty($this->storage->perm['last_seen'][$event->messages['roomId']]['command']['help\/admin']['messageId']))
					$this->messages('DELETE', array('messageId' => $this->storage->perm['last_seen'][$event->messages['roomId']]['command']['help\/admin']['messageId']));
				$this->storage->perm['last_seen'][$event->messages['roomId']]['command']['help\/admin']['messageId'] = $post_result['id'];
			}
			if (
				($spark->direct_help && $event->command['name'] == 'help') ||
				($spark->delete_invalid_commands && $event->command['name'] != 'help')
				) $spark->messages('DELETE', array('messageId' => $event->webhooks['data']['id']));
		}
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
	}

	public function bot_command_help($spark, $logger, $storage, $extensions, $event) {
		$function_start = \function_start();

		// order of commands is based on location of functions in file
		$max_command_length = 0;
		foreach ($spark->bot_triggers['command'] as $bot_command => $bot_command_details) {
			if ($max_command_length < strlen($bot_command_details['label'])) $max_command_length = strlen($bot_command_details['label']);
		}
		$text = '';
		if ($event->command['name'] != 'help') {
			if ($this->show_complete_invalid_command)
				$text = "I'm not sure what you meant with the command, **".$event->messages['text']."**\n\n";
			else
				$text = "I'm not sure what you meant with the command, **".$event->command['name']."**. ";
		}
		if ($this->direct_help) $text .= "You can use the following commands in **".$event->rooms['title']."**\n\n";
		else $text .= "You can use the following commands:\n\n";
		$last_text = '';
		foreach ($spark->bot_triggers['command'] as $bot_command => $bot_command_details) {
			if (empty($bot_command_details['label'])) continue;
			$spacer_length = $max_command_length - strlen($bot_command_details['label']);
			$spacer = '';
			for ($i=0; $i<$spacer_length; $i++) $spacer .= ' ';
			$description = (!empty($bot_command_details['description'])) ? $bot_command_details['description'] : '';
			if (preg_match("/^".$spark->bot_control_command."\//", $bot_command_details['label']) > 0) $last_text .= '> `'.$bot_command_details['label'].'`'.$spacer."\t".$description."\n\n";
			else $text .= '> `'.$bot_command_details['label'].'`'.$spacer."\t".$description."\n\n";
		}
		if (!empty($event->memberships['items'][0]['isModerator']) && !empty($spark->bot_triggers['modcommand'])) {
			$description = (!empty($spark->bot_triggers['modcommand']['help\/mod']['description'])) ? $spark->bot_triggers['modcommand']['help\/mod']['description'] : '';
			$text .= '> `'.$spark->bot_triggers['modcommand']['help\/mod']['label'].'`'.$spacer."\t".$description."\n\n";
		}
		if ($spark->is_admin($event->people['emails'])) {
			$description = (!empty($spark->bot_triggers['admincommand']['help\/admin']['description'])) ? $spark->bot_triggers['admincommand']['help\/admin']['description'] : '';
			$text .= '> `'.$spark->bot_triggers['admincommand']['help\/admin']['label'].'`'.$spacer."\t".$description."\n\n";
		} else if ($event->permissions['super_user']) {
			$description = (!empty($spark->bot_triggers['sucommand']['bot\/on']['description'])) ? $spark->bot_triggers['sucommand']['bot\/on']['description'] : '';
			$text .= '> `'.$spark->bot_triggers['sucommand']['bot\/on']['label'].'`'.$spacer."\t".$description."\n\n";
			$description = (!empty($spark->bot_triggers['sucommand']['bot\/off']['description'])) ? $spark->bot_triggers['sucommand']['bot\/off']['description'] : '';
			$text .= '> `'.$spark->bot_triggers['sucommand']['bot\/off']['label'].'`'.$spacer."\t".$description."\n\n";
			if (count($spark->bot_triggers['sucommand']) > 3) { // /bot/on /bot/off /help/superuser
				$description = (!empty($spark->bot_triggers['sucommand']['help\/superuser']['description'])) ? $spark->bot_triggers['sucommand']['help\/superuser']['description'] : '';
				$text .= '> `'.$spark->bot_triggers['sucommand']['help\/superuser']['label'].'`'.$spacer."\t".$description."\n\n";
			}
		}
		$text .= $last_text;
		if ($this->direct_help) $post_result = $spark->messages('POST', array('toPersonId' => $event->people['id'], 'markdown' => $text));
		else $post_result = $spark->messages('POST', array('roomId' => $event->messages['roomId'], 'markdown' => $text));
		if (empty($post_result))
			$logger->addError(__FILE__.": ".__METHOD__.": failed to post help message");
		else {
			if ($spark->delete_last_help && !$spark->direct_help) {
				if (!empty($storage->perm['last_seen'][$event->messages['roomId']]['command']['help']['messageId'])) {
					$spark->messages('DELETE', array('messageId' => $storage->perm['last_seen'][$event->messages['roomId']]['command']['help']['messageId']));
				}
				$storage->perm['last_seen'][$event->messages['roomId']]['command']['help']['messageId'] = $post_result['id'];
			}
			if (
				($spark->direct_help && $event->command['name'] == 'help') ||
				($spark->delete_invalid_commands && $event->command['name'] != 'help')
				) $spark->messages('DELETE', array('messageId' => $event->webhooks['data']['id']));
		}
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $storage;
	}

	public function bot_process_webhook($spark, $logger, $storage, $extensions, $event) {
		$function_start = \function_start();
		$is_admin = (!empty($event->permissions['admin'])) ? true : false;
		if (!empty($this->admins) && !empty($event->rooms['id']) && empty($this->enabled_rooms[$event->rooms['id']])) {
			$this->logger->addWarning(__FILE__.": ".__METHOD__.": there are admins and this room hasn't been enabled");
			$room_disabled = true;
			if (!$is_admin && empty($event->permissions['super_user'])) {
				if (!empty($event->webhooks['data']['personEmail']))
					$this->logger->addWarning(__FILE__.": ".__METHOD__.": room isn't enabled and user isn't an admin or super user: ".$event->webhooks['data']['personEmail']);
				else 
					$this->logger->addWarning(__FILE__.": ".__METHOD__.": room isn't enabled and not an admin or super user generated message");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return;
			}
		}
		// check for commands
		if (!empty($event->messages['text'])) {
			$any_commands_found = false;
			$malformed_command = [];
			$callbacks = [];
			foreach (array('command', 'modcommand', 'sucommand', 'admincommand') as $command_type) {
				if (($command_type == 'command' || $command_type == 'modcommand') && !empty($room_disabled)) continue;
				if ($command_type == 'modcommand' && empty($event->memberships['items'][0]['isModerator'])) continue;
				if ($command_type == 'admincommand' && empty($is_admin)) continue;
				if ($command_type == 'sucommand' && empty($is_admin) && !$event->permissions['super_user']) continue;
				if (empty($this->bot_triggers[$command_type])) continue;
				foreach ($this->bot_triggers[$command_type] as $bot_command => $bot_command_params) {
					if (!empty($this->detect_malformed_commands) && empty($malfromed_command_type)) {
						if (
							preg_match("/^\s*$this->me_mention_regex\s*[^\s]*[\\\\\/][^\s]*(\s|$)/", $event->messages['text']) > 0 &&
							preg_match("/^\s*$this->me_mention_regex\s*([\\\\\/]*".str_replace("\/", "[\\\\\/]*", $bot_command)."[\\\\\/]*)(\s|$)/is", $event->messages['text'], $matches) > 0
							) {
							$malformed_command['type'] = $command_type;
							$malformed_command['command'] = $matches[2];
						} else if (empty($this->require_mention[$event->webhooks['data']['roomId']])) {
							$command_error_regex = "/^\s*([\\\\\/]*".str_replace("\/", "[\\\\\/]*", $bot_command)."[\\\\\/]*)(\s|$)/is";
							if (
								preg_match("/^\s*[^\s]*[\\\\\/][^\s]*(\s|$)/", $event->messages['text']) > 0 &&
								preg_match($command_error_regex, $event->messages['text'], $matches) > 0
								) {
								$malformed_command['type'] = $command_type;
								$malformed_command['command'] = $matches[1];
							}
						}
					}
					$found_bot_command = false;
					$complete_bot_command = $bot_command;
					if (preg_match("/^\s*$this->me_mention_regex\s*\/($bot_command)(\s+(.*)\s*$|$)/is", $event->messages['text'], $matches) > 0) {
						$bot_command_message_data = (!empty($matches[4])) ? rtrim($matches[4]) : '';
						$bot_commands = explode('/', $matches[2]);
						$bot_command = array_shift($bot_commands);
						$bot_command_options = $bot_commands;
						$found_bot_command = true;
					} else if (empty($this->require_mention[$event->webhooks['data']['roomId']])) {
						if (preg_match("/^\s*\/($bot_command)(\s+(.*)\s*$|$)/is", $event->messages['text'], $matches) > 0) {
							$bot_command_message_data = (!empty($matches[3])) ? rtrim($matches[3]) : '';
							$bot_commands = explode('/', $matches[1]);
							$bot_command = array_shift($bot_commands);
							$bot_command_options = $bot_commands;
							$found_bot_command = true;
						}
					}
					if ($found_bot_command) {
						$any_commands_found = true;
						$event->command = array(
							'name' => $bot_command,
							'options' => $bot_command_options,
							'data' => $bot_command_message_data,
							);
						$this->logger->addInfo(__FILE__.": ".__METHOD__.": found command: ".json_encode($event->command));
						if ($this->multithreaded) $this->collect_worker_garbage();
						foreach ($bot_command_params['callbacks'] as $callback) {
							if (in_array($callback, $callbacks)) {
								$this->logger->addInfo(__FILE__.": ".__METHOD__.": this message already triggered callback: $callback");
								continue;
							} else
								$callbacks[] = $callback;
							$this->report_spark_slow($event->rooms['id']);
							if ($this->multithreaded && $bot_command != $this->bot_control_command) {
								$this->worker_pool->submit(
									new Callback($callback, $spark, $logger, $this->storage, $extensions, $event)
									);
							} else {
								$callback($spark, $logger, $this->storage, $extensions, $event);
							}
						}
						unset($event->command);
					}
				}
			}
			unset($callbacks);

			if (!empty($this->detect_malformed_commands) && empty($any_commands_found) && !empty($malformed_command)) {

				$any_commands_found = true;

				if ($malformed_command['type'] == 'command' || $malformed_command['type'] == 'sucommand')
					$callbacks = $this->bot_triggers['command']['help']['callbacks'];
				else if ($malformed_command['type'] == 'modcommand')
					$callbacks = $this->bot_triggers['modcommand']['help\/mod']['callbacks'];
				else if ($malformed_command['type'] == 'admincommand')
					$callbacks = $this->bot_triggers['admincommand']['help\/admin']['callbacks'];

				$event->command = array(
					'name' => $malformed_command['command'],
					'options' => null,
					'data' => null,
					);
				if ($this->multithreaded) $this->collect_worker_garbage();
				foreach ($callbacks as $callback) {
					$this->report_spark_slow($event->rooms['id']);
					if ($this->multithreaded) {
						$this->worker_pool->submit(
							new Callback($callback, $spark, $logger, $this->storage, $extensions, $event)
							);
					} else {
						$callback($spark, $logger, $this->storage, $extensions, $event);
					}
				}
				unset($event->command);

			}

			if (!empty($room_disabled)) {
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return;
			}

			if (empty($any_commands_found) && !empty($this->detect_unknown_commands)) {
				if (preg_match("/^\s*$this->me_mention_regex\s*(\/[^\s]+)(\s|$)/", $event->messages['text'], $matches) > 0) {
					$event->command = array(
						'name' => $matches[2],
						'options' => null,
						'data' => null,
						);
					$unknown_command_found = true;
				} else if (empty($this->require_mention[$event->webhooks['data']['roomId']])) {
					if (preg_match("/^\s*(\/[^\s]+)(\s|$)/", $event->messages['text'], $matches) > 0) {
						$event->command = array(
							'name' => $matches[1],
							'options' => null,
							'data' => null,
							);
						$unknown_command_found = true;
					}
				}
				if (!empty($unknown_command_found)) {
					if ($this->multithreaded) $this->collect_worker_garbage();
					foreach ($this->bot_triggers['command']['help']['callbacks'] as $callback) {
						$this->report_spark_slow($event->rooms['id']);
						if ($this->multithreaded) {
							$this->worker_pool->submit(
								new Callback($callback, $spark, $logger, $this->storage, $extensions, $event)
								);
						} else {
							$callback($spark, $logger, $this->storage, $extensions, $event);
						}
					}
					unset($event->command);
				}
			}

			if (!empty($this->bot_triggers['hashtag'])) {
				$event->matches = [];
				$callbacks = [];
				foreach ($this->bot_triggers['hashtag'] as $bot_hashtag_string => $bot_hashtag_string_params) {
					if (preg_match_all("/(#$bot_hashtag_string)([^0-9a-z]|$)/i", $event->messages['text'], $matches)) {
						$event->matches = array_unique(array_merge($matches[1], $event->matches));
						$callbacks = array_unique(array_merge($bot_hashtag_string_params['callbacks'], $callbacks));
					}
				}
				if (!empty($event->matches)) {
					if ($this->multithreaded) $this->collect_worker_garbage();
					foreach ($callbacks as $callback) {
						if ($this->multithreaded) {
							$this->worker_pool->submit(
								new Callback($callback, $spark, $logger, $this->storage, $extensions, $event)
								);
						} else {
							$callback($spark, $logger, $this->storage, $extensions, $event);
						}
					}
				}
				unset($event->matches);
				unset($callbacks);
			}

			if (!empty($this->bot_triggers['email'])) {
				$event->matches = [];
				$callbacks = [];
				foreach ($this->bot_triggers['email'] as $bot_email => $bot_email_params) {
					$email_regex = '/(?!(?:(?:\x22?\x5C[\x00-\x7E]\x22?)|(?:\x22?[^\x5C\x22]\x22?)){255,})(?!(?:(?:\x22?\x5C[\x00-\x7E]\x22?)|(?:\x22?[^\x5C\x22]\x22?)){65,}@)(?:(?:[\x21\x23-\x27\x2A\x2B\x2D\x2F-\x39\x3D\x3F\x5E-\x7E]+)|(?:\x22(?:[\x01-\x08\x0B\x0C\x0E-\x1F\x21\x23-\x5B\x5D-\x7F]|(?:\x5C[\x00-\x7F]))*\x22))(?:\.(?:(?:[\x21\x23-\x27\x2A\x2B\x2D\x2F-\x39\x3D\x3F\x5E-\x7E]+)|(?:\x22(?:[\x01-\x08\x0B\x0C\x0E-\x1F\x21\x23-\x5B\x5D-\x7F]|(?:\x5C[\x00-\x7F]))*\x22)))*@(?:(?:(?!.*[^.]{64,})(?:(?:(?:xn--)?[a-z0-9]+(?:-[a-z0-9]+)*\.){1,126}){1,}(?:(?:[a-z][a-z0-9]*)|(?:(?:xn--)[a-z0-9]+))(?:-[a-z0-9]+)*)|(?:\[(?:(?:IPv6:(?:(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){7})|(?:(?!(?:.*[a-f0-9][:\]]){7,})(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,5})?::(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,5})?)))|(?:(?:IPv6:(?:(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){5}:)|(?:(?!(?:.*[a-f0-9]:){5,})(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,3})?::(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,3}:)?)))?(?:(?:25[0-5])|(?:2[0-4][0-9])|(?:1[0-9]{2})|(?:[1-9]?[0-9]))(?:\.(?:(?:25[0-5])|(?:2[0-4][0-9])|(?:1[0-9]{2})|(?:[1-9]?[0-9]))){3}))\]))/iD';
					if (preg_match_all($email_regex, $event->messages['text'], $matches)) {
						$event->matches = array_unique(array_merge($matches[0], $event->matches));
						$callbacks = array_unique(array_merge($bot_email_params['callbacks'], $callbacks));
					}
				}
				if (!empty($event->matches)) {
					if ($this->multithreaded) $this->collect_worker_garbage();
					foreach ($callbacks as $callback) {
						if ($this->multithreaded) {
							$this->worker_pool->submit(
								new Callback($callback, $spark, $logger, $this->storage, $extensions, $event)
								);
						} else {
							$callback($spark, $logger, $this->storage, $extensions, $event);
						}
					}
				}
				unset($event->matches);
			}

			if (!empty($this->bot_triggers['phone'])) {
				$event->matches = [];
				$callbacks = [];
				foreach ($this->bot_triggers['phone'] as $bot_phone => $bot_phone_params) {
					$phone_regex = '/[\(\+]\d[\d\s\-\.\((\)]*\d{2}[\d\s\-\.\(\)]*\d(\s*x\s*\d+)?/i';
					if (preg_match_all($phone_regex, $event->messages['text'], $matches)) {
						$event->matches = array_unique(array_merge($matches[0], $event->matches));
						$callbacks = array_unique(array_merge($bot_phone_params['callbacks'], $callbacks));
					}
				}
				if (!empty($event->matches)) {
					if ($this->multithreaded) $this->collect_worker_garbage();
					foreach ($callbacks as $callback) {
						if ($this->multithreaded) {
							$this->worker_pool->submit(
								new Callback($callback, $spark, $logger, $this->storage, $extensions, $event)
								);
						} else {
							$callback($spark, $logger, $this->storage, $extensions, $event);
						}
					}
				}
				unset($event->matches);
				unset($callbacks);
			}

			if (!empty($this->bot_triggers['url'])) {
				$event->matches = [];
				$callbacks = [];
				foreach ($this->bot_triggers['url'] as $bot_url => $bot_url_params) {
					$url_regex = '_(?:(?:[a-z]+):///?)(?:\S+(?::\S*)?@)?(?:(?!10(?:\.\d{1,3}){3})(?!127(?:\.\d{1,3}){3})(?!169\.254(?:\.\d{1,3}){2})(?!192\.168(?:\.\d{1,3}){2})(?!172\.(?:1[6-9]|2\d|3[0-1])(?:\.\d{1,3}){2})(?:[1-9]\d?|1\d\d|2[01]\d|22[0-3])(?:\.(?:1?\d{1,2}|2[0-4]\d|25[0-5])){2}(?:\.(?:[1-9]\d?|1\d\d|2[0-4]\d|25[0-4]))|(?:(?:[a-z\x{00a1}-\x{ffff}0-9]+-?)*[a-z\x{00a1}-\x{ffff}0-9]+)(?:\.(?:[a-z\x{00a1}-\x{ffff}0-9]+-?)*[a-z\x{00a1}-\x{ffff}0-9]+)*(?:\.(?:[a-z\x{00a1}-\x{ffff}]{2,})))(?::\d{2,5})?(?:/[^\s]*)?_iuS';
					if (preg_match_all($url_regex, $event->messages['text'], $matches)) {
						$event->matches = array_unique(array_merge($matches[0], $event->matches));
						$callbacks = array_unique(array_merge($bot_url_params['callbacks'], $callbacks));
					}
				}
				if (!empty($event->matches)) {
					if ($this->multithreaded) $this->collect_worker_garbage();
					foreach ($callbacks as $callback) {
						if ($this->multithreaded) {
							$this->worker_pool->submit(
								new Callback($callback, $spark, $logger, $this->storage, $extensions, $event)
								);
						} else {
							$callback($spark, $logger, $this->storage, $extensions, $event);
						}
					}
				}
				unset($event->matches);
				unset($callbacks);
			}

			if (!empty($this->bot_triggers['search'])) {
				$event->matches = [];
				$callbacks = [];
				foreach ($this->bot_triggers['search'] as $bot_search_string => $bot_search_string_params) {
					if (preg_match_all("/$bot_search_string/i", $event->messages['text'], $matches)) {
						$event->matches = array_unique(array_merge($matches[0], $event->matches));
						$callbacks = array_unique(array_merge($bot_search_string_params['callbacks'], $callbacks));
					}
				}
				if (!empty($event->matches)) {
					if ($this->multithreaded) $this->collect_worker_garbage();
					foreach ($callbacks as $callback) {
						if ($this->multithreaded) {
							$this->worker_pool->submit(
								new Callback($callback, $spark, $logger, $this->storage, $extensions, $event)
								);
						} else {
							$callback($spark, $logger, $this->storage, $extensions, $event);
						}
					}
				}
				unset($event->matches);
				unset($callbacks);
			}

		}

		if (!empty($room_disabled)) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return;
		}

		if (
			!empty($event->messages['files'])
			&& !empty($this->bot_triggers['files'])
			) {
			foreach ($this->bot_triggers['files'] as $bot_files => $bot_files_string_params) {
				if ($this->multithreaded) $this->collect_worker_garbage();
				foreach ($bot_files_string_params['callbacks'] as $callback) {
					if ($this->multithreaded) {
						$this->worker_pool->submit(
							new Callback($callback, $spark, $logger, $this->storage, $extensions, $event)
							);
					} else {
						$callback($spark, $logger, $this->storage, $extensions, $event);
					}
				}
			}
		}

		if (!empty($this->bot_triggers['webhook'])) {
			foreach ($this->bot_triggers['webhook'] as $bot_webhook_resource_event => $bot_webhook_resource_event_params) {
				list($bot_webhook_resource, $bot_webhook_event) = explode('_', $bot_webhook_resource_event);
				if (
					$bot_webhook_resource == 'all' && $bot_webhook_event == 'all'
					|| $bot_webhook_resource == $event->webhooks['resource'] && $bot_webhook_event == 'all'
					|| $bot_webhook_resource == 'all' && $bot_webhook_event == $event->webhooks['event']
					|| $bot_webhook_resource == $event->webhooks['resource'] && $bot_webhook_event == $event->webhooks['event']
					) {
					if ($this->multithreaded) $this->collect_worker_garbage();
					foreach ($bot_webhook_resource_event_params['callbacks'] as $callback) {
						if ($this->multithreaded) {
							$this->worker_pool->submit(
								new Callback($callback, $spark, $logger, $this->storage, $extensions, $event)
								);
						} else {
							$callback($spark, $logger, $this->storage, $extensions, $event);
						}
					}
				}
			}
		}

		if (!empty($this->bot_triggers['person']) && !empty($event->people['emails'])) {
			foreach ($this->bot_triggers['person'] as $bot_person_email => $bot_person_params) {
				if (in_array($bot_person_email, $event->people['emails'])) {
					if ($this->multithreaded) $this->collect_worker_garbage();
					foreach ($bot_person_params['callbacks'] as $callback) {
						if ($this->multithreaded) {
							$this->worker_pool->submit(
								new Callback($callback, $spark, $logger, $this->storage, $extensions, $event)
								);
						} else {
							$callback($spark, $logger, $this->storage, $extensions, $event);
						}
					}
				}
			}
		}

		if (
			!empty($this->bot_triggers['search'])
			&& $webhook_message['resource'] == 'rooms' 
			&& (
				$webhook_message['event'] == 'created'
				|| (
					$webhook_message['event'] == 'updated'
					&& $this->room_before_update['title'] != $event->rooms['title']
					)
				)
			) {
			$event->matches = [];
			$callbacks = [];
			foreach ($this->bot_triggers['search'] as $bot_search_string => $bot_search_string_params) {
				if (preg_match_all("/$bot_search_string/i", $event->rooms['title'], $matches)) {
					$event->matches = array_unique(array_merge($matches[0], $event->matches));
					$callbacks = array_unique(array_merge($bot_search_string_params['callbacks'], $callbacks));
				}
			}
			if (!empty($event->matches)) {
				if ($this->multithreaded) $this->collect_worker_garbage();
				foreach ($callbacks as $callback) {
					if ($this->multithreaded) {
						$this->worker_pool->submit(
							new Callback($callback, $spark, $logger, $this->storage, $extensions, $event)
							);
					} else {
						$callback($spark, $logger, $this->storage, $extensions, $event);
					}
				}
			}
			unset($event->matches);
			unset($callbacks);
		}

		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
	}

	public function report_spark_slow($room_id) {
		$function_start = \function_start();
		if (empty($this->report_slow)) return false;
		if (empty($this->existing_rooms[$room_id])) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": missing function parameter: room_id");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if ($this->spark_api_slow == $this->spark_api_slow_max) {
			if ($this->spark_api_slow_reported[$room_id] == false) {
				$text = "Things are really slow to my backend services. It's not my fault, promise. I'll let everyone know when things are better. In the meantime, you might need to send commands a few times for me to receive them.";
				if (!empty($this->messages('POST', array('text' => $text, 'roomId' => $room_id))))
					$this->spark_api_slow_reported[$room_id] = true;
			}
		} else if ($this->spark_api_slow == 0) {
			if ($this->spark_api_slow_reported[$room_id] == true) {
				$text = "Backend services are behaving again. Hopefully that's the last of it.";
				if (!empty($this->messages('POST', array('text' => $text, 'roomId' => $room_id))))
					$this->spark_api_slow_reported[$room_id] = false;
			}
		}
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return true;
	}

	public function is_admin($emails) {
		$function_start = \function_start();
		if (empty($emails)) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": missing function parameter: emails");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		foreach ($emails as $email) {
			if (in_array($email, $this->admins)) {
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return true;
			}
		}
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return false;
	}

	protected function encode_topic($id, $email) {
		$function_start = \function_start();
		$search = array('[id]', '[email]');
		$replace = array($id, $email);
		$topic = str_replace($search, $replace, '+'.$this->webhook_target_topic);
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $topic;
	}

	public function get_integration_tokens($access_code = false) {
		$function_start = \function_start();
	
		if (empty($access_code)) {
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": missing function parameter: access_code");
			if (class_exists('Oauth')) {
				if (empty($this->oauth_provider)) {
					$this->logger->addError(__FILE__.": ".__METHOD__.": missing class parameter: oauth_provider");
					$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
					return false;
				}
				$oauth = new Oauth($this->logger, $this->config['spark']);
				$acces_code = $oauth->get_access_code($this->oauth_provider);
				unset($oauth);
				if (empty($acces_code)) {
	   	   	$this->logger->addError(__FILE__.": ".__METHOD__.": failed to get access code");
					$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		   	   return false;
				}
			} else {
				$this->logger->addError(__FILE__.": ".__METHOD__.": access_code isn't defined and oauth not included");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		      return false;
			}
		}
		if (empty($this->client_id)) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": missing class parameter: client_id");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (empty($this->client_secret)) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": missing class parameter: client_secret");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (empty($this->oauth_redirect_uri)) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": missing class parameter: oauth_redirect_uri");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (empty($this->tokens_url)) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": missing class parameter: tokens_url");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
	
		$curl = $this->curl;
		$curl->headers = array(
			"Content-type: application/x-www-form-urlencoded",
			);
		$curl->params = 
			'grant_type=authorization_code&'.
			'client_id='.urlencode($this->client_id).'&'.
			'client_secret='.urlencode($this->client_secret).'&'.
			'code='.urlencode($access_code).'&'.
			'redirect_uri='.urlencode($this->oauth_redirect_uri);
		$curl->method = 'POST';
		$curl->response_type = 'json';
		$curl->url = $this->tokens_url;
		$curl->success_http_code = '200';
		$curl->caller = __FILE__.': '.__METHOD__;
		$tokens = $curl->request();
		unset($curl);
		if (empty($tokens)) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": failed to get tokens");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		} else {
			$tokens['access_token_timestamp'] = time();
			$tokens['refresh_token_timestamp'] = time();
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return $tokens;
		}

	}

	public function refresh_access_token($refresh_token = null) {
		$function_start = \function_start();
		if (empty($refresh_token)) $refresh_token = $this->tokens['refresh_token'];
	
		if (empty($refresh_token)) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": missing function parameter: refresh_token");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (empty($this->client_id)) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": missing class parameter: client_id");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (empty($this->client_secret)) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": missing class parameter: client_secret");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (empty($this->tokens_url)) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": missing class parameter: tokens_url");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		$curl = $this->curl;
		$curl->headers = array(
			"Content-type: application/x-www-form-urlencoded",
			);
		$curl->params = 
			'grant_type=refresh_token&'.
			'client_id='.urlencode($this->client_id).'&'.
			'client_secret='.urlencode($this->client_secret).'&'.
			'refresh_token='.urlencode($refresh_token);
		$curl->method = 'POST';
		$curl->response_type = 'json';
		$curl->url = $this->tokens_url;
		$curl->success_http_code = '200';
		$curl->caller = __FILE__.': '.__METHOD__;
		$tokens = $curl->request();
		$tokens['access_token_timestamp'] = time();
		unset($curl);
		if (empty($tokens)) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": failed to refresh tokens");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		} else {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return $tokens;
		}

	}

	public function validate_access_token($access_token = null) {
		$function_start = \function_start();
			if (empty($access_token)) $access_token = $this->tokens['access_token'];

		if (empty($access_token)) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": missing function parameter: access_token");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (empty($this->api_url)) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": missing class parameter: api_url");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}

		if (!empty($me = $this->spark_api('GET', 'people', $this->api_url.'people/me', null, $access_token))) {
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": access_token is valid");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return $me;
		} else {
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": access_token is invalid");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false; 
		}
	}
	
	public function validate_users_access_token($email, $access_token = null) {
		$function_start = \function_start();
		if (empty($access_token)) $access_token = $this->tokens['access_token'];

		if (empty($email)) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": missing function parameter: email");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (empty($access_token)) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": missing function parameter: access_token");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (empty($this->api_url)) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": missing class parameter: api_url");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}

		if (!empty($user = $this->spark_api('GET', 'people', $this->api_url.'people/me', null, $access_token))) {
			if (in_array($email, $user['emails'])) {
				$this->logger->addInfo(__FILE__.": ".__METHOD__.": access_token is associated with user: $email");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return $user;
			} else {
				$this->logger->addWarning(__FILE__.": ".__METHOD__.": access_token is not associated with user: $email");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return false;
			}
		} else {
			$this->logger->addWarning(__FILE__.": ".__METHOD__.": access_token is invalid");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false; 
		}
	}
	
	protected function spark_api($method, $api, $api_url, $params = null, $access_token = null) {
		$function_start = \function_start();

		if (
			$this->passive === true
			&& ($api != 'webhooks' && $method != 'GET')
			) {

			$this->logger->addWarning(__FILE__.": ".__METHOD__.": passive is true and trying to do something that could impact UX");

			if (
				$api == 'memberships'
				&& $method == 'DELETE'
				&& !empty($this->cache[$api][$params['membershipId']]['data']['personId'])
				&& $this->cache[$api][$params['membershipId']]['data']['personId'] == $this->me['id']
				) {
				$this->logger->addWarning(__FILE__.": ".__METHOD__.": passive is true, but permitting bot to remove itself from a room");
			} else {
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return false;
			}

		}

		if (empty($access_token)) $access_token = $this->tokens['access_token'];
	
		if (empty($method)) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": missing function parameter: method");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (empty($api_url)) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": missing function parameter: api_url");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (empty($access_token)) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": missing function parameter: access_token");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}

		$start_time = time();
		$curl = $this->curl;
      foreach (array_keys($this->spark_endpoints[$this->message_version][$api]) as $api_path) {
         if (preg_match("/\/{([^}]+)}/", $api_path, $matches) > 0) {
				if (!empty($params[$matches[1]])) {
					$id = $params[$matches[1]];
					unset($params[$matches[1]]);
				}
				break;
			}
      }
		$curl->params = null;

		if (
			$this->enable_cache
			&& $api != 'messages'
			) {
			if ($method == 'GET') {
				$cacheable = true;
				if (!empty($id)) {
					if (!empty($this->cache[$api][$id]['data'])) {
						$this->logger->addInfo(__FILE__.": ".__METHOD__.": using $api cache for $id");
						$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
						return $this->cache[$api][$id]['data'];
					}
				} else {
					if (
						$api == 'memberships'
						&& !empty($params['roomId'])
						) {
						if (
							(!empty($params['personId']) && !empty($this->cache['memberships_room_person'][$params['roomId']][$params['personId']]['data']))
							|| (!empty($params['personEmail']) && !empty($this->cache['memberships_room_person'][$params['roomId']][$params['personEmail']]['data']))
							) {
							if (!empty($params['personId'])) $person = $params['personId'];
							else $person = $params['personEmail'];
							$this->logger->addDebug(__FILE__.": ".__METHOD__.": using memberships_room_person cache for room: ".$params['roomId']." person: ".$person);
							$membership_id = $this->cache['memberships_room_person'][$params['roomId']][$person]['data'];
							$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
							return [ 'items' => [ $this->cache['memberships'][$membership_id]['data'] ] ];
						} else if (
							count($params) == 1
							&& !empty($this->cache['memberships_room'][$params['roomId']])
							) {
							$this->logger->addDebug(__FILE__.": ".__METHOD__.": using memberships_room cache for room: ".$params['roomId']);
							foreach (array_keys($this->cache['memberships_room'][$params['roomId']]['data']) as $membership_id)
								$membership_items[] = $this->cache['memberships'][$membership_id]['data'];
							$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
							return [ 'items' => $membership_items ];
						}
					}
				}
			} else if ($method == 'POST' || $method == 'PUT') $cacheable = true;
		}

		if (!empty($params)) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": before encode params: ".serialize($params));
			if ($method == 'GET') {
				$get_params = '?';
				foreach (array_keys($params) as $param_name) {
					$get_params .= urlencode($param_name) .'='.urlencode($params[$param_name]).'&'; 
				}
				$api_url .= rtrim($get_params, '&');
			} else $curl->params = json_encode($params, JSON_UNESCAPED_UNICODE);
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": after encode params: ".$curl->params);
		}
		
		$curl->headers = array(
			"Content-type: application/json",
			"Authorization: Bearer $access_token"
			);
		$curl->method = $method;
		$curl->url = $api_url;
		if (empty($params['max']) && $method == 'GET') $curl->paginate = true;
		else $curl->paginate = false;
		if ($method == 'DELETE') {
			$curl->response_type = 'empty';
			$curl->success_http_code = '204';
		} else {
			$curl->response_type = 'json';
			$curl->success_http_code = '200';
		}
		if ($this->backoff) $curl->backoff_codes = ['429', '502'];
		else $curl->backoff_codes = [];
		$curl->caller = __FILE__.': '.__METHOD__;
		$data = $curl->request();

		$end_time = time();
		if ($end_time - $start_time > $this->spark_api_slow_time) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": its taking too long to call $api_url");
			if ($this->spark_api_slow < $this->spark_api_slow_max) $this->spark_api_slow++;
		} else if ($this->spark_api_slow > 0) $this->spark_api_slow - .5;
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": spark_api_slow: ".$this->spark_api_slow);

		if (empty($data)) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": failed to call $api_url");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		} else {

			if (!empty($cacheable)) {
				if (!empty($data['items'])) {
					foreach ($data['items'] as $item) {
						$this->logger->addDebug(__FILE__.": ".__METHOD__.": adding to $api cache for ".$item['id']);
						$this->cache[$api][$item['id']]['data'] = $item;
						$this->cache[$api][$item['id']]['timestamp'] = time();
						if ($api == 'memberships') {
							$this->cache['memberships_room_person'][$item['roomId']][$item['personId']]['data'] = $item['id'];
							$this->cache['memberships_room_person'][$item['roomId']][$item['personEmail']]['data'] = $item['id'];
							if (
								!empty($params['roomId'])
								&& count($params) == 1
								) {
								$this->cache['memberships_room'][$item['roomId']]['data'][$item['id']] = true;
								if (empty($this->cache['memberships_room'][$item['roomId']]['timestamp']))
									$this->cache['memberships_room'][$item['roomId']]['timestamp'] = time();
							}
						}
						$this->cache_updated = true;
					}
				} else if (!empty($data['id'])) {
					$this->logger->addDebug(__FILE__.": ".__METHOD__.": adding to $api cache for ".$data['id']);
					$this->cache[$api][$data['id']]['data'] = $data;
					$this->cache[$api][$data['id']]['timestamp'] = time();
					$this->cache_updated = true;
				}
			}

			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return $data;

		}

	}

	public function parse_ipc_message($topic, $encrypted_message, $params = null) {
		$function_start = \function_start();
		$this->logger->addInfo(__FILE__.": ".__METHOD__.": received ipc message");
		if (empty($params['callbacks'])) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": missing params callbacks");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		$symenc = new \SymmetricEncryption;
		if (empty($message = $symenc->decrypt($encrypted_message, $this->ipc_channel_psk))) {
			$this->logger->addCritical(__FILE__.": ".__METHOD__.": failed to decrypt IPC message");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		$event = new StdClass();
		$event->ipc = array(
			'message' => $message,
			);
		if ($this->multithreaded) $this->collect_worker_garbage();
		foreach ($params['callbacks'] as $callback) {
			if ($this->multithreaded) {
				$this->worker_pool->submit(
					new Callback($callback, $this, $this->logger, $this->storage, $this->extensions, $event)
					);
			} else {
				$callback($this, $this->logger, $this->storage, $this->extensions, $event);
			}
		}
	}

	public function parse_mqtt_message($topic, $message, $params = null) {
		$function_start = \function_start();
		$this->logger->addInfo(__FILE__.": ".__METHOD__.": received mqtt message");
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": mqtt topic: $topic message: $message");
		if (empty($params['callbacks'])) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": missing params callbacks");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		$event = new StdClass();
		$event->mqtt = array(
			'topic' => $topic,
			'message' => $message
			);
		if ($this->multithreaded) $this->collect_worker_garbage();
		foreach ($params['callbacks'] as $callback) {
			if ($this->multithreaded) {
				$this->worker_pool->submit(
					new Callback($callback, $this, $this->logger, $this->storage, $this->extensions, $event)
					);
			} else {
				$callback($this, $this->logger, $this->storage, $this->extensions, $event);
			}
		}
	}

	public function parse_webhook_message($topic, $message_json, $params = null) {

		$function_start = \function_start();

		$this->logger->addInfo(__FILE__.": ".__METHOD__.": received webhook message");
		if (empty($params['callbacks'])) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": missing params callbacks");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": webhook message: $message_json");
		if (empty($webhook_message = json_decode($message_json, true))) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": webhook message isn't json");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}

		if (empty($webhook_message['filter'])) $webhook_message['filter'] = null;
		if (
			$webhook_message['id'] != $this->existing_webhooks[$webhook_message['id']]['id']
			|| $webhook_message['name'] != $this->existing_webhooks[$webhook_message['id']]['name']
			|| $webhook_message['created'] != $this->existing_webhooks[$webhook_message['id']]['created']
			|| $webhook_message['filter'] != $this->existing_webhooks[$webhook_message['id']]['filter'] 
			) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": received invalid webhook message. could be malicious: expected: ".json_encode($this->existing_webhooks[$webhook_message['id']])." got: ".json_encode($webhook_message));
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}

		$event = new StdClass();
		$event->webhooks = $webhook_message;
		$event->permissions = [];

		if ($this->enable_cache) {

			if ($webhook_message['event'] == 'created') {

				if ($webhook_message['resource'] == 'memberships') {

					$this->logger->addDebug(__FILE__.": ".__METHOD__.": adding cache for ".$webhook_message['resource']." ".$webhook_message['data']['id']);
					$this->cache[$webhook_message['resource']][$webhook_message['data']['id']]['data'] = $webhook_message['data'];
					$this->cache[$webhook_message['resource']][$webhook_message['data']['id']]['timestamp'] = time();

					$this->logger->addDebug(__FILE__.": ".__METHOD__.": adding cache for memberships_room_person room: ".$webhook_message['data']['roomId']." person: ".$webhook_message['data']['personId']);
					$this->cache['memberships_room_person'][$webhook_message['data']['roomId']][$webhook_message['data']['personEmail']]['data'] = $webhook_message['data']['id'];
					$this->cache['memberships_room_person'][$webhook_message['data']['roomId']][$webhook_message['data']['personId']]['data'] = $webhook_message['data']['id'];

					if (!empty($this->cache['memberships_room'][$webhook_message['data']['roomId']])) {
						$this->logger->addDebug(__FILE__.": ".__METHOD__.": adding cache for memberships_room room: ".$webhook_message['data']['roomId']." person: ".$webhook_message['data']['personId']);
						$this->cache['memberships_room'][$webhook_message['data']['roomId']]['data'][$webhook_message['data']['id']] = true;
					}

					$this->cache_updated = true;

				}

			}

			if ($webhook_message['event'] == 'updated') {

				$event->updated = [];
				if (!empty($this->cache[$webhook_message['resource']][$webhook_message['data']['id']])) {
					$event->updated[$webhook_message['resource']] = $this->cache[$webhook_message['resource']][$webhook_message['data']['id']]['data'];
				}

				if ($webhook_message['resource'] == 'memberships') {

					if (!empty($this->cache[$webhook_message['resource']][$webhook_message['data']['id']])) {
						$this->logger->addDebug(__FILE__.": ".__METHOD__.": updating cache for ".$webhook_message['resource']." ".$webhook_message['data']['id']);
						$this->cache[$webhook_message['resource']][$webhook_message['data']['id']]['data'] = $webhook_message['data'];
						$this->cache[$webhook_message['resource']][$webhook_message['data']['id']]['timestamp'] = time();
						$this->cache_updated = true;
					}

				} else {

					if (!empty($this->cache[$webhook_message['resource']][$webhook_message['data']['id']])) {
						$this->logger->addDebug(__FILE__.": ".__METHOD__.": deleting cache due to update for ".$webhook_message['resource']." ".$webhook_message['data']['id']);
						unset($this->cache[$webhook_message['resource']][$webhook_message['data']['id']]);
						$this->cache_updated = true;
					}

				}

			}

			if ($webhook_message['event'] == 'deleted') {

				if (!empty($this->cache[$webhook_message['resource']][$webhook_message['data']['id']])) {
					$this->logger->addDebug(__FILE__.": ".__METHOD__.": deleting cache for ".$webhook_message['resource']." ".$webhook_message['data']['id']);
					unset($this->cache[$webhook_message['resource']][$webhook_message['data']['id']]);
					$this->cache_updated = true;
				}

				if ($webhook_message['resource'] == 'memberships') {

					if ($webhook_message['data']['personId'] == $this->me['id']) {

						if (!empty($this->cache['memberships_room'][$webhook_message['data']['roomId']])) {
							$this->logger->addDebug(__FILE__.": ".__METHOD__.": bot left room. deleting all cache for memberships_room room: ".$webhook_message['data']['roomId']);
							unset($this->cache['memberships_room'][$webhook_message['data']['roomId']]);
							$this->cache_updated = true;
						}
						if (!empty($this->cache['memberships_room_person'][$webhook_message['data']['roomId']])) {
							$this->logger->addDebug(__FILE__.": ".__METHOD__.": bot left room. deleting all cache for memberships_room_person room: ".$webhook_message['data']['roomId']);
							unset($this->cache['memberships_room_person'][$webhook_message['data']['roomId']]);
							$this->cache_updated = true;
						}
						if (!empty($this->cache['rooms'][$webhook_message['data']['roomId']])) {
							$this->logger->addDebug(__FILE__.": ".__METHOD__.": bot left room. deleting all cache for rooms room: ".$webhook_message['data']['roomId']);
							unset($this->cache['rooms'][$webhook_message['data']['roomId']]);
							$this->cache_updated = true;
						}
						foreach ($this->cache['memberships'] as $membership_id => $details) {
							if ($webhook_message['data']['roomId'] == $details['data']['roomId']) {
								$this->logger->addDebug(__FILE__.": ".__METHOD__.": bot left room, deleteing membership cache id: ".$details['data']['id']." room: ".$webhook_message['data']['roomId']);
								unset($this->cache['memberships'][$membership_id]);
								$this->cache_updated = true;
							}
						}
	
					} else {
	
						if (!empty($this->cache['memberships_room_person'][$webhook_message['data']['roomId']][$webhook_message['data']['personId']])) {
							$this->logger->addDebug(__FILE__.": ".__METHOD__.": deleting cache for memberships_room_person room: ".$webhook_message['data']['roomId']." person: ".$webhook_message['data']['personId']);
							unset($this->cache['memberships_room_person'][$webhook_message['data']['roomId']][$webhook_message['data']['personId']]);
							unset($this->cache['memberships_room_person'][$webhook_message['data']['roomId']][$webhook_message['data']['personEmail']]);
							$this->cache_updated = true;
						}
						if (!empty($this->cache['memberships_room'][$webhook_message['data']['roomId']]['data'][$webhook_message['data']['id']])) {
							$this->logger->addDebug(__FILE__.": ".__METHOD__.": deleting cache for memberships_room room: ".$webhook_message['data']['roomId']." person: ".$webhook_message['data']['personId']);
							unset($this->cache['memberships_room'][$webhook_message['data']['roomId']]['data'][$webhook_message['data']['id']]);
						}
	
					}

				}

			}

		}

		if (
			$webhook_message['resource'] == 'messages' 
			&& $webhook_message['event'] == 'created'
			) {

			$this->storage->perm['message_count'][$webhook_message['data']['roomId']] = (!isset($this->storage->perm['message_count'][$webhook_message['data']['roomId']])) ? 0 : $this->storage->perm['message_count'][$webhook_message['data']['roomId']]+1;

			if ($webhook_message['data']['personId'] == $this->me['id']) {
				if (!empty($this->bot_triggers['botmessage']['enabled']['callbacks']))
					$this->logger->addDebug(__FILE__.": ".__METHOD__.": botmessage observation set, so parsing message from bot");
				else {
					$this->logger->addDebug(__FILE__.": ".__METHOD__.": skipping message that the bot created");
					$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
					return;
				}
			}

			if (
				!empty($webhook_message['data']['personEmail'])
				&& !$this->is_admin(array($webhook_message['data']['personEmail']))
				&& !empty($webhook_message['data']['roomId'])
				&& !empty($this->trusted_domains[$webhook_message['data']['roomId']])
				) {

				$this->logger->addDebug(__FILE__.": ".__METHOD__.": trusted_domains set, so authorizing webhook messages");

				$trusted_domains = $this->trusted_domains[$webhook_message['data']['roomId']];

				$trusted_domain_regex = '/[@\.](';
				foreach ($trusted_domains as $trusted_domain) $trusted_domain_regex .= preg_quote($trusted_domain, '/').'|';
				$trusted_domain_regex = rtrim($trusted_domain_regex, '|');
				$trusted_domain_regex .= ')$/';
				if (preg_match($trusted_domain_regex, $webhook_message['data']['personEmail']) == 0) {
					$this->logger->addWarning(__FILE__.": ".__METHOD__.": webhook message personEmail is not a trusted domain: ".$webhook_message['data']['personEmail']);
					$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
					return false;
				} else $event->permissions['trusted_domain'] = true;
			}

		}

		foreach (array_keys($this->spark_endpoints[$this->message_version]) as $api) {
	      foreach (array_keys($this->spark_endpoints[$this->message_version][$api]) as $api_path) {
   	      if (preg_match("/\/{([^}]+)}/", $api_path, $matches) > 0) {
					if ($api == $webhook_message['resource']) $id_name = $matches[1];
					$endpoint_id_names[$matches[1]] = $api;
				}
   	   }
		}

		if (
			!empty($webhook_message['data']['personEmail'])
			) {
			$event->permissions['super_user'] = false;
			$event->permissions['admin'] = false;
			$event->permissions['super_admin'] = false;
			if (in_array($webhook_message['data']['personEmail'], $this->admins)) $event->permissions['admin'] = true;
			if (in_array($webhook_message['data']['personEmail'], $this->super_admins)) $event->permissions['super_admin'] = true;
			if (!empty($this->super_users_room)) {
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": super_users_room set, so checking membership");
				$membership_details = $this->memberships('GET', ['roomId' => $this->super_users_room, 'personEmail' => $webhook_message['data']['personEmail'] ]);
        		if (!empty($membership_details['items'][0])) {
					$event->permissions['super_user'] = true;
					$this->logger->addInfo(__FILE__.": ".__METHOD__.": member of super_users room: ".$webhook_message['data']['personEmail']);
				}
			}
		}

		if (
			$webhook_message['event'] == 'deleted'
			|| $webhook_message['resource'] == 'memberships'
			) $event->$webhook_message['resource'] = $webhook_message['data'];
		else if (
			!empty($id_name)
			&& empty($event->$webhook_message['resource'] = $this->$webhook_message['resource']('GET', array($id_name => $webhook_message['data']['id'])))
			) $this->logger->addError(__FILE__.": ".__METHOD__.": couldn't get webhook resource details: id name: $id_name id: ".$webhook_message['data']['id']);

		if (
			$this->get_all_webhook_data == true
			&& !empty($event->$webhook_message['resource'])
			) {
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": getting all webhook data from all endpoints");
			foreach ($event->$webhook_message['resource'] as $resource_detail_key => $resource_detail_value) {
				if (!empty($endpoint_id_names[$resource_detail_key])) {
					if ($webhook_message['event'] == 'deleted') {
						if ($endpoint_id_names[$resource_detail_key] == $webhook_message['resource']) {
							$event->$endpoint_id_names[$resource_detail_key] = $webhook_message['data'];
						} else if (
							$webhook_message['resource'] == 'memberships'
							&& $endpoint_id_names[$resource_detail_key] == 'rooms'
							&& $webhook_message['data']['personId'] == $this->me['id']
							) {
							$event->rooms = [ 'id' => $webhook_message['data']['roomId'] ];
						}
					}
					if (empty($event->$endpoint_id_names[$resource_detail_key])) {
						$get_resource_params = array($resource_detail_key => $resource_detail_value);
						//if ($endpoint_id_names[$resource_detail_key] == 'rooms') $get_resource_params['showSipAddress'] = true;
						if (empty($event->$endpoint_id_names[$resource_detail_key] = $this->$endpoint_id_names[$resource_detail_key]('GET', $get_resource_params))) 
							$this->logger->addError(__FILE__.": ".__METHOD__.": couldn't get webhook resource details: id name: ".$resource_detail_key." id: ".$resource_detail_value);
					}
				}
			}
			if (!empty($event->rooms['id'])) {

				if (
					$event->webhooks['resource'] == 'memberships'
					&& $event->webhooks['event'] == 'deleted'
					&& $event->webhooks['data']['personId'] == $this->me['id']
					) {
					$this->logger->addInfo(__FILE__.": ".__METHOD__.": no memberships to get since bot was removed from room");
				} else if ($this->get_room_membership) {
					$this->logger->addInfo(__FILE__.": ".__METHOD__.": getting complete room membership");
					if (empty($complete_memberships = $this->memberships('GET', array('roomId' => $event->rooms['id']))))
						$this->logger->addError(__FILE__.": ".__METHOD__.": couldn't get webhook memberships resource details: roomId: ".$event->rooms['id']);
					else {
						foreach ($complete_memberships['items'] as $index => $membership_details) {
							if (!empty($event->people['id'])) {
								if ($membership_details['personId'] == $event->people['id']) {
									$single_membership = ['items' => [$complete_memberships['items'][$index]]];
									unset($complete_memberships['items'][$index]);
									break;
								}
							}
						}
					}
					if (!empty($single_membership))
						$event->memberships = [ 'items' => array_merge($single_membership['items'], $complete_memberships['items']) ];
					else
						$event->memberships = $complete_memberships;
					unset($complete_memberships);
				} else if (
					$event->webhooks['resource'] != 'memberships'
					&& !empty($event->people['id'])
					) {
					if (empty($event->memberships = $this->memberships('GET', array('roomId' => $event->rooms['id'], 'personId' => $event->people['id']))))
						$this->logger->addError(__FILE__.": ".__METHOD__.": couldn't get webhook memberships resource details: roomId: ".$event->rooms['id']." personId: ".$event->people['id']);
				}

			}

			if (!empty($event->updated)) {
				foreach ($event->updated as $resource => $orig_data) {
					$updated = false;
					if (!empty($event->$resource)) {
						if ($resource == 'memberships') {
							if ($event->{$resource}['items'][0]['id'] == $orig_data['id']) {
								$event->updated[$resource] = array_diff_assoc($event->{$resource}['items'][0], $orig_data);
								$event->updated[$resource]['id'] = $event->{$resource}['items'][0]['id'];
								$updated = true;
							}
						} else {
							if ($event->{$resource}['id'] == $orig_data['id']) {
								$event->updated[$resource] = array_diff_assoc($event->$resource, $orig_data);
								$event->updated[$resource]['id'] = $event->{$resource}['id'];
								$updated = true;
							}
						}
						if (isset($event->updated[$resource]['created'])) unset($event->updated[$resource]['created']);
						if (isset($event->updated[$resource]['lastActivity'])) unset($event->updated[$resource]['lastActivity']);
					}
					if (!$updated) unset($event->updated[$resource]);
				}
			}

		}

		if (
			$webhook_message['resource'] == 'memberships' 
			&& $webhook_message['event'] == 'created'
			&& $webhook_message['data']['personId'] == $this->me['id']
			) {

			$this->enabled_rooms[$event->rooms['id']] = $this->default_enabled_room;

			if (
				$this->get_room_type == 'all'
				|| (
					!empty($event->rooms['type'])
					&& $this->get_room_type == $event->rooms['type']
					)
				) {
				$this->logger->addInfo(__FILE__.": ".__METHOD__.": adding new room to existing_rooms: ".$event->rooms['id']);
				$this->existing_rooms[$event->rooms['id']] = $event->rooms;
			}

			if ($this->new_room_announce) {
				if ($this->default_enabled_room)
						$text = "I'm ready to go. /help to get started."; 
				else {
					$text = "An admin or super user needs to enable me with /bot/on. Here are my admins: ";
					foreach ($this->admins as $admin) {
						if (!empty(in_array($admin, $this->super_admins))) $text .= $admin."*, ";
						else $text .= $admin.", ";
					}
					$text = rtrim($text, ', ');
				}
				$this->messages('POST', [ 'roomId' => $webhook_message['data']['roomId'], 'text' => $text ]);
			}

			if (!empty($this->bot_triggers['newroom']['enabled']['callbacks'])) {
				foreach ($this->bot_triggers['newroom']['enabled']['callbacks'] as $callback) {
					if ($this->multithreaded) {
						$this->worker_pool->submit(
							new Callback($callback, $this, $this->logger, $this->storage, $this->extensions, $event)
							);
					} else {
						$callback($this, $this->logger, $this->storage, $this->extensions, $event);
					}
				}
			}

		}

		if (
			!empty($event->rooms['id'])
			&& !empty($this->existing_rooms[$event->rooms['id']])
			) {
			if (
				$webhook_message['resource'] == 'rooms' 
				&& $webhook_message['event'] == 'updated'
				) {
				$this->logger->addInfo(__FILE__.": ".__METHOD__.": updating room in existing_rooms: ".$event->rooms['id']);
				$this->room_before_update = $this->existing_rooms[$event->rooms['id']];
				$this->existing_rooms[$event->rooms['id']] = $event->rooms;
			} else if (
				$webhook_message['resource'] == 'memberships' 
				&& $webhook_message['event'] == 'deleted'
				&& $webhook_message['data']['personId'] == $this->me['id']
				) {
				$this->logger->addInfo(__FILE__.": ".__METHOD__.": deleting room from existing_rooms: ".$event->rooms['id']);
				unset($this->existing_rooms[$event->rooms['id']]);
            if (!empty($this->enabled_rooms[$event->rooms['id']])) unset($this->enabled_rooms[$event->rooms['id']]);
            if (!empty($this->enabled_rooms_file)) $this->save_state_file($this->enabled_rooms_file, $this->enabled_rooms);
			}
		}

		if (
			!empty($event->rooms['type'])
			&& $event->rooms['type'] == 'direct'
			&& empty($this->webhook_direct) 
			) {
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": room is direct and webhook_direct is false");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}

		if (
			$webhook_message['resource'] == 'messages' 
			&& $webhook_message['event'] == 'created'
			&& $webhook_message['data']['personId'] == $this->me['id']
			&& !empty($this->bot_triggers['botmessage']['enabled']['callbacks'])
			) {
			foreach ($this->bot_triggers['botmessage']['enabled']['callbacks'] as $callback) {
				if ($this->multithreaded) {
					$this->worker_pool->submit(
						new Callback($callback, $this, $this->logger, $this->storage, $this->extensions, $event)
						);
				} else {
					$callback($this, $this->logger, $this->storage, $this->extensions, $event);
				}
			}
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": skipping processing of message that the bot created");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return;
		}

		foreach ($params['callbacks'] as $callback) {
			$callback($this, $this->logger, $this->storage, $this->extensions, $event);
		}

		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));

	}

	protected function prepare_webhook_target_url($method, $params) {
		$function_start = \function_start();

		$orig_target_url = '';
		if ($method == 'POST' && !empty($params['targetUrl'])) {
			$orig_target_url = $params['targetUrl'];
			if (empty($params['targetUrl'])) {
				$this->logger->addError(__FILE__.": ".__METHOD__.": missing function parameter: params targetUrl");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return array($params, $orig_target_url);
			}
			if (!is_callable($params['targetUrl'])) {
				$this->logger->addInfo(__FILE__.": ".__METHOD__.": not a callable function. assuming its a URL");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return array($params, $orig_target_url);
			}
			if (empty($this->broker->target_url)) {
				$this->logger->addInfo(__FILE__.": ".__METHOD__.": missing class parameter: broker target_url");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return array($params, $orig_target_url);
			}
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": using broker for webhook messages");
			$webhook_target_topic = str_replace("[email]", $this->me['emails'][0], $this->webhook_target_topic);
			$params['targetUrl'] = preg_replace("/{topic}/", $webhook_target_topic, $this->broker->target_url);
		}
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return array($params, $orig_target_url);

	}

	protected function from_utf16be_to_utf8($string) {
		return preg_replace_callback('/\\\\u([0-9a-fA-F]{4})(\\\\u([0-9a-fA-F]{4}))?/', function ($match) {
			if (!empty($match[3])) $utf16be = $match[1].$match[3];
			else $utf16be = $match[1];
			return mb_convert_encoding(pack('H*', $utf16be), 'UTF-8', 'UTF-16BE');
		}, $string);
	}

	protected function prepare_message_text($params) {
		$function_start = \function_start();

		if (!empty($params['markdown'])) {
			if (is_callable('mb_convert_encoding')) {
				$this->logger->addInfo(__FILE__.": ".__METHOD__.": converting \u escaped unicode");
				$params['markdown'] = $this->from_utf16be_to_utf8($params['markdown']);
			} else
				$this->logger->addWarning(__FILE__.": ".__METHOD__.": can't convert \u escaped unicode. compile php with --enable-mbstring or enable mbstring extension");
		} else unset($params['markdown']);
		if (!empty($params['text'])) {
			if (is_callable('mb_convert_encoding')) {
				$this->logger->addInfo(__FILE__.": ".__METHOD__.": converting \u escaped unicode");
				$params['text'] = $this->from_utf16be_to_utf8($params['text']);
			} else
				$this->logger->addWarning(__FILE__.": ".__METHOD__.": can't convert \u escaped unicode. compile php with --enable-mbstring or enable mbstring extension");
		} else unset($params['text']);
		$this->logger->addInfo(__FILE__.": ".__METHOD__.": prepared message text");
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $params;

	}

	protected function prepare_message_files($params) {
		$function_start = \function_start();

			if (
				!empty($params['files']) && 
				is_file($params['files'][0]) &&
				class_exists($this->files_storage->class)
				) {
				$this->logger->addInfo(__FILE__.": ".__METHOD__.": getting ready to upload temp files to files_storage");
				$files_storage = new $this->files_storage->class($this->logger, $this->config);
				$pid = pcntl_fork();
				if ($pid === -1) {
					$this->logger->addError(__FILE__.": ".__METHOD__.": couldn't create child process");
				} elseif ($pid === 0) {
					$this->logger->addDebug(__FILE__.": ".__METHOD__.": created child process");
					if (empty($files_storage->delete_temp_subfolders()))
						$this->logger->addError(__FILE__.": ".__METHOD__.": couldn't clean up temp folder");
					else
						$this->logger->addInfo(__FILE__.": ".__METHOD__.": cleaned up temp folder");
					exit();
				} else {
					// parent
					if (empty($file_id = $files_storage->temp_upload($params['files'][0]))) {
						$this->logger->addError(__FILE__.": ".__METHOD__.": couldn't do a temp upload");
					} else if (empty($file_url = $files_storage->create_shared_link($file_id))) {
						$this->logger->addError(__FILE__.": ".__METHOD__.": couldn't create shared link for temp upload");
					} else $params['files'] = array($file_url);
				}
				unset($files_storage);
			}
			if (!empty($params['files'])) $this->logger->addDebug(__FILE__.": ".__METHOD__.": files: ".serialize($params['files']));
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": prepared message files");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return $params;

	}

	protected function prepare_params($api, $method, $params) {
		$function_start = \function_start();

		if (strlen($api) == 0) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": missing function parameter: api");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (strlen($method) == 0) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": missing function parameter: method");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (empty($this->tokens['access_token'])) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": missing class parameter: access_token");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (empty($params)) {
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": missing function parameter: params");
		}
		if (empty($this->api_url)) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": missing class parameter: api_url");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}

		$api_url = $this->api_url.$api;
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": api_url: $api_url");
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": message_version: $this->message_version");
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": api: $api");
      foreach (array_keys($this->spark_endpoints[$this->message_version][$api]) as $api_path) {
         if (preg_match("/\/{([^}]+)}/", $api_path, $matches) > 0) {
				if (!empty($params[$matches[1]])) {
					$api_url .= '/'.$params[$matches[1]];
				}
				break;
			}
      }
		$this->logger->addInfo(__FILE__.": ".__METHOD__.": prepared params");
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return array($api_url, $params);

	}

	protected function validate_params($api, $method, $params) {
		$function_start = \function_start();

		if (strlen($this->message_version) == 0) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": missing class parameter: message_version");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}

		if (!isset($this->spark_endpoints[$this->message_version])) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": invalid class parameter: message_version: ".$this->message_version);
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (!isset($this->spark_endpoints[$this->message_version][$api])) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": invlaid function parameter: api: ".$api);
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		foreach (array_keys($this->spark_endpoints[$this->message_version][$api]) as $api_path_tmp) {
			if ((preg_match("/\/{([^}]+)}/", $api_path_tmp, $matches)) > 0) {
				$api_id_name = $matches[1];
				break;
			}
		}
		if (empty($params[$api_id_name])) $api_path = '/';
		else if ($this->spark_endpoints[$this->message_version][$api] == 'people' && $params[$api_id_name] == 'me') $api_path = '/me';
		else $api_path = "/{".$api_id_name."}";
		if (!isset($this->spark_endpoints[$this->message_version][$api][$api_path])) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": invalid message endpoint path: ".$api_path);
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (!isset($this->spark_endpoints[$this->message_version][$api][$api_path][$method])) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": invalid function parameter: method: ".$method);
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (!empty($params)) {
			foreach (array_keys($params) as $param_name) {
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": param_name: ".$param_name.": param_value: ".serialize($params[$param_name]));
				if (!isset($this->spark_endpoints[$this->message_version][$api][$api_path][$method]['params'][$param_name])) {
					$this->logger->addError(__FILE__.": ".__METHOD__.": invalid message endpoint parameter: ".$param_name);
					$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
					return false;
				}
				$param_type = $this->spark_endpoints[$this->message_version][$api][$api_path][$method]['params'][$param_name]['type'];
				if (!$this->validate_param_type($params[$param_name], $param_type)) {
					$this->logger->addError(__FILE__.": ".__METHOD__.": invalid message endpoint parameter type ($param_type): $param_name");
					$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
					return false;
				}
			}
		}
		foreach ($this->spark_endpoints[$this->message_version][$api][$api_path][$method]['required']['and'] as $required_param) {
			if (!isset($params[$required_param])) {
				$this->logger->addError(__FILE__.": ".__METHOD__.": missing message endpoint required parameter: $param_name");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return false;
			}
		}
		if (count($this->spark_endpoints[$this->message_version][$api][$api_path][$method]['required']['or']) > 0) {
			foreach ($this->spark_endpoints[$this->message_version][$api][$api_path][$method]['required']['or'] as $required_param) {
				if (isset($params[$required_param])) $have_one_required_or_param = true;
			}
			if (empty($have_one_required_or_param)) {
				$this->logger->addError(__FILE__.": ".__METHOD__.": missing message endpoint required parameter: (".implode(" or ", $this->spark_endpoints[$this->message_version][$api][$api_path][$method]['required']['or']).")");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return false;
			}
		}
		$this->logger->addInfo(__FILE__.": ".__METHOD__.": validated params");
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return true;

	}

	protected function validate_param_type($param_value, $param_type) {
		$function_start = \function_start();
		// need to check min max and character types once we have all the correct values

		if (is_array($param_value)) {
			foreach ($param_value as $one_param_value) {
				if (empty($this->check_type($one_param_value, $param_type))) {
					$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
					return false;
				}
			}
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return true;
		} else {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return $this->check_type($param_value, $param_type);
		}

	}

	protected function check_type($param_value, $param_type) {
		$function_start = \function_start();
		switch ($param_type) {
			case 'id':
				$id_regex = "/^[a-zA-Z0-9]+$/";
				$result = (preg_match($id_regex, $param_value) > 0);
				break;
			case 'integer':
				$result = is_int($param_value);
				break;
			case 'string':
				$result = is_string($param_value);
				break;
			case 'boolean':
				$result = is_bool($param_value);
				break;
			case 'email':
				$result = filter_var($param_value, FILTER_VALIDATE_EMAIL);
				break;
			case 'file':
				$result = is_file($param_value);
				break;
			case 'url':
				$result = filter_var($param_value, FILTER_VALIDATE_URL);
				break;
			case 'iso8601':
				$iso8601_regex = "/^([\+-]?\d{4}(?!\d{2}\b))((-?)((0[1-9]|1[0-2])(\3([12]\d|0[1-9]|3[01]))?|W([0-4]\d|5[0-2])(-?[1-7])?|(00[1-9]|0[1-9]\d|[12]\d{2}|3([0-5]\d|6[1-6])))([T\s]((([01]\d|2[0-3])((:?)[0-5]\d)?|24\:?00)([\.,]\d+(?!:))?)?(\17[0-5]\d([\.,]\d+)?)?([zZ]|([\+-])([01]\d|2[0-3]):?([0-5]\d)?)?)?)?$/";
				$result = (preg_match($iso8601_regex, $param_value) > 0);
				break;
			default:
				$result = '';
				break;
		}
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": param_value: $param_value param_type: $param_type result: $result");
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $result;
	}

	public function respond($event, $text, $files = null, $private = false) {
		if ($private) {
			if (empty($event->people['id'])) {
				$this->logger->addError(__FILE__.": ".__METHOD__.": missing event parameter: rooms['id']");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return false;
			}
			if (!empty($files)) $return = $this->messages('POST', array('markdown' => $text, 'files' => $files, 'toPersonId' => $event->people['id']));
			else $return = $this->messages('POST', array('markdown' => $text, 'toPersonId' => $event->people['id']));
		} else {
			if (empty($event->rooms['id'])) {
				$this->logger->addError(__FILE__.": ".__METHOD__.": missing event parameter: rooms['id']");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return false;
			}
			if (!empty($files)) $return = $this->messages('POST', array('markdown' => $text, 'files' => $files, 'roomId' => $event->rooms['id']));
			else $return = $this->messages('POST', array('markdown' => $text, 'roomId' => $event->rooms['id']));
		}
		return $return;
	}

	public function broadcast($text, $files = null) {
		$return = true;
		foreach (array_keys($this->enabled_rooms) as $room_id) {
			if (!empty($files)) {
				if (empty($this->messages('POST', array('markdown' => $text, 'files' => $files, 'roomId' => $room_id)))) $return = false;;
			} else {
				if (empty($this->messages('POST', array('markdown' => $text, 'roomId' => $room_id)))) $return = false;;
			}
		}
		return $return;
	}

	public function respond_private($event, $text, $files = null) {
		return $this->respond($event, $text, $files, true);
	}

	public function clear_observation($event) {
		$return = false;
		if (!empty($event->messages['id'])) {
			$return = $this->messages('DELETE', array('messageId' => $event->messages['id']));
		}
		return $return;
	}

   protected function set_bot_variables() {
		$function_start = \function_start();

      if (empty($this->config['spark']['default_allowed_domains'])) $this->logger->addWarning(__FILE__.": missing configuration parameters: default_allowed_domains");
      else $this->default_allowed_domains = $this->config['spark']['default_allowed_domains'];

      if (empty($this->config['spark']['allowed_domains_file'])) $this->logger->addWarning(__FILE__.": missing configuration parameters: allowed_domains_file");
      else $this->allowed_domains_file = $this->config['spark']['allowed_domains_file'];

		if (!empty($this->allowed_domains_file)) {
			if (!empty($settings = $this->load_state_file('allowed_domains_file')))
				$this->allowed_domains = $settings;
		}

      if (empty($this->config['spark']['default_trusted_domains'])) $this->logger->addWarning(__FILE__.": missing configuration parameters: default_trusted_domains");
      else $this->default_trusted_domains = $this->config['spark']['default_trusted_domains'];

      if (empty($this->config['spark']['trusted_domains_file'])) $this->logger->addWarning(__FILE__.": missing configuration parameters: trusted_domains_file");
      else $this->trusted_domains_file = $this->config['spark']['trusted_domains_file'];

		if (!empty($this->trusted_domains_file)) {
			if (!empty($settings = $this->load_state_file('trusted_domains_file')))
				$this->trusted_domains = $settings;
		}

      if (empty($this->config['spark']['admins'])) $this->logger->addWarning(__FILE__.": missing configuration parameters: admins");
      else {
			$this->admins = $this->config['spark']['admins'];
			$this->super_admins = $this->admins;
		}

      if (empty($this->config['spark']['admins_file'])) $this->logger->addWarning(__FILE__.": missing configuration parameters: admins_file");
      else $this->admins_file = $this->config['spark']['admins_file'];

		if (!empty($this->admins_file)) {
			if (!empty($settings = $this->load_state_file('admins_file'))) {
				$this->admins = $settings;
				if (!empty($this->super_admins)) $this->admins = array_unique(array_merge($this->admins, $this->super_admins));
			}
		}

      if (!isset($this->config['spark']['new_room_announce']) || !is_bool((bool) $this->config['spark']['new_room_announce'])) $this->logger->addWarning(__FILE__.": missing configuration parameters: new_room_announce");
      else $this->new_room_announce = (bool) $this->config['spark']['new_room_announce'];

      if (!isset($this->config['spark']['user_management']) || !is_bool((bool) $this->config['spark']['user_management'])) $this->logger->addWarning(__FILE__.": missing configuration parameters: user_management");
      else $this->user_management = (bool) $this->config['spark']['user_management'];

      if (!isset($this->config['spark']['default_require_mention']) || !is_bool((bool) $this->config['spark']['default_require_mention'])) $this->logger->addWarning(__FILE__.": missing configuration parameters: default_require_mention");
      else $this->default_require_mention = (bool) $this->config['spark']['default_require_mention'];

      if (empty($this->config['spark']['require_mention_file'])) $this->logger->addWarning(__FILE__.": missing configuration parameters: require_mention_file");
      else $this->require_mention_file = $this->config['spark']['require_mention_file'];

		if (!empty($this->require_mention_file)) {
			if (!empty($settings = $this->load_state_file('require_mention_file')))
				$this->require_mention = $settings;
		}

      if (empty($this->config['spark']['default_super_users_room'])) $this->logger->addWarning(__FILE__.": missing configuration parameters: default_super_users_room");
      else $this->super_users_room = $this->config['spark']['default_super_users_room'];

      if (empty($this->config['spark']['super_users_room_file'])) $this->logger->addWarning(__FILE__.": missing configuration parameters: super_users_room_file");
      else $this->super_users_room_file = $this->config['spark']['super_users_room_file'];

		if (!empty($this->super_users_room_file)) {
			if (!empty($this->super_users_room)) $this->logger->addInfo(__FILE__.": default super_users room setting: ".json_encode($this->super_users_room));
			if (!empty($settings = $this->load_state_file('super_users_room_file')))
				$this->super_users_room = $settings;
		}

      if (!isset($this->config['spark']['default_enabled_room']) || !is_bool((bool) $this->config['spark']['default_enabled_room'])) $this->logger->addWarning(__FILE__.": missing configuration parameters: default_enabled_room");
      else $this->default_enabled_room = (bool) $this->config['spark']['default_enabled_room'];

      if (empty($this->config['spark']['enabled_rooms_file'])) $this->logger->addWarning(__FILE__.": missing configuration parameters: enabled_rooms_file");
      else $this->enabled_rooms_file = $this->config['spark']['enabled_rooms_file'];

		if (!empty($this->enabled_rooms_file)) {
			$this->logger->addInfo(__FILE__.": default room enabled setting: ".json_encode($this->default_enabled_room));
			if (!empty($settings = $this->load_state_file('enabled_rooms_file')))
				$this->enabled_rooms = $settings;
		}

      if (empty($this->config['spark']['bot_control_command'])) $this->logger->addWarning(__FILE__.": missing configuration parameters: bot_control_command");
      else $this->bot_control_command = $this->config['spark']['bot_control_command'];

      if (empty($this->config['spark']['ipc_channel_seed'])) $this->logger->addWarning(__FILE__.": missing configuration parameters: ipc_channel_seed");
      else $this->ipc_channel_seed = $this->config['spark']['ipc_channel_seed'];

      if (empty($this->config['spark']['ipc_channel_psk'])) $this->logger->addWarning(__FILE__.": missing configuration parameters: ipc_channel_psk");
      else $this->ipc_channel_psk = $this->config['spark']['ipc_channel_psk'];

		if (!isset($this->config['spark']['get_room_membership']) || !is_bool((bool) $this->config['spark']['get_room_membership'])) $this->logger->addWarning(__FILE__.": missing configuration parameters: get_room_membership");
		else $this->get_room_membership = (bool) $this->config['spark']['get_room_membership'];

		if (!isset($this->config['spark']['get_all_webhook_data']) || !is_bool((bool) $this->config['spark']['get_all_webhook_data'])) $this->logger->addWarning(__FILE__.": missing configuration parameters: get_all_webhook_data");
		else $this->get_all_webhook_data = (bool) $this->config['spark']['get_all_webhook_data'];

		if (!isset($this->config['spark']['passive']) || !is_bool((bool) $this->config['spark']['passive'])) $this->logger->addWarning(__FILE__.": missing configuration parameters: passive");
		else $this->passive = (bool) $this->config['spark']['passive'];

		if (!isset($this->config['spark']['delete_last_help']) || !is_bool((bool) $this->config['spark']['delete_last_help'])) $this->logger->addWarning(__FILE__.": missing configuration parameters: delete_last_help");
		else $this->delete_last_help = (bool) $this->config['spark']['delete_last_help'];

		if (!isset($this->config['spark']['direct_help']) || !is_bool((bool) $this->config['spark']['direct_help'])) $this->logger->addWarning(__FILE__.": missing configuration parameters: direct_help");
		else $this->direct_help = (bool) $this->config['spark']['direct_help'];

		if (!isset($this->config['spark']['report_slow']) || !is_bool((bool) $this->config['spark']['report_slow'])) $this->logger->addWarning(__FILE__.": missing configuration parameters: report_slow");
		else $this->report_slow = (bool) $this->config['spark']['report_slow'];

		if (!isset($this->config['spark']['webhook_direct']) || !is_bool((bool) $this->config['spark']['webhook_direct'])) $this->logger->addWarning(__FILE__.": missing configuration parameters: webhook_direct");
		else $this->webhook_direct = (bool) $this->config['spark']['webhook_direct'];

		if (!isset($this->config['spark']['enable_cache']) || !is_bool((bool) $this->config['spark']['enable_cache'])) $this->logger->addWarning(__FILE__.": missing configuration parameters: enable_cache");
		else $this->enable_cache = (bool) $this->config['spark']['enable_cache'];

      if (empty($this->config['spark']['cache_saves_in'])) $this->logger->addWarning(__FILE__.": missing configuration parameters: cache_saves_in");
      else $this->loop_timers['save_cache'] = $this->config['spark']['cache_saves_in'];

      if (empty($this->config['spark']['cache_expires_in'])) $this->logger->addWarning(__FILE__.": missing configuration parameters: cache_expires_in");
      else {
			$this->cache_expires_in = $this->config['spark']['cache_expires_in'];
			if ($this->cache_expires_in == -1)
				$this->logger->addInfo(__FILE__.": spark cache will never expire");
		}

		if (empty($this->config['spark']['cache_file'])) {
			$this->logger->addWarning(__FILE__.": missing configuration parameters: cache_file");
			if ($this->enable_cache) $this->logger->addError(__FILE__.": !!! no file to save cache. this will impact bot performance if restarted !!!");
		} else $this->cache_file = $this->config['spark']['cache_file'];

		if (!empty($this->cache_file)) {
			if (!empty($settings = $this->load_state_file('cache_file'))) {
				$this->cache = $settings;
				if (!empty($this->cache['memberships_room_person'])) unset($this->cache['memberships_room_person']);
				if (!empty($this->cache['memberships_room'])) unset($this->cache['memberships_room']);
				if (!empty($this->cache['memberships'])) unset($this->cache['memberships']);
				$this->clean_cache();
			}
		}

		if (!isset($this->config['spark']['show_complete_invalid_command']) || !is_bool((bool) $this->config['spark']['show_complete_invalid_command'])) $this->logger->addWarning(__FILE__.": missing configuration parameters: show_complete_invalid_command");
		else $this->show_complete_invalid_command = (bool) $this->config['spark']['show_complete_invalid_command'];

		if (!isset($this->config['spark']['detect_malformed_commands']) || !is_bool((bool) $this->config['spark']['detect_malformed_commands'])) $this->logger->addWarning(__FILE__.": missing configuration parameters: detect_malformed_commands");
		else $this->detect_malformed_commands = (bool) $this->config['spark']['detect_malformed_commands'];

		if (!isset($this->config['spark']['detect_unknown_commands']) || !is_bool((bool) $this->config['spark']['detect_unknown_commands'])) $this->logger->addWarning(__FILE__.": missing configuration parameters: detect_unknown_commands");
		else $this->detect_unknown_commands = (bool) $this->config['spark']['detect_unknown_commands'];

		if (!isset($this->config['spark']['delete_invalid_commands']) || !is_bool((bool) $this->config['spark']['delete_invalid_commands'])) $this->logger->addWarning(__FILE__.": missing configuration parameters: delete_invalid_commands");
		else $this->delete_invalid_commands = (bool) $this->config['spark']['delete_invalid_commands'];

		if (empty($this->config['spark']['get_room_type']) || !in_array($this->config['spark']['get_room_type'], $this->room_types)) $this->logger->addWarning(__FILE__.": missing configuration parameters: get_room_type");
		else $this->get_room_type = $this->config['spark']['get_room_type'];

		//if (empty($this->config['spark']['overclock'])) $this->logger->addWarning(__FILE__.": missing configuration parameters: overclock");
		//else $this->overclock = $this->config['spark']['overclock'];

		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
   }

	public function set_access_token($access_token) {
		$this->tokens['access_token'] = $access_token;
	}

	public function set_tokens($access_token, $expires_in = 0, $refresh_token = null, $refresh_token_expires_in = 0, $get_me = true) {
		$function_start = \function_start();
		if (strlen($access_token) == 0) {
			$this->logger->addCritical(__FILE__.": ".__METHOD__.": missing function parameter: access_token");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if ($get_me) {
			if (empty($this->me = $this->spark_api('GET', 'people', $this->api_url.'people/me', null, $access_token))) {
				$this->logger->addCritical(__FILE__.": ".__METHOD__.": couldn't get me when setting access_token");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return false;
			}
			$this->set_me_mention_regex();
		}
		$this->tokens = array(
			'access_token_timestamp' => time(),
			'access_token' => $access_token,
			'expires_in' => $expires_in,
			'refresh_token' => $refresh_token,
			'refresh_token_timestamp' => time(),
			'refresh_token_expires_in' => $refresh_token_expires_in,
			);

		if (!empty($this->token_file)) {
			if (!file_exists(dirname($this->token_file))) {
			   if (!mkdir(dirname($this->token_file), 0660, true)) {
					$this->logger->addError(__FILE__.": ".__METHOD__.": can't create directory: ".dirname($this->token_file));
					$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
					return false;
				}
			}
			if (!file_exists($this->token_file)) {
				if (!touch($this->token_file)) {
					$this->logger->addError(__FILE__.": ".__METHOD__.": can't create file: ".$this->token_file);
					$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
					return false;
				}
			}
			if (!chmod($this->token_file, 0640)) {
				$this->logger->addError(__FILE__.": ".__METHOD__.": can't chmod file to 0640: ".$this->token_file);
			}
			if (!file_put_contents($this->token_file, json_encode($this->tokens))) {
				$this->logger->addError(__FILE__.": ".__METHOD__.": failed to write tokens to disk");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return false;
			} else {
				if (file_exists($this->token_file.'.lock')) {
					$this->logger->addInfo(__FILE__.": ".__METHOD__.": found get_bot_tokens lock file");
					if (unlink($this->token_file.'.lock'))
						$this->logger->addInfo(__FILE__.": ".__METHOD__.": removed get_bot_tokens lock file");
					else {
						$this->logger->addError(__FILE__.": ".__METHOD__.": couldn't remove get_bot_tokens lock file");
						$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
						return false;
					}
				}
			}
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return true;
		}
	}

	public function get_bot_tokens($access_code = false) {
		$function_start = \function_start();

		if (empty($this->token_file)) $this->logger->addWarning(__FILE__.": ".__METHOD__.": missing class parameter: token_file: PERFORMANCE WILL BE IMPACTED");
	
		if (file_exists($this->token_file)) {
			if (empty($tokens_file_content = @file_get_contents($this->token_file))) $this->logger->addWarning(__FILE__.": ".__METHOD__.": token_file is empty");
			else {

				if (empty($tokens = json_decode($tokens_file_content, true))) $this->logger->addError(__FILE__.": ".__METHOD__.": token_file contents are not formatted correctly");
				else {
					if (
						!empty($tokens['access_token_timestamp'])
						&& !empty($tokens['access_token'])
						&& !empty($tokens['expires_in'])
						) {
		
						if (
							!empty($tokens['refresh_token'])
							&& !empty($tokens['refresh_token_timestamp'])
							&& !empty($tokens['refresh_token_expires_in'])
							&& (($tokens['refresh_token_expires_in']+$tokens['refresh_token_timestamp'] - time()) < $this->refresh_token_expiration_lookahead*86400) 
							) {
							$this->logger->addInfo(__FILE__.": ".__METHOD__.": refresh_token has expired or will in less than an hour");
						} else {
							if (($tokens['expires_in']+$tokens['access_token_timestamp'] - time()) < $this->access_token_expiration_lookahead) $this->logger->addInfo(__FILE__.": ".__METHOD__.": access_token has expired or will in less than an hour");
							else if (!empty($this->me = $this->validate_users_access_token($this->machine_account, $tokens['access_token']))) {
								$this->set_me_mention_regex();
								$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
								return $tokens;
							}
							if (
								!empty($tokens['refresh_token'])
								&& !empty($access_token_details = $this->refresh_access_token($tokens['refresh_token']))
								&& !empty($this->me = $this->validate_users_access_token($this->machine_account, $access_token_details['access_token'])) 
								) {
								$tokens['access_token'] = $access_token_details['access_token'];
								$tokens['expires_in'] = $access_token_details['expires_in'];
								$tokens['access_token_timestamp'] = time();
								if (!file_put_contents($this->token_file, json_encode($tokens))) $this->logger->addError(__FILE__.": ".__METHOD__.": failed to write tokens to disk");
								$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
								return $tokens;
							}
						}
					} else $this->logger->addError(__FILE__.": ".__METHOD__.": token_file is missing parameters");
				}
			}
		} else $this->logger->addInfo(__FILE__.": ".__METHOD__.": token_file doesn't exist");
	
		if ($this->is_cli) {

			if (empty($access_code)) {
				$this->logger->addInfo(__FILE__.": ".__METHOD__.": no access code provided");
				if (class_exists('Oauth')) {
					if (empty($this->oauth_provider)) {
						$this->logger->addError(__FILE__.": ".__METHOD__.": missing class parameter: oauth_provider");
						$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
						return false;
					}
					$oauth = new Oauth($this->logger, $this->config['spark']);
					$this->logger->addWarning(__FILE__.": ".__METHOD__.": about to get access code via Oauth. this could take a few minutes.");
					if (empty($access_code = $oauth->get_access_code($this->oauth_provider))) {
		   	   	$this->logger->addError(__FILE__.": ".__METHOD__.": failed to get access code");
						$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			   	   return false;
					}
				} else {
					$this->logger->addError(__FILE__.": ".__METHOD__.": access_code is missing and oauth not included");
					$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			      return false;
				}
			}
		} else {
			$this->logger->addCritical(__FILE__.": ".__METHOD__.": forking to get bot tokens");
			$this->fork_get_bot_tokens();
			header('HTTP/1.1 503 Service Temporarily Unavailable');
			die();
		}
	
		if (!empty($access_code)) {
			if (!empty($access_code['token_type'] && strtolower($access_code['token_type']) == 'bearer')) {
				if (empty($access_code['access_token'] || empty($access_code['expires_in']))) {
					$this->logger->addError(__FILE__.": ".__METHOD__.": access code was a bearer token, but missing expires_in or access_token");
					$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
					return false;
				}
				$tokens = $access_code;
				$tokens['refresh_token'] = null;
				$tokens['refresh_token_expires_in'] = null;
			}
			if (
				!empty($tokens['access_token'])
				|| !empty($tokens = $this->get_integration_tokens($access_code['access_token']))) {
				if (empty($this->me = $this->validate_access_token($tokens['access_token']))) {
					$this->logger->addError(__FILE__.": ".__METHOD__.": couldn't get me");
					$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
					return false;
				}
				$this->set_me_mention_regex();
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return $tokens;
			} else {
				$this->logger->addError(__FILE__.": ".__METHOD__.": couldn't get tokens");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return false;
			}
		}
	
	}

	public function observe_start($observations, $help, $functions) {
		$observations = $this->check_for_observations($observations);
		$help = $this->check_for_help($help);
		return $this->observe('start', $observations, $help, $functions);
	}

	public function observe_stop($observations, $help, $functions) {
		$observations = $this->check_for_observations($observations);
		$help = $this->check_for_help($help);
		return $this->observe('stop', $observations, $help, $functions);
	}

	public function observe_botmessage($observations, $help, $functions) {
		$observations = $this->check_for_observations($observations);
		$help = $this->check_for_help($help);
		return $this->observe('botmessage', $observations, $help, $functions);
	}

	public function observe_newroom($observations, $help, $functions) {
		$observations = $this->check_for_observations($observations);
		$help = $this->check_for_help($help);
		return $this->observe('newroom', $observations, $help, $functions);
	}

	public function observe_ipc($observations, $help, $functions) {
		$observations = $this->check_for_observations($observations);
		$help = $this->check_for_help($help);
		return $this->observe('ipc', $observations, $help, $functions);
	}

	public function observe_files($observations, $help, $functions) {
		$observations = $this->check_for_observations($observations);
		$help = $this->check_for_help($help);
		return $this->observe('files', $observations, $help, $functions);
	}

	public function observe_url($observations, $help, $functions) {
		$observations = $this->check_for_observations($observations);
		$help = $this->check_for_help($help);
		return $this->observe('url', $observations, $help, $functions);
	}

	public function observe_hashtag($observations, $help, $functions) {
		$observations = $this->check_for_observations($observations);
		if (empty($observations)) $observations = ["[a-z0-9]"];
		$help = $this->check_for_help($help);
		return $this->observe('hashtag', $observations, $help, $functions);
	}

	public function observe_email($observations, $help, $functions) {
		$observations = $this->check_for_observations($observations);
		$help = $this->check_for_help($help);
		return $this->observe('email', $observations, $help, $functions);
	}

	public function observe_phone($observations, $help, $functions) {
		$observations = $this->check_for_observations($observations);
		$help = $this->check_for_help($help);
		return $this->observe('phone', $observations, $help, $functions);
	}

	public function observe_boton($observations, $help, $functions) {
		$observations = $this->check_for_observations($observations);
		$help = $this->check_for_help($help);
		return $this->observe('boton', $observations, $help, $functions);
	}

	public function observe_webhook($observations, $help, $functions) {
		$observations = $this->check_for_observations($observations, true);
		if (count($observations) != 2) {
			$this->logger->addCritical(__FILE__.": ".__METHOD__.": don't have resource or event for webhook observation: ".join('', $observation));
			die();
		}
		$observations = [ $observations[0]."_".$observations[1] ];
		$help = $this->check_for_help($help);
		return $this->observe('webhook', $observations, $help, $functions);
	}

	public function observe_at($observations, $help, $functions) {
		$observations = $this->check_for_observations($observations, true);
		if (preg_match('/^[0-9]+$/', $observation) == -1) {
			$this->logger->addCritical(__FILE__.": ".__METHOD__.": at observation isn't a number: $observation");
			$this->logger->addCritical(__FILE__.": ".__METHOD__.": can't add observe, exiting");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			die();
		}
		$help = $this->check_for_help($help);
		return $this->observe('at', $observations, $help, $functions);
	}

	public function observe_mqtt($observations, $help, $functions) {
		$observations = $this->check_for_observations($observations, true);
		$help = $this->check_for_help($help);
		return $this->observe('mqtt', $observations, $help, $functions);
	}

	public function observe_person($observations, $help, $functions) {
		$observations = $this->check_for_observations($observations, true);
		foreach ($observations as $observation) {
			if (!filter_var($observation, FILTER_VALIDATE_EMAIL)) {
		  	  	$this->logger->addCritical(__FILE__.": ".__METHOD__.": person observation contains invalid email: $observation");
  			  	$this->logger->addCritical(__FILE__.": ".__METHOD__.": can't add observe, exiting");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				die();
			}
		}
		$help = $this->check_for_help($help);
		return $this->observe('person', $observations, $help, $functions);
	}

	public function observe_search($observations, $help, $functions) {
		$observations = $this->check_for_observations($observations, true);
		$help = $this->check_for_help($help);
		return $this->observe('search', $observations, $help, $functions);
	}

	public function observe_sucommand($observations, $help, $functions) {
		$observations = $this->check_for_observations($observations, true);
		$observations = preg_replace("/\//", '\/', $observations);
		$help = $this->check_for_help($help, true);
		return $this->observe('sucommand', $observations, $help, $functions);
	}

	public function observe_admincommand($observations, $help, $functions) {
		$observations = $this->check_for_observations($observations, true);
		$observations = preg_replace("/\//", '\/', $observations);
		$help = $this->check_for_help($help, true);
		return $this->observe('admincommand', $observations, $help, $functions);
	}

	public function observe_modcommand($observations, $help, $functions) {
		$observations = $this->check_for_observations($observations, true);
		$observations = preg_replace("/\//", '\/', $observations);
		$help = $this->check_for_help($help, true);
		return $this->observe('modcommand', $observations, $help, $functions);
	}

	public function observe_command($observations, $help, $functions) {
		$observations = $this->check_for_observations($observations, true);
		$observations = preg_replace("/\//", '\/', $observations);
		$help = $this->check_for_help($help, true);
		return $this->observe('command', $observations, $help, $functions);
	}

	protected function check_for_help($help, $require = false) {

		$function_start = \function_start();

		if (empty($help) && !$require) {
			$this->logger->addWarning(__FILE__.": ".__METHOD__.": missing function parameter: help");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return [];
		}

		if (!is_array($help)) {
	  	  	$this->logger->addCritical(__FILE__.": ".__METHOD__.": invalid function parameter: help");
  		  	$this->logger->addCritical(__FILE__.": ".__METHOD__.": can't add observe, exiting");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			die();
		}
	
		if ($require) {

			if (empty($help[0])) {
  		   	$this->logger->addCritical(__FILE__.": ".__METHOD__.": missing function parameter: help label");
  		   	$this->logger->addCritical(__FILE__.": ".__METHOD__.": can't add observe, exiting");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				die();
			}
	
			if (empty($help[1])) {
  		   	$this->logger->addCritical(__FILE__.": ".__METHOD__.": missing function parameter: help description");
  		   	$this->logger->addCritical(__FILE__.": ".__METHOD__.": can't add observe, exiting");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				die();
			}

		} else {

			if (empty($help[0])) {
				$this->logger->addWarning(__FILE__.": ".__METHOD__.": missing function parameter: help label");
				$help = [];
			} else if (empty($help[1])) {
				$this->logger->addWarning(__FILE__.": ".__METHOD__.": missing function parameter: help description");
				$help = [];
			}

		}

		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $help;

	}

	protected function check_for_observations($observations, $require = false) {

		$function_start = \function_start();

		if (empty($observations) && !$require) {
			$this->logger->addWarning(__FILE__.": ".__METHOD__.": missing function parameter: observations");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return [];
		}

		if (!is_array($observations)) {
	  	  	$this->logger->addCritical(__FILE__.": ".__METHOD__.": invalid function parameter: observations");
  		  	$this->logger->addCritical(__FILE__.": ".__METHOD__.": can't add observe, exiting");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			die();
		}
	
		foreach ($observations as $key => $observation) {
			if (empty($observation)) unset($observations[$key]);
		}
	
		if (empty($observations)) {
			if ($require) {
	  		  	$this->logger->addCritical(__FILE__.": ".__METHOD__.": empty function parameter: observations");
  			  	$this->logger->addCritical(__FILE__.": ".__METHOD__.": can't add observe, exiting");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				die();
			} else {
				$this->logger->addWarning(__FILE__.": ".__METHOD__.": empty function parameter: observations");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return [];
			}
		}

		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $observations;

	}

	protected function observe($type, $observations, $help, $functions) {
		$function_start = \function_start();

		if (empty($functions)) $not_function = true;
		else if ($functions instanceof Closure) $functions = [ $functions ];
		else if (is_array($functions)) {
			foreach ($functions as $function) {
				if (!is_callable($function) && !($function instanceof Closure)) {
					$not_function = true;
					break;
				}
			}
		}
		if (!empty($not_function)) {
  	   	$this->logger->addCritical(__FILE__.": ".__METHOD__.": missing function parameter: functions");
  	   	$this->logger->addCritical(__FILE__.": ".__METHOD__.": no callable functions, can't add observe, exiting");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			die();
		}

		if (empty($observations)) $observations = ['enabled'];
		if (empty($help)) $help = ['',''];
		foreach ($observations as $key => $observation) {
			if ($key > 0) $help = ['',''];
			if (!isset($this->bot_triggers[$type][$observation])) {
				$this->bot_triggers[$type][$observation] = array(
					'callbacks' => $functions,
					'label' => $help[0],
					'description' => $help[1],
					);
			} else {
				$this->bot_triggers[$type][$observation]['label'] = $help[0];
				$this->bot_triggers[$type][$observation]['description'] = $help[1];
				foreach ($functions as $function) {
					if (!in_array($this->bot_triggers[$type][$observation]['callbacks'])) 
						$this->bot_triggers[$type][$observation]['callbacks'][] = $function;
				}
			}
		}

		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return true;

	}

	public function is_phonenumber($string) {
		$regexp = '/[\(\+]\d[\d\s\-\.\((\)]*\d{2}[\d\s\-\.\(\)]*\d(\s*x\s*\d+)?/i';
		if (preg_match($regexp, $string)) return true;
		else return false;
	}


	public function create_room($title, $participants, $moderators = null, $text = null, $files = null, $reuse_latest = false, $team_id = null) {

		$function_start = \function_start();

		if (!empty($reuse_latest)) {
			$room_details_tmp = [];
			foreach ($this->existing_rooms as $room) {
				if ($room['title'] === $title) {
					$room_details_tmp[$room['created']] = $room['id'];
				}
			}
			ksort($room_details_tmp);
			if (!empty($room_id = array_pop($room_details_tmp))) {
				$room_details = $this->existing_rooms[$room_id];
				$this->logger->addInfo(__FILE__.": ".__METHOD__.": have details for room: $title id: ".$room_details['id']);
			}
		}
	
		if (empty($room_details)) {
			if (empty($room_details = $this->rooms('POST', [ 'title' => $title ]))) {
				$this->logger->addError(__FILE__.": ".__METHOD__.": failed to create room: $title");
				$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
				return false;
			}
			$this->logger->addInfo(__FILE__.": ".__METHOD__.": created room: $title id: ".$room_details['id']);
		}

		$membership_details = $this->memberships('GET', [ 'roomId' => $room_details['id'], 'personEmail' => $this->me['emails'][0] ])['items'][0];
		if ($membership_details['personEmail'] != $this->me['emails'][0]) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": failed to get bot membership: room_id: ".$room_details['id']);
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
	
		if (!empty($room_details['isLocked']) && empty($membership_details['isModerator'])) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": room is locked and bot is not moderator");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}

		if (!empty($moderators)) {
			if (empty($membership_details['isModerator'])) {
				if (empty($membership_details = $this->memberships('PUT', [ 'membershipId' => $membership_details['id'], 'isModerator' => true ]))) {
					$this->logger->addError(__FILE__.": ".__METHOD__.": failed to make bot moderator: room_id: ".$room_details['id']);
					$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
					return false;
				}
				$this->logger->addInfo(__FILE__.": ".__METHOD__.": made bot moderator: room_id: ".$room_details['id']);
			} else 
				$this->logger->addInfo(__FILE__.": ".__METHOD__.": bot is already moderator: room_id: ".$room_details['id']);
		}
	
		if (empty($this->add_users($moderators, $room_details['id'], 'room', true)))
			$this->logger->addError(__FILE__.": ".__METHOD__.": adding moderators encountered errors");

		if (empty($this->add_users($participants, $room_details['id'])))
			$this->logger->addError(__FILE__.": ".__METHOD__.": adding participants encountered errors");

		if (!empty($text) || !empty($files)) {
			if (!empty($files)) $params = [ 'markdown' => $text, 'files' => $files, 'roomId' => $room_details['id'] ];
			else $params = [ 'markdown' => $text, 'roomId' => $room_details['id'] ];
			if (empty($this->messages('POST', $params))) {
				$this->logger->addError(__FILE__.": ".__METHOD__.": couldn't post welcome message");
			}
		}

		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $room_details;

	}

	public function add_users($users, $id, $type = 'room', $moderators = false) {

		$function_start = \function_start();

		$this->logger->addDebug(__FILE__.": ".__METHOD__.": users: ".json_encode($users)." id: $id type: $type mods: $moderators");

		if ($type != 'room') {
			$this->logger->addError(__FILE__.": ".__METHOD__.": nothing other than type room supported yet.");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		} else {
			$api_function = "memberships";
		}

		$return = true;

		foreach ($users as $user) {

			$this->logger->addDebug(__FILE__.": ".__METHOD__.": trying to add user: $user to $type: $id");

			if (filter_var($user, FILTER_VALIDATE_EMAIL)) $email = true;
			else $email = false;

			if ($email) $membership_details = $this->$api_function('GET', [ $type.'Id' => $id, 'personEmail' => $user ]);
			else $membership_details = $this->$api_function('GET', [ $type.'Id' => $id, 'personId' => $user ]);

			if (!empty($membership_details['items'][0])) $membership_details = $membership_details['items'][0];

			if (
				($email && (empty($membership_details['personEmail']) || $membership_details['personEmail'] != $user))
				|| (!$email && (empty($membership_details['personId']) || $membership_details['personId'] != $user))
				) {

				if (
					($email && empty($membership_details = $this->$api_function('POST', [ $type.'Id' => $id, 'personEmail' => $user ])))
					|| (!$email && empty($membership_details = $this->$api_function('POST', [ $type.'Id' => $id, 'personId' => $user ])))
					) {
					$this->logger->addError(__FILE__.": ".__METHOD__.": couldn't add user: $user $type: $id");
					$return = false;
				} else
					$this->logger->addInfo(__FILE__.": ".__METHOD__.": added user: $user $type: $id");

			} else
				$this->logger->addInfo(__FILE__.": ".__METHOD__.": already member: user: $user $type: $id");
	
			if ($moderators) {
				if (!$membership_details['isModerator']) {
					if (empty($membership_details = $this->$api_function('PUT', [ 'membershipId' => $membership_details['id'], 'isModerator' => true ]))) {
						$this->logger->addError(__FILE__.": ".__METHOD__.": couldn't make user moderator: user: $user $type: $id");
						$return = false;
					} else
						$this->logger->addInfo(__FILE__.": ".__METHOD__.": made user moderator: user: $user $type: $id");
				} else
					$this->logger->addInfo(__FILE__.": ".__METHOD__.": already moderator: user: $user $type: $id");
			}

		}

		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $return;

	}

	public function delete_all_webhooks($filters = []) {
		$function_start = \function_start();
		$existing_webhooks = $this->get_all_webhooks();
		if (!is_array($existing_webhooks)) {
			$this->logger->addError(__FILE__.": ".__METHOD__.": failed to get all webhooks");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return false;
		}
		if (count($existing_webhooks) == 0) {
			$this->logger->addWarning(__FILE__.": ".__METHOD__.": there are no webhooks");
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
			return [];
		}
		foreach ($existing_webhooks as $id => $webhook_details) {
			$this->logger->addDebug(__FILE__.": ".__METHOD__.": checking webhook for deletion: $id");
			if (
				(!empty($filters['name']) && !preg_match("/".str_replace('/', '\/', $filters['name'])."/", $webhook_details['name']))
				|| (!empty($filters['targetUrl']) && !preg_match("/".str_replace('/', '\/', $filters['targetUrl'])."/", $webhook_details['targetUrl']))
				|| (!empty($filters['resource']) && !preg_match("/".str_replace('/', '\/', $filters['resource'])."/", $webhook_details['resource']))
				|| (!empty($filters['event']) && !preg_match("/".str_replace('/', '\/', $filters['event'])."/", $webhook_details['event']))
				|| (!empty($filters['filter']) && !preg_match("/".str_replace('/', '\/', $filters['filter'])."/", $webhook_details['filter']))
				) continue;
			if (empty($this->webhooks('DELETE', ['webhookId' => $id], false)))
				$this->logger->addError(__FILE__.": ".__METHOD__.": failed to delete webhook: $id");
			else {
				$this->logger->addInfo(__FILE__.": ".__METHOD__.": deleted webhook: $id");
				unset($existing_webhooks[$id]);
			}
		}
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return $existing_webhooks;
	}

	protected function save_cache() {
		$function_start = \function_start();

		if (empty($this->cache_file)) return false;

		if (!$this->cache_updated) return false;

		$this->logger->addDebug(__FILE__.": ".__METHOD__.": cache has been updated, so need to save");

		if (empty(file_put_contents($this->cache_file, json_encode($this->cache, JSON_PRETTY_PRINT), LOCK_EX))) {
			$this->logger->addCritical(__FILE__.": ".__METHOD__.": !!! couldn't write to ".$this->cache_file.", bot can't maintain cache if restarted. !!!");
			return false;
		} else $this->cache_updated = false;

		$this->logger->addInfo(__FILE__.": ".__METHOD__.": saved cache");
		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
		return true;
	}

	protected function clean_cache() {
		$function_start = \function_start();

		if ($this->cache_expires_in == -1) return;

		foreach (array_keys($this->cache) as $api) {
			if ($api == 'memberships_room') {
				foreach ($this->cache[$api] as $room_id => $details) {
					if ($details['timestamp'] + $this->cache_expires_in <= time()) {
						unset($this->cache[$api][$room_id]);
						$this->cache_updated = true;
					}
				}
			} else { 
				foreach ($this->cache[$api] as $id => $details) {
					if ($details['timestamp'] + $this->cache_expires_in <= time()) {
						if ($api == 'memberships') {
							unset($this->cache['memberships_room_person'][$details['data']['roomId']][$details['data']['personId']]);
							unset($this->cache['memberships_room_person'][$details['data']['roomId']][$details['data']['personEmail']]);
						}
						unset($this->cache[$api][$id]);
						$this->cache_updated = true;
					}
				}
			}
		}

		$this->logger->addDebug(__FILE__.": ".__METHOD__.": ".\function_end($function_start));
	}

}

?>
