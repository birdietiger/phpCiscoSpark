<?php

class SymmetricEncryption {

	protected $logger;
	protected $enc_method = 'aes-256-cbc';
	protected $key_length = '32';
	protected $hmac_algo = 'sha256';
	protected $message_part_types = array(
		'ciphertext',
		'iv',
		'hmac',
		);

	public function __construct($logger = null) {
		$this->logger = $logger;
		if (!in_array($this->enc_method, openssl_get_cipher_methods())) {
			echo " enc_method not supported on this system ";
			return false;
		}
		if (!in_array($this->hmac_algo, hash_algos())) {
			echo " hmac not supported on this system ";
			return false;
		}
		$this->iv_length = openssl_cipher_iv_length($this->enc_method);
	}

	protected function validate_key($key) {
		if (empty($key)) {
			echo " log no key set";
			return false;
		}
		if (empty($key_binary = base64_decode($key, true))) {
			echo " invlaid or empty key";
			return false;
		}
		if (strlen($key_binary) != $this->key_length) {
			echo " wrong size ";
			return false;
		}
		return $key_binary;
	}

	public function encrypt($message, $key, $iv_string = null) {
		if (empty($key_binary = $this->validate_key($key))) return false;
		if (strlen($message) == 0) {
			echo " message is empty ";
			return false;
		}
		if (strlen($iv_string) == 0) {
			$isIvCryptoStrong = false;
			while (!$isIvCryptoStrong) {
				$iv_binary = openssl_random_pseudo_bytes($this->iv_length, $isIvCryptoStrong);
			}
		} else {
			echo "using user specified iv. not as secure, but could be ok depending on use.\n";
			while (strlen($iv_string) < $this->iv_length) {
				$iv_string .= '0';
			}
			$iv_binary = $iv_string;
		}
		$iv_base64 = base64_encode($iv_binary);
		if (($ciphertext_binary = openssl_encrypt($message, $this->enc_method, $key_binary, OPENSSL_RAW_DATA, $iv_binary)) === false) {
			echo " failed to encrypt message ";
			return false;
		}
		$ciphertext_base64 = base64_encode($ciphertext_binary);
		$message_data = $ciphertext_base64.':'.$iv_base64;
		if (($hmac = hash_hmac($this->hmac_algo, $message_data, $key_binary, true)) === false) {
			echo " failed to generate hmac ";
			return false;
		}
		$hmac_base64 = base64_encode($hmac);
		return $message_data.':'.$hmac_base64;
	}

	public function decrypt($message, $key) {
		if (empty($key_binary = $this->validate_key($key))) return false;
		if (count($message_parts = explode(':', $message)) != 3) {
			echo " invalid format"; 
			return false;
		}
		foreach ($message_parts as $message_part_key => $message_part_data) {
			$part = $this->message_part_types[$message_part_key];
			if (empty($message_part_data)) {
				echo " empty encoded $part ";
				return false;
			}
			${$part.'_base64'} = $message_part_data;
			if (empty(${$part.'_binary'} = base64_decode(${$part.'_base64'}, true))) {
				echo " invalid or empty $part ";
				return false;
			}
		}
		$message_data = $ciphertext_base64.':'.$iv_base64;
		if (($expected_hmac_binary = hash_hmac($this->hmac_algo, $message_data, $key_binary, true)) === false) {
			echo " failed to generate expected hmac ";
			return false;
		}
		if ($hmac_binary !== $expected_hmac_binary) {
			echo " failed hmac ";
			return false;
		}
		if (($message_decrypted = openssl_decrypt($ciphertext_binary, $this->enc_method, $key_binary, OPENSSL_RAW_DATA, $iv_binary)) === false) {
			echo " failed to decrypt ";
			return false;
		}
		return $message_decrypted;
	}
	
}

?>
