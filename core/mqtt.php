<?php

namespace phpMQTT;

/*
 	phpMQTT
	A simple php class to connect/publish/subscribe to an MQTT broker

	MAJOR UPDATES by https://github.com/birdietiger
 
*/
/*
	Licence
	Copyright (c) 2010 Blue Rhinos Consulting | Andrew Milsted
	andrew@bluerhinos.co.uk | http://www.bluerhinos.co.uk
	Permission is hereby granted, free of charge, to any person obtaining a copy
	of this software and associated documentation files (the "Software"), to deal
	in the Software without restriction, including without limitation the rights
	to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
	copies of the Software, and to permit persons to whom the Software is
	furnished to do so, subject to the following conditions:
	The above copyright notice and this permission notice shall be included in
	all copies or substantial portions of the Software.
	THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
	IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
	FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
	AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
	LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
	OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
	THE SOFTWARE.
	
*/
/* phpMQTT */
class phpMQTT {
	private $socket; 			/* holds the socket	*/
	private $websocket_client; 			/* holds the socket	*/
	public $websocket = false; 			/* should use websocket	*/
	public $secure = false;	/* tls or not */
	public $no_cert_checks = false;	/* make sure server certs are valid */
	public $proxy; 			/* holds the socket proxy host	*/
	public $proxy_port = 80; 			/* holds the socket proxy port	*/
	private $msgid = 1;			/* counter for message id */
	public $keepalive = 30;		/* default keepalive timmer */
	private $ping_received;		/* host unix time, used to detect disconects */
	public $topics = array(); 	/* used to store currently subscribed topics */
	public $debug = false;		/* should output debug messages */
	public $address;			/* broker address */
	public $port;				/* broker port */
	public $clientid;			/* client id sent to brocker */
	public $will;				/* stores the will of the client */
	public $username;			/* stores username */
	public $password;			/* stores password */
	public $max_empty_loop_count = 3;	/* how many times to loop if nothing is found when not looping forever */
	private $ping_sent = 0;		/* when the last ping was sent in unixtime */
	public $ping_wait = 1;		/* how many secs to wait to send another ping */

	function __construct($address, $port, $clientid){
		$this->broker($address, $port, $clientid);
	}

	/* sets the broker details */
	function broker($address, $port, $clientid){
		$this->address = $address;
		$this->port = $port;
		$this->clientid = $clientid;		
	}

	function connect_auto($clean = true, $will = NULL, $username = NULL, $password = NULL){
		while($this->connect($clean, $will, $username, $password)==false){
			usleep(2000000);
		}
		return true;
	}

