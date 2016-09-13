<?php

/**
 * Copyright (C) 2014, 2015 Textalk
 * Copyright (C) 2015 Patrick McCarren - added payload fragmentation for huge payloads
 *
 * This file is part of Websocket PHP and is free software under the ISC License.
 * License text: https://raw.githubusercontent.com/Textalk/websocket-php/master/COPYING
 */

namespace WebSocket;

class Base {
  protected $socket, $is_connected = false, $is_closing = false, $last_opcode = null,
    $close_status = null, $huge_payload = null;

  protected static $opcodes = array(
    'continuation' => 0,
    'text'         => 1,
    'binary'       => 2,
    'close'        => 8,
    'ping'         => 9,
    'pong'         => 10,
  );

  public function getLastOpcode()  { return $this->last_opcode;  }
  public function getCloseStatus() { return $this->close_status; }
  public function isConnected()    { return $this->is_connected; }

  public function setTimeout($timeout) {
    $this->options['timeout'] = $timeout;

    if ($this->socket && get_resource_type($this->socket) === 'stream') {
      stream_set_timeout($this->socket, $timeout);
    }
  }

  public function setFragmentSize($fragment_size) {
    $this->options['fragment_size'] = $fragment_size;
    return $this;
  }

  public function getFragmentSize() {
    return $this->options['fragment_size'];
  }

  public function send($payload, $opcode = 'text', $masked = true) {
    if (!$this->is_connected) $this->connect(); /// @todo This is a client function, fixme!

    if (!in_array($opcode, array_keys(self::$opcodes))) {
      throw new BadOpcodeException("Bad opcode '$opcode'.  Try 'text' or 'binary'.");
    }

    // record the length of the payload
    $payload_length = strlen($payload);

    $fragment_cursor = 0;
    // while we have data to send
    while ($payload_length > $fragment_cursor) {
      // get a fragment of the payload
      $sub_payload = substr($payload, $fragment_cursor, $this->options['fragment_size']);

      // advance the cursor
      $fragment_cursor += $this->options['fragment_size'];

      // is this the final fragment to send?
      $final = $payload_length <= $fragment_cursor;

      // send the fragment
      $this->send_fragment($final, $sub_payload, $opcode, $masked);

      // all fragments after the first will be marked a continuation
      $opcode = 'continuation';
    }

  }

  private const FIRST_BYTE_MASK       = 0b10001111;
  private const SECOND_BYTE_MASK      = 0b11111111;

  private const FINAL_BIT             = 0b10000000;
  private const OPCODE_MASK           = 0b00001111;

  private const MASKED_BIT            = 0b10000000;
  private const PAYLOAD_MASK          = 0b01111111;

  private const PAYLOAD_LENGTH_16BIT  = 0b01111110;
  private const PAYLOAD_LENGTH_64BIT  = 0b01111111;


  protected function send_fragment($final, $payload, $opcode, $masked) {

    $frame = [0, 0];

    // Set final bit
    $frame[0] |= self::FINAL_BIT * !!$final;
    // Set correct opcode
    $frame[0] |= self::OPCODE_MASK & self::$opcodes[$opcode];
    // Reset reserved bytes
    $frame[0] &= self::FIRST_BYTE_MASK;

    // 7 bits of payload length...
    $payload_length = strlen($payload);
    if ($payload_length > 65535) {
      $length_opcode = self::PAYLOAD_LENGTH_64BIT;
      array_push($frame, pack('J', $payload_length));
    }
    elseif ($payload_length > 125) {
      $length_opcode = self::PAYLOAD_LENGTH_16BIT;
      array_push($frame, pack('n', $payload_length));
    }
    else {
      $length_opcode = $payload_length;
    }

    // Set masked mode
    $frame[1] |= self::MASKED_BIT * !!$masked;
    $frame[1] |= self::PAYLOAD_MASK & $length_opcode;


    // Handle masking
    if ($masked) {
      // generate a random mask:
      $mask = '';
      for ($i = 0; $i < 4; $i++) $mask .= chr(rand(0, 255));
      array_push($frame, $mask);

      for ($i = 0; $i < $payload_length; $i++) $payload[$i] = $payload[$i] ^ $mask[$i & 3];
    }

    // Append payload to frame
    array_push($frame, $payload);

    $this->write($frame);
  }

  public function receive($try = false) {
    if (!$this->is_connected) $this->connect(); /// @todo This is a client function, fixme!

    $this->huge_payload = '';

    $response = null;
    do {
      $response = $this->receive_fragment();
    } while (is_null($response) && !$try);

    return $response;
  }

  protected $unparsed_fragment = '';

  protected function receive_fragment_header() {
    $minimum_size = 2;
    $minimum_remain = $minimum_size - strlen($this->unparsed_fragment);

    if ($this->will_block($minimum_remain))
      return null;

    $this->unparsed_fragment .= $this->read($minimum_remain);

    $payload_length = (integer) ord($this->unparsed_fragment[1]) & 127; // Bits 1-7 in byte 1

    switch ($payload_length)
    {
    default:
      return $this->unparsed_fragment;
    case 126:
      $extra_header_bytes = 2;
      break;
    case 127:
      $extra_header_bytes = 8;
      break;
    }

    $extra_remain =
      $minimum_size
      + $extra_header_bytes
      - strlen($this->unparsed_fragment);

    if ($this->will_block($extra_remain))
      return null;

    $this->unparsed_fragment .= $this->read($extra_remain);

    return $this->unparsed_fragment;
  }

