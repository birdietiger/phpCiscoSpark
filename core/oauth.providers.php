<?php

$providers = array(

	'box' => array(
		'response_type' => '%response_type%',
		'response_token_type' => '%response_token_type%',
		'steps' => array(
			0 => array(
				'url' => '',
				'post_data' => array(
					),
				'find_form_name' => 'login_form',
				'ignore_input' => array(
					),
				),
			1 => array(
				'url' => '',
				'post_data' => array(
					'login' => '%machine_account%',
					'password' => '%machine_password%',
					),
				'find_form_name' => 'consent_form',
				'ignore_input' => array(
					'consent_reject',
					),
				),
			2 => array(
				'url' => '',
				'post_data' => array(
					'consent_accept' => 'Grant+access+to+Box',
					),
				'find_form_name' => '',
				'ignore_input' => array(
					),
				),
			),
		),
	
	'webex' => array(
	
		'response_type' => '%response_type%',
		'response_token_type' => '%response_token_type%',
		'steps' => array(
	
			0 => array(
				'url' => '',
				'post_data' => array(
					),
				'find_form_name' => 'GlobalEmailLookup',
				'ignore_input' => array(
					),
				),
		
			1 => array(
				'url' => '',
				'post_data' => array(
					'email' => '%machine_account%',
					),
				'find_form_name' => 'Login',
				'ignore_input' => array(
					),
				),
		
			2 => array(
				'url' => '',
				'post_data' => array(
					'IDToken0' => '',
					'IDToken1' => '%machine_account%',
					'IDToken2' => '%machine_password%',
					'redirect_uri' => '%redirect_uri%',
					'IDButton' => 'Sign+In',
					),
				'find_form_name' => '',
				'ignore_input' => array(
					),
				),
		
			3 => array(
				'url' => '',
				'post_data' => array(
					'decision' => 'accept'
					),
				'find_form_name' => '',
				'ignore_input' => array(
					),
				),
		
			),
	
		),
	);

?>
