<?php

//USAGE EXAMPLE:
//$filename = "/home/pachanga/tmp/foo.php";
//$response = phpfpm_request("localhost", 9000, $filename);
//var_dump($response);

define('FCGI_VERSION_1', 1);
define('FCGI_BEGIN_REQUEST', 1);
define('FCGI_ABORT_REQUEST', 2);
define('FCGI_END_REQUEST', 3);
define('FCGI_PARAMS', 4);
define('FCGI_STDIN', 5);
define('FCGI_STDOUT', 6);
define('FCGI_STDERR', 7);
define('FCGI_DATA', 8);
define('FCGI_GET_VALUES', 9);
define('FCGI_GET_VALUES_RESULT', 10);

function _phpfpm_echo($what)
{
  echo $what;
}

function _phpfpm_make_server_vars($filename) 
{
  $nsv["SERVER_SOFTWARE"]="";
  $nsv["SERVER_NAME"]="fake";
  $nsv["SERVER_PROTOCOL"]='1.0';
  $nsv["SERVER_PORT"]=80;
  $nsv["SERVER_ADDR"]='localhost';
  $nsv["SERVER_API"]='1.0';
  $nsv["REQUEST_METHOD"]="GET";
  $nsv["PATH_TRANSLATED"]=realpath($filename);
  $nsv["SCRIPT_NAME"]=basename($filename);
  $nsv["QUERY_STRING"]='/' . basename($filename);
  $nsv["REMOTE_HOST"]='localhost';
  $nsv["DOCUMENT_ROOT"]='/';
  $nsv["REQUEST_URI"]='/' . basename($filename);
  $nsv["PATH_INFO"]=$filename;

  return $nsv;
}

class phpfpm_connection 
{
  private $peof = false;

  function build_fcgi_packet($type, $content) 
  {
    $clen=strlen($content);

    $packet=chr(FCGI_VERSION_1);
    $packet.=chr($type);
    $packet.=chr(0).chr(1); // Request id = 1
    $packet.=chr((int)($clen/256)).chr($clen%256); // Content length
    $packet.=chr(0).chr(0); // No padding and reserved
    $packet.=$content;

    return($packet);

  }

  function build_fcgi_nvpair($name, $value) 
  {
    $nlen = strlen($name);
    $vlen = strlen($value);

    if ($nlen < 128) {

      $nvpair = chr($nlen);

    } else {

      $nvpair = chr(($nlen >> 24) | 0x80) . chr(($nlen >> 16) & 0xFF) . chr(($nlen >> 8) & 0xFF) . chr($nlen & 0xFF);

    }

    if ($vlen < 128) {

      $nvpair .= chr($vlen);

    } else {

      $nvpair .= chr(($vlen >> 24) | 0x80) . chr(($vlen >> 16) & 0xFF) . chr(($vlen >> 8) & 0xFF) . chr($vlen & 0xFF);

    }

    return $nvpair . $name . $value;

  } 

  function decode_fcgi_packet($data) 
  {
    $ret["version"]=ord($data{0});
    $ret["type"]=ord($data{1});
    $ret["length"]=(ord($data{4}) << 8)+ord($data{5});
    $ret["content"]=substr($data, 8, $ret["length"]);

    return($ret);

  }

