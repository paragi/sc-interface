<?php
/*============================================================================*\
  Generic REST interface for inteligent network device input
  
  Device should send a GET or POST request with the following variables:
    - state: the value or state of the input device. 
    - serial: the serial number that uniquely identify the device.

  only registred devices, may access this interface.  

\*============================================================================*/
namespace device\push;
define('DIR_BASE',realpath( __DIR__  . str_repeat(DIRECTORY_SEPARATOR . '..',2)) . DIRECTORY_SEPARATOR);

require DIR_BASE . "rocket-store.php";
echo "<pre>\n";
$rsdb = new \Paragi\RocketStore([
    "data_storage_area" => DIR_BASE . "var/rsdb"
  , "data_format" => RS_FORMAT_JSON
]);

function test_services($service,$func,$data='',$asyncronos_execution=false){
   echo "Event: " . print_r($data);
}

do{
  $check = "Data missing in request" ;
  if(empty($_REQUEST)) break; 

  $check = "Valid IP address: " . $_SERVER['REMOTE_ADDR'];
  if(!filter_var($_SERVER['REMOTE_ADDR'],FILTER_VALIDATE_IP)) break;

  $check = "Access denied for unregistred device";
  $reply = $rsdb->get("push-device",$_SERVER['REMOTE_ADDR']);
  if(!$reply['count']){
    $reply = $rsdb->get("push-request",$_SERVER['REMOTE_ADDR']);
    // Add new request
    if(!$reply['count']){
      $record = $_REQUEST;
      $record['time'] = time();
      $record['ip'] = $_SERVER['REMOTE_ADDR'];
      $reply = $rsdb->post("push-request",$record['ip'],$record);
      if($reply['count'] != 1)
        $check = $reply['error'];
    }
    break;
  }
print_r($reply);
  $check = "Serial number mismatch";
  if(!empty($reply['result'][$_SERVER['REMOTE_ADDR']]['serial']) && $_REQUEST['serial'] != $reply['result'][$_SERVER['REMOTE_ADDR']]['serial'])
    break;

  // Accept puch input
  $record = $_REQUEST;
  //$record = array_merge($_REQUEST,reset($reply['result']));
  $record['time'] = time();
  $record['ip'] = $_SERVER['REMOTE_ADDR'];
  $record['origin'] = "push device";
  test_services("event","announce",$record,true);

  $check = false;
}while(false);

// Reply to device
echo $check ?: "ok", "\n";
?>