<?php

/**
 * Description of class_pia_daemon
 *
 * @author dev
 */
class PiaDaemon {
  var $socket;
  var $client_index;
  var $ovpn_array;
  var $_file;

  private $state; //holds current state of the daemon
    /*
     * states:
     *  none - doing nothing
     *  connecting vpn - bash script will attempt to connect to vpn. need to check for an established connection now
     *  connected - VPN is/should be up
     *  checking vpn - checking if VPN is up
     *  checking internet - checking if Internet is up
     *  offline vpn - vpn is offline but internet is up
     *  offline internet - internet and vpn are down
     *  sleeping n - sleeping for n seconds
     *
     */


  function __construct() {
    $this->socket = false;
    $this->client_index = false;
    $this->state = 'none';
    $this->_file = new FilesystemOperations();

    $this->populate_ovpn_array();
  }

  /* pass the socket object */
  function pass_socket( &$socket ){
    $this->socket = $socket;
  }

  /**
   * reads /pia/ovpn/*.ovpn and store the conent in an array.
   * the file names will be used as the VPN name by the connect function
   */
  private function populate_ovpn_array(){
    if( is_dir('/pia/ovpn') ){
      $_files = new FilesystemOperations();

      $tmp = array('ovpn');
      $_files->set_ls_filter($tmp, 2);
      $_files->set_ls_filter_mode('include');
      $this->ovpn_array = $_files->ls('/pia/ovpn');
    }
  }

  /**
   * allows you to pass the client currently being processed in $_clients
   * @param type $client_index_id
   */
  function pass_client_index( &$client_index_id )  {
    if( is_int($client_index_id) === true ){
      $this->client_index = $client_index_id;
      return true;
    }else{ return false; }
  }

  /**
   * method to respond to user input, will find a matching case and do something
   * @param string $input info sent by user
   */
  function switch_input( &$input ){
    $daemon_cmd = strtoupper($input[0]);
    switch( $daemon_cmd )
    {
        case 'ST':    //DEBUG COMMAND
          $this->input_st();
          break;
        case 'HELP':    //List public commands
          $this->input_help();
          break;
        case 'EXIT';
          $this->input_exit();
          break;
        case 'CONNECT';
          $this->input_connect($input);
          break;
        case 'STATUS';
          $this->input_satus();
          break;
        case 'SHUTDOWN';
          $this->shutdown();
          break;
        case 'DISCONNECT';
          $this->input_disconnect();
          break;
        default:
          $this->input_invalid($daemon_cmd);
          break;
    }
  }

  private function input_satus(){
    global $CONF;

    print date($CONF['date_format'])." user requested status. here is a copy for the console.\r\n";
    $status = $this->get_vpn_status();

    if( $status == false ){
      //fall back on executing some commands ourselfs
      exec('/sbin/ip addr show eth0 | grep -w "inet" | gawk -F" " \'{print $2}\' | cut -d/ -f1', $ret);
      $msg = "Internet IP: ".$ret[0];
      print "$msg\r\n";
      $this->socket->write($this->client_index, $msg);
      unset($ret);

      exec('/sbin/ip addr show eth1 | grep -w "inet" | gawk -F" " \'{print $2}\' | cut -d/ -f1', $ret);
      $msg = "VM private IP: ".$ret[0];
      print "$msg\r\n";
      $this->socket->write($this->client_index, $msg);
      unset($ret);

      exec('/sbin/ip addr show tun0 2>/dev/null | grep -w "inet" | gawk -F" " \'{print $2}\' | cut -d/ -f1', $ret);
      if( array_key_exists( '0', $ret) !== true ){
        $msg = "VPN is DOWN";
      }else{
        $msg = "VPN IP: ".$ret[0];
      }
      print "$msg\r\n";
      $this->socket->write($this->client_index, $msg);
      unset($ret);

    }else{
      //use /pia/include/status.txt as it is maintained by the shell scripts
      $msg = "Internet IP: ".$status['INTERNETIP'];
      print "$msg\r\n";
      $this->socket->write($this->client_index, $msg);

      $msg = "VM private IP: ".$status['INTIP'];
      print "$msg\r\n";
      $this->socket->write($this->client_index, $msg);

      exec('/sbin/ip addr show tun0 2>/dev/null | grep -w "inet" | gawk -F" " \'{print $2}\' | cut -d/ -f1', $ret);
      if( array_key_exists( '0', $ret) !== true ){
        $msg = "VPN is DOWN";
      }else{
        $msg = "VPN IP: $status[VPNIP] Port: $status[VPNPORT]";
      }
      print "$msg\r\n";
      $this->socket->write($this->client_index, $msg);
    }

    $this->update_client_timeout();
  }