  function parser_open($args, $filename, &$rq_err, &$cgi_headers) 
  {
    global $conf, $add_errmsg;

    // Connect to FastCGI server

    $fcgi_server=explode(":", $args);

    if (!$this->sck=fsockopen($fcgi_server[0], $fcgi_server[1], $errno, $errstr, 5)) {

      $rq_err=500;
      $tmperr="mod_fcgi: unable to contact application server ($errno : $errstr).";
      $add_errmsg.=($tmperr."<br><br>");
      _phpfpm_echo("WARN: ".$tmperr, NW_EL_WARNING);
      return (false);

    }

    // Begin session

    $begin_rq_packet=chr(0).chr(1).chr(0).chr(0).chr(0).chr(0).chr(0).chr(0);
    fwrite($this->sck, $this->build_fcgi_packet(FCGI_BEGIN_REQUEST, $begin_rq_packet));

    // Build params

    $fcgi_params_packet = '';
    $fcgi_params_packet.=$this->build_fcgi_nvpair("GATEWAY_INTERFACE", "FastCGI/1.0");
    $nsv = _phpfpm_make_server_vars($filename);
    foreach($nsv as $key=>$var) 
      $fcgi_params_packet.=$this->build_fcgi_nvpair($key, $var);

    $stdin_content="";

    // Send params

    fwrite($this->sck, $this->build_fcgi_packet(FCGI_PARAMS, $fcgi_params_packet));
    fwrite($this->sck, $this->build_fcgi_packet(FCGI_PARAMS, ""));

    //var_dump($fcgi_params_packet);

    // Build and send stdin flow

    if ($stdin_content) fwrite($this->sck, $this->build_fcgi_packet(FCGI_STDIN, $stdin_content));
    fwrite($this->sck, $this->build_fcgi_packet(FCGI_STDIN, ""));

    // Read answers from fastcgi server

    $content="";

    while (($p1=strpos($content, "\r\n\r\n"))===false) {

      $tmpp=$this->decode_fcgi_packet($packet=fread($this->sck, 8));
      //var_dump($tmpp);
      $tl=$tmpp["length"]%8;
      $tadd=($tl?(8-$tl):0);
      $resp=$this->decode_fcgi_packet($packet.fread($this->sck, $tmpp["length"]+$tadd));
      //var_dump($resp);

      if ($valid_pck=($resp["type"]==FCGI_STDOUT || $resp["type"]==FCGI_STDERR)) $content.=$resp["content"];

      if ($resp["type"]==FCGI_STDERR) _phpfpm_echo("WARN: mod_fcgi: app server returned error : '".$resp["content"]."'", NW_EL_WARNING);

    }

    if (feof($this->sck)) $this->peof=true;

    if ($p1) {

      $headers=explode("\n", trim(substr($content, 0, $p1)));
      $content=substr($content, $p1+4);

    }

    $GLOBALS["http_resp"]="";

    //$cnh=access_query("fcginoheader");

    foreach ($headers as $s) if ($s=trim($s)) {

      if (substr($s, 0, 5)=="HTTP/") {

        $hd_key="STATUS";
        strtok($s, " ");

      } else {

        $hd_key=strtok($s, ":");

      }

      $hd_val=trim(strtok(""));
      $hku=strtoupper($hd_key);

      //if ($cnh) foreach ($cnh as $nohdr) if ($hku==strtoupper($nohdr)) $hd_key="";

      if ($hd_key) {

        if ($hku=="SET-COOKIE") {

          $cgi_headers["cookies"][]=$hd_val;

        } else {

          $cgi_headers[$hd_key]=$hd_val;

        }

      }

    }

    $this->parsed_output=$content;

  }

  function parser_get_output() 
  {
    if (!$this->peof && !$this->parsed_output) {

      $tmpp=$this->decode_fcgi_packet($packet=fread($this->sck, 8));
      $tl=$tmpp["length"]%8;
      $tadd=($tl?(8-$tl):0);
      $resp=$this->decode_fcgi_packet($packet.fread($this->sck, $tmpp["length"]+$tadd));

      if ($valid_pck=($resp["type"]==FCGI_STDOUT || $resp["type"]==FCGI_STDERR)) {

        $content.=$resp["content"];

      } else {

        $this->peof=true;				

      }

      if ($resp["type"]==FCGI_STDERR) _phpfpm_echo("WARN: mod_fcgi: app server returned error : '".$resp["content"]."'", NW_EL_WARNING);

    }

    $content = '';
    if ($this->parsed_output) {

      $content=$this->parsed_output;
      $this->parsed_output="";

    }

    return($content);

  }

  function parser_eof() 
  {
    return($this->peof);
  }

  function parser_close() 
  {
    $this->peof=false;
    fclose($this->sck);
  }

}

function phpfpm_guess_host($port)
{
  exec('netstat -lnt', $out, $ret);

  foreach($out as $line)
  {
    $line = trim($line);
    if(!$line)
      continue;
    $items = preg_split("/\s+/", $line);
    if($items[0] == "tcp" && preg_match("~(\S+):(\d+)~", $items[3], $m) && $items[5] = "LISTEN")
    {
      if($m[2] == $port)
        return $m[1];
    }
  }
  return false;
}

function phpfpm_request($host, $port, $filename)
{
  $fcgi = new phpfpm_connection();
  $rg_err = array();
  $cgi_headers = array();
  $fcgi->parser_open("$host:$port", $filename, $rq_err, $cgi_headers);
  //var_dump($rq_err);
  return $fcgi->parser_get_output();
}

