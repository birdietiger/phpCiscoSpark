<?php

// need to get max number of results returned
// need to check min, max, allowed characs

$spark_apis_delete_http_codes = array(
	204 => 'Item deleted.',
	400 => 'The request was invalid or cannot be otherwise served. An accompanying error message will explain further.',
	401 => 'Authentication credentials were missing or incorrect.',
	403 => 'The request is understood, but it has been refused or access is not allowed.',
	404 => 'The URI requested is invalid or the resource requested, such as a user, does not exist. Also returned when the requested format is not supported by the requested method.',
	409 => 'The request could not be processed because it conflicts with some established rule of the system. For example, a person may not be added to a room more than once.',
	500 => 'Something went wrong on the server.',
	503 => 'Server is overloaded with requests. Try again later.',
	);

$spark_apis_get_put_post_http_codes = array(
	200 => 'OK',
	400 => 'The request was invalid or cannot be otherwise served. An accompanying error message will explain further.',
	401 => 'Authentication credentials were missing or incorrect.',
	403 => 'The request is understood, but it has been refused or access is not allowed.',
	404 => 'The URI requested is invalid or the resource requested, such as a user, does not exist. Also returned when the requested format is not supported by the requested method.',
	409 => 'The request could not be processed because it conflicts with some established rule of the system. For example, a person may not be added to a room more than once.',
	500 => 'Something went wrong on the server.',
	503 => 'Server is overloaded with requests. Try again later.',
	);

