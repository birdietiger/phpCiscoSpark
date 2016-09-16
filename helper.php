<?php

// get application file data
$included_files = get_included_files();
$app_file = $included_files[0];
$app_dir = dirname($app_file);
$app_filename = basename($app_file);
$app_name = basename($app_file, '.php');
if (empty($config_dir))
	$app_conf_dir = "$app_dir/config/";
else
	$app_conf_dir = $config_dir;
if (empty($config_file))
	$app_conf_file = "$app_conf_dir/$app_name.conf";
else
	$app_conf_file = $config_file;

// make sure phpCiscoSpark has been installed.
if (!is_file(__DIR__.'/install.lock')) die("EMERGENCY: You need to install phpCiscoSpark. See '".__DIR__."/README.md'\n");

//require necessary files to enable all features
require_once __DIR__ . '/core/logging.php';
require_once __DIR__ . '/core/storage.php';
require_once __DIR__ . '/core/utils.php';
require_once __DIR__ . '/core/nlp.php';
require_once __DIR__ . '/core/curl.php';
require_once __DIR__ . '/core/oauth.php';
require_once __DIR__ . '/core/spark.php';
require_once __DIR__ . '/core/extensions.php';
require_once __DIR__ . '/core/encryption.php';

// load the config
if (is_file($app_conf_file)) $config = $orig_config = parse_ini_file($app_conf_file, true);
else die("EMERGENCY: Missing the configuration file: $app_conf_file\nINFO: See '".__DIR__."/README.md'\n");

// setup logging with config
$log_config = (!empty($config['log'])) ? $config['log'] : null;
$logger = new \BasicLogger($log_config);

// collect missing passwords, which could be excluded from the config file for security purposes
if (($config = collect_missing_passwords($orig_config)) === false) {
	$logger->addEmergency(__FILE__.' '.__METHOD__.': Could not collect all the missing passwords.');
	die();
} else if (serialize($config) != serialize($orig_config)) {
	if (get_prompt('Do you want to save these passwords in the config file? [Y/n] ') === 'Y') {
		if (save_new_file($app_conf_file, array_to_ini($config)) === false)
			$logger->addCritical(__FILE__.' '.__METHOD__.": Couldn't save new configuration file. Passwords will need to be provided next time.");
	} else $logger->addCritical(__FILE__.' '.__METHOD__.": You will need to provide those passwords each time you run this script.");
}

// set location of config file as part of config
$config['config_file'] = $app_conf_file;

// setup object to store perm and temp data
if (!empty($config['storage'])) 
	$storage = new \Storage($logger, $config['storage']);
else
	$storage = new StdClass();

// load extensions
if (!empty($config['extensions']))
	$extensions = new \Extensions($logger, $app_conf_dir, $config['extensions'], $storage);
else
	$extensions = new StdClass();

// setup multithread if supported 
if (class_exists('Thread')) require_once __DIR__ . '/core/spark.workers.php';

// setup spark app
if (!empty($config['broker']['class']) && !empty($config['broker']['host'])) {

	require_once __DIR__ . '/core/mqtt.php';
	require_once __DIR__ . '/core/broker.php';

	// setup message broker to handle mqtt and webhooks
	$broker = new \MessageBroker($logger, $config);

	// setup spark with broker
	$spark = new \Spark($logger, $config, $extensions, $storage, $broker);

} else {

	// setup spark without broker
	$spark = new \Spark($logger, $config, $extensions, $storage);

}

?>