	/* connects to the broker 
		inputs: $clean: should the client send a clean session flag */
	function connect($clean = true, $will = NULL, $username = NULL, $password = NULL){

		$this->ping_received = null;
		if($will) $this->will = $will;
		if($username) $this->username = $username;
		if($password) $this->password = $password;
		
		if ($this->websocket) {
			// order is important
			require(__DIR__.'/websocket/Exception.php');   
			require(__DIR__.'/websocket/BadOpcodeException.php');   
			require(__DIR__.'/websocket/BadUriException.php');   
			require(__DIR__.'/websocket/ConnectionException.php');   
			require(__DIR__.'/websocket/Base.php');   
			require(__DIR__.'/websocket/Client.php');   
			if (!empty($this->proxy) && !empty($this->proxy_port)) {
				$opts = [
					'http' => [
						'proxy' => 'tcp://'.$this->proxy.':'.$this->proxy_port,
						]
					];
			} else $opts = [];
			$context = stream_context_create($opts);
			$headers = [
				'Sec-WebSocket-Protocol' => 'mqtt'
				];
			if ($this->secure) $scheme = 'wss';
			else $scheme = 'ws';
			$this->websocket_client = new \WebSocket\Client("$scheme://$this->address:$this->port", ['context' => $context, 'headers' => $headers, 'timeout' => 60]);
		} else {
			if (!empty($this->proxy)) {
				$socket_address = $this->proxy;
				$socket_port = $this->proxy_port;
			} else {
				$socket_address = $this->address;	
				$socket_port = $this->port;
			}
			$socket_scheme = "tcp://";
			$context_options = array(
				'ssl' => array(
					'peer_name' => $this->address,
					'verify_peer' => true,
					'verify_peer_name' => true,
					'allow_self_signed' => false,
					),
				);
			if ($this->no_cert_checks) {
				$context_options['ssl']['verify_peer'] = false;
				$context_options['ssl']['verify_peer_name'] = false;
				$context_options['ssl']['allow_self_signed'] = true;
			}
			$stream_context = stream_context_create($context_options);
			$this->socket = stream_socket_client($socket_scheme.$socket_address.':'.$socket_port, $errno, $errstr, 60, STREAM_CLIENT_CONNECT, $stream_context);
			if (!$this->socket ) {
			    if($this->debug) error_log("stream_socket_client() $errno, $errstr \n");
				return false;
			}
			if ($this->secure) {
				if (!stream_socket_enable_crypto($this->socket,true,STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
						if($this->debug) error_log("enable tls failed\n");
						return false;
				}
			}
			stream_set_timeout($this->socket, 5);
			stream_set_blocking($this->socket, 0);
		}
		$i = 0;
		$buffer = "";
		$buffer .= chr(0x00); $i++;
		$buffer .= chr(0x06); $i++;
		$buffer .= chr(0x4d); $i++;
		$buffer .= chr(0x51); $i++;
		$buffer .= chr(0x49); $i++;
		$buffer .= chr(0x73); $i++;
		$buffer .= chr(0x64); $i++;
		$buffer .= chr(0x70); $i++;
		$buffer .= chr(0x03); $i++;
		//No Will
		$var = 0;
		if($clean) $var+=2;
		//Add will info to header
		if($this->will != NULL){
			$var += 4; // Set will flag
			$var += ($this->will['qos'] << 3); //Set will qos
			if($this->will['retain'])	$var += 32; //Set will retain
		}
		if($this->username != NULL) $var += 128;	//Add username to header
		if($this->password != NULL) $var += 64;	//Add password to header
		$buffer .= chr($var); $i++;
		//Keep alive
		$buffer .= chr($this->keepalive >> 8); $i++;
		$buffer .= chr($this->keepalive & 0xff); $i++;
		$buffer .= $this->strwritestring($this->clientid,$i);
		//Adding will to payload
		if($this->will != NULL){
			$buffer .= $this->strwritestring($this->will['topic'],$i);  
			$buffer .= $this->strwritestring($this->will['content'],$i);
		}
		if($this->username) $buffer .= $this->strwritestring($this->username,$i);
		if($this->password) $buffer .= $this->strwritestring($this->password,$i);
		$head = chr(0x10);
		$head .= $this->setmsglength($i);
		if ($this->websocket) {
			$this->websocket_client->send($head.$buffer);
			$data = $this->websocket_client->receive();
			$stream = fopen('php://memory','r+');
			fwrite($stream, $data);
			rewind($stream);
		 	$string = $this->read(4, false, $stream);
			fclose($stream);
		} else {
			//fwrite($this->socket, $head);
			fwrite($this->socket,  $head.$buffer);
		 	$string = $this->read(4, false, $this->socket);
		}
		if(ord($string{0})>>4 == 2 && $string{3} == chr(0)){
			if($this->debug) echo "Connected to Broker\n"; 
		}else{	
			error_log(sprintf("Connection failed! (Error: 0x%02x 0x%02x)\n", 
			                        ord($string{0}),ord($string{3})));
			return false;
		}
		return true;
	}
	/* read: reads in so many bytes */
	function read($int = 8192, $nb = false, $stream = null){
		//	print_r(socket_get_status($this->socket));
		
		$string="";
		$togo = $int;
		
		if($nb){
			//return fread($this->socket, $togo);
			return fread($stream, $togo);
		}
			
		while (!feof($stream) && $togo>0) {
			//$fread = fread($this->socket, $togo);
			$fread = fread($stream, $togo);
			$string .= $fread;
			$togo = $int - strlen($string);
		}
		
	
		
		
			return $string;
	}

	/* unsubscribe: unsubscribe from topics */
	function unsubscribe($topics, $qos = 0){
		$i = 0;
		$buffer = "";
		$id = $this->msgid;
		$buffer .= chr($id >> 8);  $i++;
		$buffer .= chr($id % 256);  $i++;
		foreach($topics as $key => $topic){
			$buffer .= $this->strwritestring($key,$i);
			$buffer .= chr($topic["qos"]);  $i++;
			unset($this->topics[$key]);
		}
		$cmd = 0xA2;
		//$qos
		$cmd +=	($qos << 1);
		$head = chr($cmd);
		$head .= $this->setmsglength($i);

		if ($this->websocket) {
			$this->websocket_client->send($head.$buffer);
			$data = $this->websocket_client->receive();
			$stream = fopen('php://memory','r+');
			fwrite($stream, $data);
			rewind($stream);
			$string = $this->read(2, false, $stream);
			$bytes = ord(substr($string,1,1));
			$string = $this->read($bytes, false, $stream);
			fclose($stream);
		} else {
			//fwrite($this->socket, $head, 2);
			fwrite($this->socket, $head.$buffer, $i);
			$string = $this->read(2, false, $this->socket);
			$bytes = ord(substr($string,1,1));
			$string = $this->read($bytes, false, $this->socket);
		}
		return true;
	}
	/* subscribe: subscribes to topics */
	function subscribe($topics, $qos = 0){
		$i = 0;
		$buffer = "";
		$id = $this->msgid;
		$buffer .= chr($id >> 8);  $i++;
		$buffer .= chr($id % 256);  $i++;
		foreach($topics as $key => $topic){
			$buffer .= $this->strwritestring($key,$i);
			$buffer .= chr($topic["qos"]);  $i++;
			if (empty($topic['params'])) $topic['params'] = null;
			$this->topics[$key] = $topic; 
		}
		$cmd = 0x80;
		//$qos
		$cmd +=	($qos << 1);
		$head = chr($cmd);
		$head .= $this->setmsglength($i);

		if ($this->websocket) {
			$this->websocket_client->send($head.$buffer);
			$data = $this->websocket_client->receive();
			$stream = fopen('php://memory','r+');
			fwrite($stream, $data);
			rewind($stream);
			$string = $this->read(2, false, $stream);
			$bytes = ord(substr($string,1,1));
			$string = $this->read($bytes, false, $stream);
			fclose($stream);
		} else {
			//fwrite($this->socket, $head, 2);
			fwrite($this->socket, $head.$buffer, $i);
			$string = $this->read(2, false, $this->socket);
			$bytes = ord(substr($string,1,1));
			$string = $this->read($bytes, false, $this->socket);
		}
		return true;
	}
	/* ping: sends a keep alive ping */
	function ping(){
			if (time() - $this->ping_sent < $this->ping_wait) return;
			$head = " ";
			$head = chr(0xc0);		
			$head .= chr(0x00);
			if ($this->websocket) $this->websocket_client->send($head);
			else fwrite($this->socket, $head, 2);
			$this->ping_sent = time();
			if($this->debug) echo "ping sent\n";
	}
	/* disconnect: sends a proper disconect cmd */
	function disconnect(){
			$head = " ";
			$head{0} = chr(0xe0);		
			$head{1} = chr(0x00);
			if ($this->websocket) $this->websocket_client->send($head);
			else fwrite($this->socket, $head, 2);
	}
	/* close: sends a proper disconect, then closes the socket */
	function close(){
	 	$this->disconnect();
		if ($this->websocket) unset($this->websocket_client);
		else fclose($this->socket);
	}
	/* publish: publishes $content on a $topic */
	function publish($topic, $content, $qos = 0, $retain = 0){
		$i = 0;
		$buffer = "";
		$buffer .= $this->strwritestring($topic,$i);
		if($qos){
			$id = $this->msgid++;
			$buffer .= chr($id >> 8);  $i++;
		 	$buffer .= chr($id % 256);  $i++;
		}
		$buffer .= $content;
		$i+=strlen($content);
		$cmd = 0x30;
		if($qos) $cmd += $qos << 1;
		if($retain) $cmd += 1;
		$head = chr($cmd);		
		$head .= $this->setmsglength($i);
		$response_cmd = 0;
		if ($this->websocket) {
			$this->websocket_client->send($head.$buffer);
			if (!empty($qos)) {
				$data = $this->websocket_client->receive();
				$stream = fopen('php://memory','r+');
				fwrite($stream, $data);
				rewind($stream);
				$byte = $this->read(1, true, $stream);
				$response_cmd = (int)(ord($byte)/16);
				fclose($stream);
			}
		} else {
			fwrite($this->socket, $head.$buffer, $i);
			if (!empty($qos)) {
				stream_set_blocking($this->socket, 1);
				$byte = $this->read(1, true, $this->socket);
				$response_cmd = (int)(ord($byte)/16);
			}
		}
		if (!empty($qos)) {
			if ($response_cmd == 4) return true;
			else return false;
		}
		return true;
	}
	/* message: processes a recieved topic */
	function message($msg){
		 	$tlen = (ord($msg{0})<<8) + ord($msg{1});
			$topic = substr($msg,2,$tlen);
			$msg = substr($msg,($tlen+2));
			$found = 0;
			foreach($this->topics as $key=>$top){
				if( preg_match("/^".str_replace("#",".*",
						str_replace("+","[^\/]*",
							str_replace("/","\/",
								str_replace("$",'\$',
									$key))))."$/",$topic) ){
					if(is_callable($top['function'])){
						$top['function']($topic,$msg,$top['params']);
						$found = 1;
					} else
						if($this->debug) echo "topic callback function is not callable\n";
				}
			}
			if($this->debug && !$found) echo "msg recieved but no match in subscriptions\n";
	}
	/* proc: the processing loop for an "allways on" client 
		set true when you are doing other stuff in the loop good for watching something else at the same time */	
	function proc( $loop = true){
		$loop_count = 1;
		while ($loop == true || $loop_count <= $this->max_empty_loop_count) {
			$sockets = array($this->socket);
			$w = $e = NULL;
			$cmd = 0;
			
				//$byte = fgetc($this->socket);
			if(!$this->websocket && feof($this->socket)){
				if($this->debug) echo "eof receive going to reconnect for good measure\n";
				fclose($this->socket);
				$this->connect_auto(false);
				if(count($this->topics)) {
					if ($this->websocket) {
						foreach ($this->topics as $topic_key => $topic) $this->subscribe(array($topic_key => $topic));
					} else $this->subscribe($this->topics);	
				}
			}
			
			if ($this->websocket) {
				/*
				$info = @ws_get_frame_info($this->socket);
				$data = @ws_read_frame($info);
				*/
				socket_set_blocking($this->websocket_client->socket, 0);
				$data = $this->websocket_client->receive();
				socket_set_blocking($this->websocket_client->socket, 1);
				$byte = '';
				$stream = fopen('php://memory','r+');
				if (!empty($data)) {
					fwrite($stream, $data);
					rewind($stream);
					$byte = $this->read(1, true, $stream);
				}
			} else {
				$byte = $this->read(1, true, $this->socket);
			}

			if (!strlen($byte)) {

				if($loop == true || $loop_count <= $this->max_empty_loop_count){
					$loop_count++;
					usleep(100000);
				}
			 
			} else { 
			
				$cmd = (int)(ord($byte)/16);
				if($this->debug) echo "Recevid: $cmd\n";
				$multiplier = 1; 
				$value = 0;
				do{
					if ($this->websocket) $digit = ord($this->read(1, false, $stream));
					else $digit = ord($this->read(1, false, $this->socket));

					$value += ($digit & 127) * $multiplier; 
					$multiplier *= 128;
					}while (($digit & 128) != 0);
				if($this->debug) echo "Fetching: $value\n";
				
				if($value)
					if ($this->websocket) $string = $this->read($value,"fetch",$stream);
               else $string = $this->read($value,"fetch", $this->socket);

				if($cmd){
					switch($cmd){
						case 3:
							// PUBLISH
							$this->message($string);
							break;
						case 4:
							// PUBACK
							break;
						case 13:
							// PINGRESP
							$this->ping_received = time();
							$this->ping_sent = 0;
							break;
					}
				}
			}
			if ($this->websocket) {
				if (!empty($stream)) fclose($stream);
			}
			if (empty($this->ping_received)) $this->ping_received = time();
			if(time() - $this->ping_received >= $this->keepalive){
				$this->ping();	
			}
			
			if((time() - $this->ping_received) >= ($this->keepalive * 2)){
				if($this->debug) echo "not seen a ping response in a while, disconnecting\n";
				if ($this->websocket) unset($this->websocket_client);
				else fclose($this->socket);
				$this->connect_auto(false);
				if(count($this->topics)) {
					if ($this->websocket) {
						foreach ($this->topics as $topic_key => $topic) $this->subscribe(array($topic_key => $topic));
					} else $this->subscribe($this->topics);	
				}
			}

		}
		return 1;
	}
	/* getmsglength: */
	function getmsglength(&$msg, &$i){
		$multiplier = 1; 
		$value = 0 ;
		do{
		  $digit = ord($msg{$i});
		  $value += ($digit & 127) * $multiplier; 
		  $multiplier *= 128;
		  $i++;
		}while (($digit & 128) != 0);
		return $value;
	}
	/* setmsglength: */
	function setmsglength($len){
		$string = "";
		do{
		  $digit = $len % 128;
		  $len = $len >> 7;
		  // if there are more digits to encode, set the top bit of this digit
		  if ( $len > 0 )
		    $digit = ($digit | 0x80);
		  $string .= chr($digit);
		}while ( $len > 0 );
		return $string;
	}
	/* strwritestring: writes a string to a buffer */
	function strwritestring($str, &$i){
		$ret = " ";
		$len = strlen($str);
		$msb = $len >> 8;
		$lsb = $len % 256;
		$ret = chr($msb);
		$ret .= chr($lsb);
		$ret .= $str;
		$i += ($len+2);
		return $ret;
	}
	function printstr($string){
		$strlen = strlen($string);
			for($j=0;$j<$strlen;$j++){
				$num = ord($string{$j});
				if($num > 31) 
					$chr = $string{$j}; else $chr = " ";
				printf("%4d: %08b : 0x%02x : %s \n",$j,$num,$num,$chr);
			}
	}
}
?>
