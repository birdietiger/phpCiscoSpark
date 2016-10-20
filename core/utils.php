<?php

function set_timezone() {
   $timezone = 'UTC';
   if (is_link('/etc/localtime')) {
      // Mac OS X (and older Linuxes)
      // /etc/localtime is a symlink to the
      // timezone in /usr/share/zoneinfo.
      $filename = readlink('/etc/localtime');
      if (strpos($filename, '/usr/share/zoneinfo/') === 0) {
        $timezone = substr($filename, 20);
      }
   } elseif (file_exists('/etc/timezone')) {
      // Ubuntu / Debian.
      $data = file_get_contents('/etc/timezone');
      if ($data) {
         $timezone = $data;
      }
   } elseif (file_exists('/etc/sysconfig/clock')) {
      // RHEL / CentOS
      $data = parse_ini_file('/etc/sysconfig/clock');
      if (!empty($data['ZONE'])) {
         $timezone = $data['ZONE'];
      }
   }
   date_default_timezone_set($timezone);
}

function array_diff_assoc_recursive($array1, $array2) {
	foreach($array1 as $key => $value){
		if(is_array($value)){
			if(!isset($array2[$key]))
				$difference[$key] = $value;
			elseif(!is_array($array2[$key]))
				$difference[$key] = $value;
			else {
				$new_diff = array_diff_assoc_recursive($value, $array2[$key]);
				if($new_diff != FALSE)
					$difference[$key] = $new_diff;
			}
		} elseif (
			(!isset($array2[$key]) || $array2[$key] != $value) && 
			!(isset($array2[$key]) && $array2[$key]===null && $value===null)
			)
			$difference[$key] = $value;
	}
	return !isset($difference) ? array() : $difference;
}

function function_start() {
	$function_start = array(
		microtime(TRUE),
		memory_get_usage(FALSE),
		memory_get_usage(TRUE)
		);
	return $function_start;
}

function function_end($function_start) {
	$function_end =
		"function completed: time=".
		round(microtime(TRUE) - $function_start[0], 4).
		" memory=".
		round((memory_get_usage(FALSE) - $function_start[1]) / 1024, 2).
		"/".
		round((memory_get_usage(TRUE) - $function_start[2]) / 1024, 2).
		"KB".
		"";
	return $function_end;
}

function array_to_ini($array) {

	$res = array();

	foreach ($array as $key => $val) {

		if (is_array($val)) {

			$res[] = "[$key]";
			foreach ($val as $skey => $sval) {
				if (is_array($sval)) {
					foreach ($sval as $tkey => $tval) $res[] = $skey."[] = ".(is_numeric($tval) ? $tval : '"'.$tval.'"');
				} else {
					if (is_bool($sval) && $sval === true) $res[] = "$skey = on";
					else if (is_bool($sval) && $sval === false) $res[] = "$skey = off";
					else if (is_string($sval) && strlen($sval) === 0) $res[] = "$skey = off";
					else if (is_string($sval) && $sval === '1') $res[] = "$skey = on";
					else if (is_numeric($sval)) $res[] = "$skey = $sval";
					else $res[] = "$skey = \"$sval\"";
				}
			}
			$res[] = "";

		} else {

			$res[] = "$key = ".(is_numeric($val) ? $val : '"'.$val.'"');

		}

	}

	return implode("\r\n", $res);

}

function save_new_file($file, $content) {

	if (is_dir($file)) return false;

	$filename = basename($file);
	$dir = dirname($file);

	if (file_exists($file)) {

		$i = -1;
		$current_files = scandir($dir);
		foreach ($current_files as $current_file) {               

			if (preg_match("/^$filename\.([1-9][0-9]*)$/", $current_file, $matches) == 0) continue;
			if (isset($matches[1]) && intval($matches[1]) > $i) $i = intval($matches[1]);

		}

		if (!rename($file, $file.'.'.($i+1))) return false;

	}

	return file_put_contents($file, $content, FILE_APPEND);

}

function collect_missing_passwords($config, $type = '') {

	$sparkbot_domains = ['sparkbot.io'];

	foreach ($config as $config_key => $config_value) {

		if (is_array($config_value)) {

			if (($config[$config_key] = collect_missing_passwords($config_value, $config_key)) === false) return false;

		} else if (
			preg_match('/^(.+)_password$/', $config_key, $matches) > 0
			&& strlen($config_value) == 0
			) {

			if (
				!empty($config[$matches[1].'_account'])
				&& preg_match('/@('.implode('|', $sparkbot_domains).')$/', $config[$matches[1].'_account']) > 0
				) continue;

			if (!empty($type)) $type .= ' ';
			if (strlen(($config[$config_key] = get_prompt('Please provide '.$type.$config_key.': ', 3, true))) == 0) return false;

		}

	}

	return $config;

}

function get_cores() {

	$data = file('/proc/stat');
	$cores = 0;

	foreach( $data as $line )
		if(preg_match('/^cpu[0-9]/', $line)) $cores++;

	return $cores;

}

function get_prompt($string = '', $tries = 3, $hide = false) {

	$os = strtoupper(substr(PHP_OS, 0, 3));

	for ($i = 1; $i<= $tries; $i++) {

		if ($hide) { if ($i > 1) echo "\n"; }
		echo $string;

		if ($os !== 'WIN') {
			if ($hide) system('stty -echo');
			$prompt = fgets(STDIN);
			if ($hide) system('stty echo');
		} else {
			//not supported yet
			//$prompt = `input.exe`;
		}

		$prompt = rtrim($prompt, PHP_EOL);

		if (strlen($prompt) > 0) break;

	} 

	if ($hide) echo PHP_EOL;

	return $prompt;

}
 
/* to compile for windows 

You can compile this code in Visual Studio Command Prompt:

cl /Os /Ox input.c
Or you can use MINGW‘s GCC like this:

gcc -Os -O3 -m32 -march=i586 input.c -o input.exe

#include <stdio.h>
#include <wtypes.h>
#include <wincon.h>
 
HANDLE hconin = INVALID_HANDLE_VALUE;
DWORD cmode;
 
void restore_term(void) {
	if (hconin == INVALID_HANDLE_VALUE)
		return;
 
	SetConsoleMode(hconin, cmode);
	CloseHandle(hconin);
	hconin = INVALID_HANDLE_VALUE;
}
 
int disable_echo(void) {
	hconin = CreateFile("CONIN$", GENERIC_READ | GENERIC_WRITE,
	FILE_SHARE_READ, NULL, OPEN_EXISTING,
	FILE_ATTRIBUTE_NORMAL, NULL);
	if (hconin == INVALID_HANDLE_VALUE)
		return -1;
 
	GetConsoleMode(hconin, &cmode);
	if (!SetConsoleMode(hconin, cmode & (~ENABLE_ECHO_INPUT))) {
		CloseHandle(hconin);
		hconin = INVALID_HANDLE_VALUE;
		return -1;
	}
 
	return 0;
}
 
int main(void) {
	char psw[100];
 
	disable_echo();
	fgets(psw, 100, stdin);
	restore_term();
	printf("%s", psw);
 
	return 0;
}
*/

?>
