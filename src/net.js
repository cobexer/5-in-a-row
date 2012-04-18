/**
 * 5-in-a-row <http://cobexer.github.com/5-in-a-row/>
 * Copyright (c) 2012 Obexer Christoph. All rights reserved.
 *
 * Released under the MIT and GPL licenses.
 */

var gameSocket;

function initNet() {
	gameSocket = new WrappedWebSocket("ws://" + location.hostname + ":12345/websocket/5-in-a-row");
	gameSocket.onmessage = onMessage;
	gameSocket.onopen = onOpen;
	if (gameSocket.readyState == WebSocket.OPEN) {
		gameSocket.onopen();
	}
}

function send(type, data) {
	var msg = data || {};
	msg.type = type;
	gameSocket.send(JSON.stringify(msg));
}

function onOpen() {
	if (!this.initialized) {
		send('init', gamePlayer);
		this.initialized = true;
	}
}

function onMessage(event) {
	//TODO: handle server sent messages
	var msg = JSON.parse(event.data);
	switch(msg.type) {
	// some player clicked somewhere
	case 'click':
		// msg = { player: {...}, x: x, y: y [, isNextPlayer: true] }
		// if (msg.isNextPlayer) ... we are the next one to click
		break;
	// some info about a player in this game changed
	case 'updatePlayer':
		// msg = { oldPlayer: { name: '', color: '', method: '' }, newPlayer: { name: '', color: '', method: '' } }
		break;
	// server tells us the config for us
	case 'init':
		// msg = { name: '', color: '', method: '' }
		break;
	// new player joined our game
	case 'join':
		// msg = { player: {...} }
		//TODO: reset the game array
		break;
	case 'reset':
		// msg = {}
		//TODO: reset the game array
		break;
	case 'playerLeft':
		// msg = { player: {...} }
		//TODO: reset the game array
		break;
	case 'newGame':
		// msg= { name: '' }
		//TODO: reset game array, enable game admin controls
		break;
	}
}
