<?php

namespace WAMP;

/**
 * WAMP Server client
 *
 * @author Martin Bažík <martin@bazo.sk>
 */
class WAMPClient
{

	/** @var string */
	private $endpoint;

	/** @var string */
	private $serverHost;

	/** @var int */
	private $serverPort = 80;

	/** @var resource */
	private $fd;

	private $connected = FALSE;

	private $sessionId = NULL;
	
	/**
	 * @param type $endpoint The WAMP server endpoint
	 */
	public function __construct($endpoint)
	{
		$this->endpoint = $endpoint;
		$this->parseUrl();
	}


    /**
     * Connects using websocket protocol
     * @param string $target Our Target on RemoteServer
     * @return string
     */
    public function connect($target='/websocket/')
	{
		if($this->connected) {
			return $this->sessionId;
		}
		
		$this->fd = stream_socket_client($this->serverHost . ':' . $this->serverPort, $errno, $errstr);

		if (!$this->fd) {
			throw new \RuntimeException('Could not open socket. Reason: ' . $errstr);
		}

        $response = $this->upgradeProtocol($target);

		$this->verifyResponse($response);

		$payload = json_decode($this->read());

		if ($payload[0] != WAMPProtocol::MSG_WELCOME) {
			throw new \RuntimeException('WAMP Server did not send welcome message.');
		}
		
		$this->sessionId = $payload[1];
		return $this->sessionId;
	}


    private function upgradeProtocol($target)
	{
		$key = $this->generateKey();

        if(strpos($target, '/') === FALSE ) {
            throw new \RuntimeException('Wamp Server Target is wrong.');
        }

        $out = "GET ".$target." HTTP/1.1\r\n";
        $out .= "Host: {$this->serverHost} \r\n";
		$out .= "Upgrade: WebSocket\r\n";
		$out .= "Connection: Upgrade\r\n";
		$out .= "Sec-WebSocket-Key: $key \r\n";
		$out .= "Sec-WebSocket-Version: 13\r\n";
		$out .= "Origin: *\r\n\r\n";

		fwrite($this->fd, $out);

		return fgets($this->fd);
	}


	private function verifyResponse($response)
	{
		if ($response === FALSE) {
			throw new \RuntimeException('WAMP Server did not respond properly');
		}
		$subres = substr($response, 0, 12);
		
		if ($subres != 'HTTP/1.1 101') {
			throw new \RuntimeException('Unexpected Response. Expected HTTP/1.1 101 got ' . $subres);
		}
	}


	/**
	 * Read the buffer and return the oldest event in stack
	 *
	 * @see https://tools.ietf.org/html/rfc6455#section-5.2
	 * @return string
	 */
	private function read()
	{
		// Ignore first byte
		fread($this->fd, 1);

		// There is also masking bit, as MSB, bit it's 0
		$payloadLength = ord(fread($this->fd, 1));

		switch ($payloadLength) {
			case 126:
				$payloadLength = unpack("n", fread($this->fd, 2));
				$payloadLength = $payloadLength[1];
				break;
			case 127:
				//$this->stdout('error', "Next 8 bytes are 64bit uint payload length, not yet implemented, since PHP can't handle 64bit longs!");
				break;
		}

		return fread($this->fd, $payloadLength);
	}


	/**
	 * Disconnect
	 *
	 * @return boolean
	 */
	public function disconnect()
	{
		if ($this->fd) {
			fclose($this->fd);
			return TRUE;
		}

		return FALSE;
	}


	/**
	 * Send message to the websocket
	 *
	 * @access private
	 * @param array $data
	 * @return ElephantIO\Client
	 */
	private function send($data)
	{
		$rawMessage = json_encode($data);
		$payload = new WebsocketPayload;
		$payload->setOpcode(WebsocketPayload::OPCODE_TEXT)
				->setMask(TRUE)
				->setPayload($rawMessage);
		$encoded = $payload->encodePayload();
		fwrite($this->fd, $encoded);

		return $this;
	}


	/**
	 * Establish a prefix on server
	 * @see http://wamp.ws/spec#prefix_message
	 * @param type $prefix
	 * @param type $uri
	 */
	public function prefix($prefix, $uri)
	{
		$type = WAMPProtocol::MSG_PREFIX;
		$data = [$type, $prefix, $uri];
		$this->send($data);
	}


	/**
	 * Call a procedure on server
	 * @see http://wamp.ws/spec#call_message
	 * @param string $procURI
	 * @param mixed $arguments
	 */
	public function call($procUri, $arguments = [])
	{
		$args = func_get_args();
		array_shift($args);
		$type = WAMPProtocol::MSG_CALL;
		$callId = uniqid("", $moreEntropy = TRUE);
		$data = array_merge(array($type, $callId, $procUri), $args);

		$this->send($data);
	}


	/**
	 * The client will send an event to all clients connected to the server who have subscribed to the topicURI
	 * @see http://wamp.ws/spec#publish_message
	 * @param string $topicUri
	 * @param string $payload
	 * @param string $exclude
	 * @param string $eligible
	 */
	public function publish($topicUri, $payload, $exclude = [], $eligible = [])
	{
		$type = WAMPProtocol::MSG_PUBLISH;
		$data = array($type, $topicUri, $payload, $exclude, $eligible);
		$this->send($data);
	}


	/**
	 * Subscribers receive PubSub events published by subscribers via the EVENT message. The EVENT message contains the topicURI, the topic under which the event was published, and event, the PubSub event payload.
	 * @param string $topicUri
	 * @param string $payload
	 */
	public function event($topicUri, $payload)
	{
		$type = WAMPProtocol::MSG_EVENT;
		$data = array($type, $topicUri, $payload);
		$this->send($data);
	}


	private function generateKey($length = 16)
	{
		$c = 0;
		$tmp = '';

		while ($c++ * 16 < $length) {
			$tmp .= md5(mt_rand(), TRUE);
		}

		return base64_encode(substr($tmp, 0, $length));
	}


	/**
	 * Parse the url and set server parameters
	 *
	 * @access private
	 * @return bool
	 */
	private function parseUrl()
	{
		$url = parse_url($this->endpoint);

		$this->serverHost = $url['host'];
		$this->serverPort = isset($url['port']) ? $url['port'] : null;

		if (array_key_exists('scheme', $url) && $url['scheme'] == 'https') {
			$this->serverHost = 'ssl://' . $this->serverHost;
			if (!$this->serverPort) {
				$this->serverPort = 443;
			}
		}

		return TRUE;
	}


}