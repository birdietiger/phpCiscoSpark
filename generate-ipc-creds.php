<?php

$enc_method = 'aes-256-cbc';
$key_length = '32';

$seed = rand(120, 1800);

$isKeyCryptoStrong = false;
while (!$isKeyCryptoStrong) {
	$key = openssl_random_pseudo_bytes($key_length, $isKeyCryptoStrong);
}
$key = rtrim(base64_encode($key), '=');

echo "\n";
echo "ipc_channel_psk = $key\n";
echo "ipc_channel_seed = $seed\n";
echo "\n";
echo "Copy and paste the proceeding lines starting with ipc_channel_*\n";
echo "into the [spark] section in the bot config files for all bots\n";
echo "in your bot cluster. These settings *must* match in all bots in\n";
echo "the cluster for inter-bot communication to work.\n";
echo "\n";
echo "These values are randomly generated everytime this script runs.\n";
echo "Rerunning this script does not impact any existing configuration\n";
echo "or bot operation.\n";
echo "\n";

?>