  /**
   * method to disconnect all active VPN connections
   * @return bool true,false true when done
   */
  private function input_disconnect(){
    global $CONF;

    //match found! initiate a VPN connection and break
    print date($CONF['date_format'])." Disconnecting VPN\r\n";
    exec("/pia/php/shell/pia-stop quite"); //calling my bash scripts - this should work :)
    $msg = 'VPN has been disconnected';
    $this->socket->write($this->client_index, $msg);
    $this->state = 'offline vpn';

    $this->update_client_timeout();
    return true;
  }

  /**
   * method to estabish a VPN connection
   * WARNING VPN connection must be less than 26 chars
   * @param array $input_array user supplied input after explode(" ", trim($USERINPUT));
   * @return bool true,false true when a match has been found and the connection shell script has been started
   */
  private function input_connect($input_array){
    global $_client;
    global $CONF;

    /* 0 must be connect and 1 must be a valid VPN name */
    $l = mb_strlen($input_array[1]);
    if( strtoupper($input_array[0]) === 'CONNECT' && $l > 0 && $l < 26 ){
      //check if the specified .ovpn file exists
      reset($this->ovpn_array);
      foreach( $this->ovpn_array as $ovpn ){
        if( strtolower($ovpn) === strtolower($input_array[1].'.ovpn') )
        {
          //match found! initiate a VPN connection and break
          $exec_ovpn = substr($ovpn, 0, (mb_strlen($ovpn)-5)); // -5 for .ovpn - NEVER use user supplied input when you don't have to!!!
          print date($CONF['date_format'])." Establishing a new VPN connection to $exec_ovpn\r\n";
          exec("/pia/php/shell/pia-connect \"$exec_ovpn\" > /dev/null 2>/dev/null &"); //calling my bash scripts - this should work :)
          $this->state = 'connecting vpn';

          $this->update_client_timeout();
          return true;
        }
      }
      print date($CONF['date_format'])." User supplied invalid VPN connection name: {$input_array[1]}\r\n";
      $msg = "Invalid VPN connection name! ".$input_array[1];
      $this->socket->write($this->client_index, $msg);
      $_client[$this->client_index]['cmd_error_cnt']++;

    }else{
      print date($CONF['date_format'])." User supplied invalid VPN connection name\r\n";
      $msg = "Invalid VPN connection name!";
      $this->socket->write($this->client_index, $msg);
      $_client[$this->client_index]['cmd_error_cnt']++;
    }
  }


  /**
   * disconnect a connection
   * @global type $_client
   */
  private function input_exit(){
    global $_client;

    $msg = "Good bye.\r\n".chr(0);
    $this->socket->write($this->client_index, $msg);
    $this->socket->close($this->client_index);
    unset($_client[$this->client_index]); //Remove token since client disconnected
  }

  private function input_invalid( &$input ){
    global $_client;
    global $CONF;

    $msg = "UNKOWN COMMAND $input";
    $this->socket->write($this->client_index, $msg);
    $msg = "try HELP for a list of commands";
    $this->socket->write($this->client_index, $msg);
    print date($CONF['date_format'])." Debug - Received unknown input: $input\r\n";
    $_client[$this->client_index]['cmd_error_cnt']++;
  }

  /* debug function to see if we can get the server to talk to us */
  private function input_st(){
    global $_client;
    global $CONF;

    print date($CONF['date_format'])." Received request say something.\r\n";
    $msg = date($CONF['date_format'])." Say something....\r\n";
    socket_write($_client[$this->client_index]['sock'], $msg, strlen($msg));

    $this->update_client_timeout();
  }