  protected function receive_fragment() {

    $data = $this->receive_fragment_header();

    // Buffer not ready for header
    if ($data === null)
      return null;

    // Is this the final fragment?  // Bit 0 in byte 0
    /// @todo Handle huge payloads with multiple fragments.
    $final = (boolean) (ord($data[0]) & 1 << 7);

    // Should be unused, and must be false…  // Bits 1, 2, & 3
    $rsv1  = (boolean) (ord($data[0]) & 1 << 6);
    $rsv2  = (boolean) (ord($data[0]) & 1 << 5);
    $rsv3  = (boolean) (ord($data[0]) & 1 << 4);

    // Parse opcode
    $opcode_int = ord($data[0]) & 31; // Bits 4-7
    $opcode_ints = array_flip(self::$opcodes);
    if (!array_key_exists($opcode_int, $opcode_ints)) {
      throw new ConnectionException("Bad opcode in websocket frame: $opcode_int");
    }
    $opcode = $opcode_ints[$opcode_int];

    // record the opcode if we are not receiving a continutation fragment
    if ($opcode !== 'continuation') {
      $this->last_opcode = $opcode;
    }

    // Masking?
    $mask = (boolean) (ord($data[1]) >> 7);  // Bit 0 in byte 1

    $payload = '';

    // Payload length
    $payload_length = (integer) ord($data[1]) & 127; // Bits 1-7 in byte 1
    if ($payload_length > 125) {
      if ($payload_length === 126)
        $unpack_mode = 'n'; // 126: 'n' means big-endian 16-bit unsigned int
      else
        $unpack_mode = 'J'; // 127: 'J' means big-endian 64-bit unsigned int

      $unpacked = unpack($unpack_mode, substr($data, 2));
      $payload_length = current($unpacked);
    }

    // Try again later when fragment is downloaded
    if ($this->will_block($mask * 4 + $payload_length))
      return null;

    // Enter fragment reading state
    $this->unparsed_fragment = '';

    // Get masking key.
    if ($mask) $masking_key = $this->read(4);

    // Get the actual payload, if any (might not be for e.g. close frames.
    if ($payload_length > 0) {
      $data = $this->read($payload_length);

      if ($mask) {
        // Unmask payload.
        for ($i = 0; $i < $payload_length; $i++) $payload .= ($data[$i] ^ $masking_key[$i % 4]);
      }
      else $payload = $data;
    }

    if ($opcode === 'close') {
      // Get the close status.
      if ($payload_length >= 2) {
        $status_bin = substr($payload, 0, 2);
        $status = current(unpack('n', $status_bin));

        $this->close_status = $status;
        $payload = substr($payload, 2);

        if (!$this->is_closing) $this->send($status_bin . 'Close acknowledged: ' . $status, 'close', true); // Respond.
      }

      if ($this->is_closing) $this->is_closing = false; // A close response, all done.

      // And close the socket.
      fclose($this->socket);
      $this->is_connected = false;
    }

    // if this is not the last fragment, then we need to save the payload
    if (!$final) {
      $this->huge_payload .= $payload;
      return null;
    }
    // this is the last fragment, and we are processing a huge_payload
    else if ($this->huge_payload) {
      // sp we need to retreive the whole payload
      $payload = $this->huge_payload .= $payload;
      $this->huge_payload = null;
    }

    return $payload;
  }

  /**
   * Tell the socket to close.
   *
   * @param integer $status  http://tools.ietf.org/html/rfc6455#section-7.4
   * @param string  $message A closing message, max 125 bytes.
   */
  public function close($status = 1000, $message = 'ttfn') {
    $status_str = pack('n', $status);

    $this->send($status_str . $message, 'close', true);

    $this->is_closing = true;
    $response = $this->receive(); // Receiving a close frame will close the socket now.

    return $response;
  }

  protected function write($data) {

    // Array contains binary data and split-ed bytes
    if (is_array($data)) {
      foreach ($data as $part)
        $this->write($part);
      return;
    }

    // If it is not binary data, then it is byte
    if (!is_string($data))
      $data = pack('C', $data);

    $written = fwrite($this->socket, $data);

    if ($written < strlen($data)) {
      throw new ConnectionException(
        "Could only write $written out of " . strlen($data) . " bytes."
      );
    }
  }

  protected function read($length) {
    $data = '';
    while (strlen($data) < $length) {
      $buffer = fread($this->socket, $length - strlen($data));
      if ($buffer === false) {
        $metadata = stream_get_meta_data($this->socket);
        throw new ConnectionException(
          'Broken frame, read ' . strlen($data) . ' of stated '
          . $length . ' bytes.  Stream state: '
          . json_encode($metadata)
        );
      }
      if ($buffer === '') {
        $metadata = stream_get_meta_data($this->socket);
        throw new ConnectionException(
          'Empty read; connection dead?  Stream state: ' . json_encode($metadata)
        );
      }
      $data .= $buffer;
    }
    return $data;
  }

  protected function will_block($length) {
    return $this->unreaded() < $length;
  }

  protected function unreaded() {
    $metadata = stream_get_meta_data($this->socket);
    return $metadata['unread_bytes'];
  }
}
