#!/usr/bin/php -q
<?php
/**
 * 5-in-a-row <http://cobexer.github.com/5-in-a-row/>
 * Copyright (c) 2012 Obexer Christoph. All rights reserved.
 *
 * Released under the MIT and GPL licenses.
 */

require_once(dirname(realpath(__FILE__)) . '/WebSocketChatServer.php');
require_once(dirname(realpath(__FILE__)) . '/WebSocketGameServer.php');

$server = new WebsocketServer('0.0.0.0', 12345);
$server->addEndpoint(new WebSocketChatServer($server));
$server->addEndpoint(new WebSocketGameServer($server));

$server->run();