$spark_endpoints = array(

	'1' => array(
		'people' => array(
			'/' => array(
				'GET' => array(
					'description' => 'List people in your organization.',
					'headers' => array(
						'Content-type' => 'application/json',
						),
					'params' => array(
						'email' => array(
							'type' => 'email',
							),
						'displayName' => array(
							'type' => 'string',
							'min' => 3,
							),
						'max' => array(
							'type' => 'integer',
							'min' => 1,
							'max' => 100000,
							'default' => 100,
							),
						),
					'required' => array(
						'and' => array(
							),
						'or' => array(
							'email',
							'displayName',
							),
						),
					'response' => $spark_apis_get_put_post_http_codes,
					),
				),
		
			'/{personId}' => array(
				'GET' => array(
					'description' => 'Shows details for a person, by ID.',
					'headers' => array(
						'Content-type' => 'application/json',
						),
					'params' => array(
						'personId' => array(
							'type' => 'id',
							),
						),
					'required' => array(
						'and' => array(
							'personId',
							),
						'or' => array(
							),
						),
					'response' => $spark_apis_get_put_post_http_codes,
					),
				),
		
			'/me' => array(
				'GET' => array(
					'description' => 'Show the profile for the authenticated user.',
					'headers' => array(
						'Content-type' => 'application/json',
						),
					'params' => array(
						),
					'required' => array(
						'and' => array(
							),
						'or' => array(
							),
						),
					'response' => $spark_apis_get_put_post_http_codes,
					),
				),
			),

		'rooms' => array(
			'/' => array(
				'GET' => array(
					'description' => 'List rooms.',
					'headers' => array(
						'Content-type' => 'application/json',
						),
					'params' => array(
						//'showSipAddress' => array(
						//	'type' => 'boolean',
						//	),
						'teamId' => array(
							'type' => 'id',
							),
						'type' => array(
							'type' => 'string',
							),
						'max' => array(
							'type' => 'integer',
							'min' => 1,
							'max' => 100000,
							),
						),
					'required' => array(
						'and' => array(
							),
						'or' => array(
							),
						),
					'response' => $spark_apis_get_put_post_http_codes,
					),
				'POST' => array(
					'description' => 'Creates a room and adds authenticated user as member.',
					'headers' => array(
						'Content-type' => 'application/json',
						),
					'params' => array(
						'teamId' => array(
							'type' => 'id',
							),
						'title' => array(
							'type' => 'string',
							),
						),
					'required' => array(
						'and' => array(
							'title',
							),
						'or' => array(
							),
						),
					'response' => $spark_apis_get_put_post_http_codes,
					),
				),
		
			'/{roomId}' => array(
				'GET' => array(
					'description' => 'Shows details for a room, by ID.',
					'headers' => array(
						'Content-type' => 'application/json',
						),
					'params' => array(
						'roomId' => array(
							'type' => 'id',
							),
						//'showSipAddress' => array(
						//	'type' => 'boolean',
						//	),
						),
					'required' => array(
						'and' => array(
							'roomId',
							),
						'or' => array(
							),
						),
					'response' => $spark_apis_get_put_post_http_codes,
					),
				'PUT' => array(
					'description' => 'Updates details for a room, by ID.',
					'headers' => array(
						'Content-type' => 'application/json',
						),
					'params' => array(
						'title' => array(
							'type' => 'string',
							),
						),
					'required' => array(
						'and' => array(
							'title',
							),
						'or' => array(
							),
						),
					'response' => $spark_apis_get_put_post_http_codes,
					),
				'DELETE' => array(
					'description' => 'Deletes a room, by ID.',
					'headers' => array(
						'Content-type' => 'application/json',
						),
					'params' => array(
						'roomId' => array(
							'type' => 'id',
							),
						),
					'required' => array(
						'and' => array(
							'roomId',
							),
						'or' => array(
							),
						),
					'response' => $spark_apis_delete_http_codes,
					),
				),
			),
		
		'memberships' => array(
			'/' => array(
				'GET' => array(
					'description' => 'Lists all room memberships. By default, lists memberships for rooms to which the authenticated user belongs.',
					'headers' => array(
						'Content-type' => 'application/json',
						),
					'params' => array(
						'roomId' => array(
							'type' => 'id',
							),
						'personId' => array(
							'type' => 'id',
							),
						'personEmail' => array(
							'type' => 'email',
							),
						'max' => array(
							'type' => 'integer',
							'min' => 1,
							'max' => 1000,
							'default' => 100,
							),
						),
					'required' => array(
						'and' => array(
							),
						'or' => array(
							),
						),
					'response' => $spark_apis_get_put_post_http_codes,
					),
				'POST' => array(
					'description' => 'Add someone to a room by Person ID or email address; optionally making them a moderator.',
					'headers' => array(
						'Content-type' => 'application/json',
						),
					'params' => array(
						'roomId' => array(
							'type' => 'id',
							),
						'personId' => array(
							'type' => 'id',
							),
						'personEmail' => array(
							'type' => 'email',
							),
						'isModerator' => array(
							'type' => 'boolean',
							),
						),
					'required' => array(
						'and' => array(
							'roomId',
							),
						'or' => array(
							'personId',
							'personEmail',
							),
						),
					'response' => $spark_apis_get_put_post_http_codes,
					),
				),
		
			'/{membershipId}' => array(
				'GET' => array(
					'description' => 'Get details for a membership by ID.',
					'headers' => array(
						'Content-type' => 'application/json',
						),
					'params' => array(
						'membershipId' => array(
							'type' => 'id',
							),
						),
					'required' => array(
						'and' => array(
							'membershipId',
							),
						'or' => array(
							),
						),
					'response' => $spark_apis_get_put_post_http_codes,
					),
				'PUT' => array(
					'description' => 'Updates properties for a membership by ID.',
					'headers' => array(
						'Content-type' => 'application/json',
						),
					'params' => array(
						'membershipId' => array(
							'type' => 'id',
							),
						'isModerator' => array(
							'type' => 'boolean',
							),
						),
					'required' => array(
						'and' => array(
							'isModerator',
							'membershipId',
							),
						'or' => array(
							),
						),
					'response' => $spark_apis_get_put_post_http_codes,
					),
				'DELETE' => array(
					'description' => 'Deletes a membership by ID.',
					'headers' => array(
						'Content-type' => 'application/json',
						),
					'params' => array(
						'membershipId' => array(
							'type' => 'id',
							),
						),
					'required' => array(
						'and' => array(
							'membershipId',
							),
						'or' => array(
							),
						),
					'response' => $spark_apis_delete_http_codes,
					),
				),
			),
		
		'messages' => array(
			'/' => array(
				'GET' => array(
					'description' => 'Lists all messages in a room. If present, includes the associated media content attachment for each message.',
					'headers' => array(
						'Content-type' => 'application/json',
						),
					'params' => array(
						'roomId' => array(
							'type' => 'id',
							),
						'after' => array(
							'type' => 'iso8601',
							),
						'before' => array(
							'type' => 'iso8601',
							),
						'beforeMessage' => array(
							'type' => 'id',
							),
						'max' => array(
							'type' => 'integer',
							'min' => 1,
							'max' => 100000,
							'default' => 100,
							),
						),
					'required' => array(
						'and' => array(
							'roomId',
							),
						'or' => array(
							),
						),
					'response' => $spark_apis_get_put_post_http_codes,
					),
				'POST' => array(
					'description' => 'Posts a plain text message, and optionally, a media content attachment, to a room.',
					'headers' => array(
						'Content-type' => 'application/json',
						),
					'params' => array(
						'roomId' => array(
							'type' => 'id',
							),
						'text' => array(
							'type' => 'string',
							'max' => 7439,
							),
						'markdown' => array(
							'type' => 'string',
							'max' => 7439,
							),
						'files' => array(
							'type' => 'url',
							),
						'toPersonId' => array(
							'type' => 'id',
							),
						'toPersonEmail' => array(
							'type' => 'email',
							),
						),
					'required' => array(
						'and' => array(
							),
						'or' => array(
							'roomId',
							'toPersonEmail',
							'toPersonId',
							),
						),
					'response' => $spark_apis_get_put_post_http_codes,
					),
				),
		
			'/{messageId}' => array(
				'GET' => array(
					'description' => 'Shows details for a message, by message ID.',
					'headers' => array(
						'Content-type' => 'application/json',
						),
					'params' => array(
						'messageId' => array(
							'type' => 'id',
							),
						),
					'required' => array(
						'and' => array(
							'messageId',
							),
						'or' => array(
							),
						),
					'response' => $spark_apis_get_put_post_http_codes,
					),
				'DELETE' => array(
					'description' => 'Deletes a message, by message ID.',
					'headers' => array(
						'Content-type' => 'application/json',
						),
					'params' => array(
						'messageId' => array(
							'type' => 'id',
							),
						),
					'required' => array(
						'and' => array(
							'messageId',
							),
						'or' => array(
							),
						),
					'response' => $spark_apis_delete_http_codes,
					),
				),
			),
		
		'webhooks' => array(
			'/' => array(
				'GET' => array(
					'description' => 'List all webhooks.',
					'headers' => array(
						'Content-type' => 'application/json',
						),
					'params' => array(
						'max' => array(
							'type' => 'integer',
							'min' => 1,
							'max' => 100000,
							),
						),
					'required' => array(
						'and' => array(
							),
						'or' => array(
							),
						),
					'response' => $spark_apis_get_put_post_http_codes,
					),
				'POST' => array(
					'description' => 'Posts a webhook.',
					'headers' => array(
						'Content-type' => 'application/json',
						),
					'params' => array(
						'name' => array(
							'type' => 'string',
							),
						'targetUrl' => array(
							'type' => 'url',
							),
						'resource' => array(
							'type' => 'string',
							),
						'event' => array(
							'type' => 'string',
							),
						'filter' => array(
							'type' => 'string',
							),
						'secret' => array(
							'type' => 'string',
							),
						),
					'required' => array(
						'and' => array(
							'name',
							'targetUrl',
							'resource',
							'event',
							),
						'or' => array(
							),
						),
					'response' => $spark_apis_get_put_post_http_codes,
					),
				),
		
			'/{webhookId}' => array(
				'GET' => array(
					'description' => 'Shows details for a webhook, by ID.',
					'headers' => array(
						'Content-type' => 'application/json',
						),
					'params' => array(
						'webhookId' => array(
							'type' => 'id',
							),
						),
					'required' => array(
						'and' => array(
							'webhookId',
							),
						'or' => array(
							),
						),
					'response' => $spark_apis_get_put_post_http_codes,
					),
				'PUT' => array(
					'description' => 'Updates a webhook, by ID.',
					'headers' => array(
						'Content-type' => 'application/json',
						),
					'params' => array(
						'name' => array(
							'type' => 'string',
							),
						'targetUrl' => array(
							'type' => 'url',
							),
						),
					'required' => array(
						'and' => array(
							'name',
							'targetUrl',
							),
						'or' => array(
							),
						),
					'response' => $spark_apis_get_put_post_http_codes,
					),
				'DELETE' => array(
					'description' => 'Deletes a webhook, by ID.',
					'headers' => array(
						'Content-type' => 'application/json',
						),
					'params' => array(
						'webhookId' => array(
							'type' => 'id',
							),
						),
					'required' => array(
						'and' => array(
							'webhookId',
							),
						'or' => array(
							),
						),
					'response' => $spark_apis_delete_http_codes,
					),
				),
			),
		),
	);

//echo json_encode($spark_endpoints, JSON_PRETTY_PRINT);

?>