  /**
   * display "usage" info
   */
  private function input_help(){
    global $CONF;

    $msg = "\r\n\r\n$CONF[server_name] v:$CONF[server_ver]";
    $this->socket->write($this->client_index, $msg);

    $msg = "DAEMON COMMANDS";
    $this->socket->write($this->client_index, $msg);

    $msg = "  HELP \t lists this info";
    $this->socket->write($this->client_index, $msg);

    $msg = "  EXIT \t disconnect this connection";
    $this->socket->write($this->client_index, $msg);

    $msg = "  SHUTDOWN \t shutdown PIA daemon";
    $this->socket->write($this->client_index, $msg);

    $msg = "  LIST \t lists availabe VPN connections";
    $this->socket->write($this->client_index, $msg);

    $msg = "  CONNECT \"VPN name\" \t establishes a VPN connection";
    $this->socket->write($this->client_index, $msg);

    $msg = "  DISCONNECT \t terminate current VPN connection";
    $this->socket->write($this->client_index, $msg);

    $msg = "  STATUS \t show status information and network configuration";
    $this->socket->write($this->client_index, $msg);

    $msg = "  FORWARD \"IP\"  \t setup port forwarding to specified IP";
    $this->socket->write($this->client_index, $msg);

    $msg = "  AUTH \"username\" \"password\" \t authenticate yourself with username and password";
    $this->socket->write($this->client_index, $msg);

    $this->update_client_timeout();
  }

  /**
   * method to update the variable keeping track how long the client has been idle
   * @global type $_client
   */
  private function update_client_timeout(){
    global $_client;
    //update client timeout values
    if( isset($_client[$this->client_index]['token']) == true ){
      $_SESSION[$_client[$this->client_index]['token']]['time_last_cmd_rec'] = microtime(true);
    }
    $_client[$this->client_index]['time_con'] = microtime(true);
  }

  private function shutdown(){
    global $CONF;

    print date($CONF['date_format'])." good by cruel world...\r\n";
    exec('/pia/pia-stop');
    exec('/pia/pia-forward fix quite'); //close stuck sockets ... think I need to close all clients first
    socket_close($this->socket->socket);
    exit;
  }

  /**
   * method looks at $this->state and calls required functions
   */
  function check_state(){
    global $CONF;

    switch($this->state){
      case 'connecting vpn':
        //check if connection has been established
        if( $this->is_vpn_up() === true ){
          exec("/pia/pia-daemon > /dev/null 2>/dev/null &"); //this will check if the VPN is up and keep it that way
          $this->state = 'connected';
          print date($CONF['date_format'])." pia-daemon has been started\r\n";
          return;
        }

    }
  }

  /**
   * returns the VPN status info as an array
   * <ul><li>VPNIP:10.127.1.6</li>
   * <li>VPNPORT:44802</li>
   * <li>INTIP:192.168.10.1</li>
   * <li>INTERNETIP:192.168.192.136</li>
   * </ul>
   * @return array,boolean array containing IP data about interfaces or false on failure
   */
  function get_vpn_status(){
    $ret = array();
    $lines = explode( "\n", $this->get_status_file_contents() );

    if( $lines !== false ){
      foreach( $lines as $l ){
        if( $l != "" && substr($l, 0, 1) !== '#' )
        {
            $v = explode( ':', $l);
            //$v now contains something like [0] = VPNIP [1] 10.127.1.6
            //  VPNIP:10.127.1.6
            //  VPNPORT:44802
            //  INTIP:192.168.10.1
            //  INTERNETIP:192.168.192.136
            $ret[$v[0]] = $v[1];
        }
      }
      if( count($ret) > 0 ){
        return $ret;
      }
    }
    return false;
  }

  /**
   * method to check if the VPN is up
   * @return boolean true if VPN is up, false if not
   */
  function is_vpn_up(){
    exec('/sbin/ip addr show tun0 2>/dev/null | grep -w "inet" | gawk -F" " \'{print $2}\' | cut -d/ -f1', $ret);
    if( array_key_exists( '0', $ret) !== true ){
      return false;
    }else{
      return true;
    }
  }

  /**
   * reads /pia/include/status.txt and returns it as a string with \n line endings
   * @return string,boolean FALSE if the files does not exist or is not readable, else the contents of the file
   */
  private function get_status_file_contents(){
    $filepath = '/pia/include/status.txt';
    if( is_file($filepath) ){
      $contents = $this->_file->readfile($filepath);
      if( $contents !== false ){
        //process file contants
        //example:
        //  VPNIP:10.127.1.6
        //  VPNPORT:44802
        //  INTIP:192.168.10.1
        //  INTERNETIP:192.168.192.136
        return $this->eol($contents);
      }else{
        return false;
      }
    }
  }

  /**
   * ensures that every string uses only \n
   * @param string $string string that may contain \r\n
   * @return string retruns string with \r\n turned to n
   */
  function eol($string) {
    return str_replace("\r", "\n", str_replace("\r\n", "\r", $string) );
  }


}

?>