<?php
/*============================================================================*\
  Generic USB bit device handler
  
  Handles LPT Relay box type 1 - based on the USB to LPT PL2305 chip

  Namespace should reflect the directory containing this file.
  Class name should be the file name without extention.
\*============================================================================*/
namespace device\usb;
class generic_bit{
/*============================================================================*\
  Device handler:
  
  This is the code that talks with a device, identified by type and a unit ID

  The class defined must have two public functions:  
    handler(<command>,[<unit ID>])
    initialize([<unit ID>]) 

  This file is included by the service module to execute interaction commands on
  devices using the handler function.
  
  Commands should be device specific or close to it. Translations is done with 
  the interaction descriptor. Commands should be at leas something like this:
    - status : request status of unit. Is it on-line etc
    - get      
    - set		
    - capabilities : list of commands
    - description : a text string
    - diagnostic : an array of status at different levels 
    (Empty means get state of unit)

  If a series of commands are requested (array), only the last commands reply
  are returned. 

  Unit ID is a code is used to identify which device is being addressed.Commands are send directly to the device. Empty means status request of unit.
  (on-line?)
  A list of commands can be requested (array)  
  The response returned is only from the last command executed

  the initialize function is called once when the server starts 

  An interaction definition file is used to define the use of this device, a 
  unit ID to wich commands should be send on requests.

  Let error handling be done with PHP error
  Execution stops on error
  Error messages:
  If the user gives a command, try to think of doing something sensible, instead
  of throwing an error message. Assume instead that the user may have a 
  perfectly reasonable expectation, that at the moment is beyond your 
  comprehension. If you are unable to do that, say do politely.
  REMEMBER:  --- The user is thy GOD! ---

  A devices that is unavailable is NOT an error. The status is just off-line
  
  To debug, use the direct command interface
  
  Return value:
  
  The function should return an array of the following format:
  
  error => Always defined but empty when ok.
  The error message is a precise technical description, related to the device.
  It's often a good idea to include offending values of variables or other 
  information that facilitates solving the problem.
      
  state => If the given command requests or manipulates the state of the device,
  this variable should reflect the (new) state of the device.
  the state can also be off-line, for a number of reasons.
  
  result => If the given command dose not directly requests or manipulates the 
  state of the device, the result of the request is given here. It can be a 
  string or an array of a strings. A typical use is for meta data.
  
  reply => High level reply to the user, depending on the outcome it eg. "ok" or
  "failed" or empty string. Might be ignored.
  
\*============================================================================*/

/*============================================================================*\
  Device specifics:

  For USB -> LPT/com like interfaces eg. LPT relay card.
  
  Device types supported:
  type 1: USB to LPT PL2305 based relay board version 1
 
  This is made and tested with the very widely used PL2305 USB to LPT chip.
  Using a proper device ID is not possible with this device. The serial number 
  is usually set to zero (unless there is an eprom on the device) and there is 
  no mechanism to distinguish identical boards, except from there bus number 
  eg. placement in the USB HUB.
  This means that if the device is connected to another USB port, it gets a new
  device ID :c(

  The type 1 is output only and device ID only by bus number.
  
  NB: remember to add webserver to lp group: $ sudo usermod -a -G lp www 
  
  Output values are negated so that a 1 is relay activated.
 
  Bit sates are stored in a file in the var directory. Upon initializations 
  the stored state are reinserted.
  
  The device must be initialized, as the output is set to a state other then off
  upon connection, by the driver.

  Commands:  
  get <bit 1-n>    : return the value of the bit    
  set <bit 1-n>    : set the bit to 1 / high
  reset <bit 1-n>  : set the bit to 0 / low
  toggle <bit 1-n> : change the bit value       

 
  An internal command "init" sets the output of the device to its last known value.
  
  NB: Output on boot might be an arbitrary value
\*============================================================================*/
var $initialized=false;

/*==========================================================================*\
  Handler function.
  
  Interprets and executes commands on device identified by $unit_id

  Parameters:
    $command: an array of command strings
    $unit_id: A string that identifies the device
  
  Return:
    an associative array containing one ore more of the following key-value pairs 
    error:  An explanation as to why the command failed
    state:  value returned of a get device state request
    status: on/off-line 
    result: an answer to a request
    
    Atleast one must be pressent.
\*==========================================================================*/
public function handler($command,$unit_id=null){
/*
  // Set security level of each command.
  // The levels are meta, get and set.
  // This array is also used to validate commands.
*/

  $keyword=[
     "list"
//    ,"status"
    ,"capabilities"
    ,"description"
    ,"get"
    ,"set"
    ,"reset"
    ,"toggle"
    ,"diagnostic"
    ,"invert"
    ,"uninvert"
  ];

  $description="Generic USB bit device";

  // Execute commands
  if(!is_array($command)) $command=[$command];
  foreach($command as $cmd){
    // Split command into operation and bit number operated on
    $opr=strtok(strtolower($cmd)," \n\r");
    $bitno=strtok(" \n\r");
    switch ($opr){
      // Meta commands
      case "capabilities":
        $response['result']=$keyword;
        break;
      case "description":
        $response['result']=$description;
        break;
      case "list": // List connected device IDs
        $response=["result"=>$this->list_devices()];
        break;
      case "diagnostic":
        if(!$unit_id) return ["error"=>"No unit ID given"];
        $response['reply']='unavailable at this time';  
        break;
      default:
        if(!$unit_id) return ["error"=>"No unit ID given"];
        $response=$this->operate($unit_id,$opr,$bitno);
        break;
    }
  }

  // Fill with default values
  if(!isset($response['state']) && empty($response['result'])) 
    $response['state']='off-line';
  if(!isset($response['error'])) $response['error']=null;
  if(!empty($response['error']) & empty($response['state'])) 
    $response['state']='off-line';

  return $response;
}
  
/*==========================================================================*\
  Initialize device

  All devices that are on-line, are set to there last known values.

  Initialize settings and device, opon boot.
  This function is called when the server starts. It should be written to allow
  to be called multiple times. 
  The server might not be fully initialised when this script is running. You can
  not expect all services to respond.  
  preemptime loaded libraries are not likely to be loaded when this function is
  called. 

  Errors are not recordes and no events are send.
  
  When errors occurres, try to continue if at all posible.
  
  Output from this function are redirected to initialize.log
  
\*==========================================================================*/
public function initialize($unit_id=null){
  static $initialized=false;
  
  // Initialize all
  if(!isset($unit_id)){
    if($initialized) return;
    $initialized=true;

    // Preset unit to last know settings
    // Get device names
    $dev=$this->list_devices();
    foreach($dev as $unit_id=>$dev_name){
      if(!empty($dev_name)){
        $response = services("datastore","keyValue",["key"=>"$dev_name-$unit_id"]);
//        $data=$this->file_store_get($unit_id);
        $byte = ($data['mask'] ^ $value) & 0xff;
        $this->write_file($dev_name,$byte);
      }
    }
    
  //Initialize one unit  
  }else{
    // Preset unit to last know settings
    // Get device name
    $dev_name=$this->list_devices($unit_id);
    if(!empty($dev_name)){
      $data=$this->file_store_get($unit_id);
      $byte = ($data['mask'] ^ $value) & 0xff;
      $this->write_file($dev_name,$byte);
    }
  }     
}

/*==========================================================================*\
  Do operation on device
\*==========================================================================*/
private function operate($unit_id,$opr,$bitno=null){
//printf("Command: %s %d\n",$opr,$bitno);
  // Get device name
  $dev_name=$this->list_devices($unit_id);
  if(empty($dev_name)) return ["state"=>"off-line"];

  // Get state. This can opnly be read from the store
  // Get the bit state from file (or 0) 
  $data = $this->file_store_get($unit_id);
  if(empty($data['state'])) $data['state'] = 0;
  if(empty($data['mask'])) $data['mask'] = 0;

  if(!in_array($opr,["get","set","reset","toggle","invert","uninvert"]))
    return ["error"=>"device unknown operation ($opr)"];
    
  if(!is_numeric($bitno))
    return ["error"=>"Please specify bit (1-8) to performe opreation on"];

  if($bitno<1) $bitno=1;
  if($bitno>8) $bitno=8;

  $change_state = true;
  if($opr=="set")           $data['state'] |= (1<<($bitno-1));
  elseif($opr=="reset")     $data['state'] &= ~(1<<($bitno-1));
  elseif($opr=="toggle")    $data['state'] ^= (1<<($bitno-1));
  elseif($opr=="invert")    $data['mask'] |= (1<<($bitno-1));
  elseif($opr=="uninvert")  $data['mask'] &= ~(1<<($bitno-1));
  else $change_state = false;

  $response['state']= ($data['state'] & (1<<($bitno-1)))?1:0;

  // Write to device    
  if($change_state){
    $byte = ($data['mask'] ^ $data['state']) & 0xff;
    $response["error"] = $this->write_file($dev_name,$byte);
  
    // Write state to file store
    if(empty($response["error"]))
      $response["error"] = $this->file_store_set($unit_id,$data); 
  }

  return $response;
}

/*==========================================================================*\
  List USB devices 

  Return array of <device ID> => <device name> for alle devices that are on-line
  or if unit ID is given, the device name of that unit, if its on-line or null
  
  (Tested on USB->LPT devices)

  The device name is assigned by order of connection (Not usable for id)
  Hack: Use connector placement (Hub id) as ID
  
\*==========================================================================*/
private function list_devices($unit_id=null){


  // Look for an LPT devices attached
  $device=glob("/sys/class/usbmisc/*",GLOB_NOSORT | GLOB_MARK );
 
  // Look for serial devices
  $device+=glob("/sys/class/tty/ttyUSB*",GLOB_NOSORT | GLOB_MARK );

  foreach($device as $name){

    // Get device name
    $uevent=parse_ini_file($name."uevent");
    if(is_array($uevent) && $uevent['DEVNAME']){
      $dev_name="/dev/".$uevent['DEVNAME'];   
    }

    // Find the sysfs path to information about the device
    $arr=explode("/",realpath(dirname(realpath($name))."/../../"));
    $path=implode("/",$arr);
    if(!$path) continue;
    
     // Get alternative device name based on bus number/device number
    // !! TEST THIS
    if(!$dev_name){
      $dev_name="/dev/bus/usb";
      $dev_name.="/".sprintf("%03d",trim(file_get_contents($path."/busnum")));
      $dev_name.="/".sprintf("%03d",trim(file_get_contents($path."/devnum")));
    }
    
    // Compose a unique ID from device serial number
    if(file_exists($path."/serial")){
      $id=trim(file_get_contents($path."/serial"));     // !!Test this!!

    // Alternativly use bus number
    }else{
      // The only way to uniquely identify identical devices, without a serial 
      // number, is to use the connetor placement in the USB bus.
      // This means that the divece change ID with placement of USB connector :(
     // Find usb bus path
     $id=end($arr);
    }
    
    // Add vendor and product ID
    if(file_exists($path."/idVendor"))
      $id.=":".sprintf("%-4s",trim(file_get_contents($path."/idVendor")));
    if(file_exists($path."/idProduct"))
      $id.=":".sprintf("%-4s",trim(file_get_contents($path."/idProduct")));

    if($id==$unit_id) break;
    $list[$id]=$dev_name;
  }

 
  if(!empty($unit_id))
    return @$dev_name;
  else
    return @$list;
} 

/*==========================================================================*\
  Write sysfs file function
  
  Return: a meaningfull error string
\*==========================================================================*/
private function write_file($fn,$byte){

  // Check that file exists
  if(!file_exists($fn))
    return "The file '$fn' dose not exists";
    
  // Check access 
  if(!is_writable($fn)){
    // Get file persimmions
    $stat=stat($fn);
    $perm=posix_getpwuid($stat['uid'])['name']
      .":".posix_getgrgid($stat['gid'])['name']
      ." ".decoct($stat['mode'] & 0777);  
    return "Write access to the file '$fn' was denied ($perm)";
  }
    
  // Write
//printf("Writing %X\n", ord(chr(intval($data) & 0xff)));
  $rc=@file_put_contents($fn,chr(intval($byte) & 0xff)); // Might return false on succes!
  if($rc<1){
    $error=error_get_last(); 
    return "Error when writing to device($fn) ".$error['message'];
  }
  
  return "";
}

/*==========================================================================*\
  File store functions  
\*==========================================================================*/
private function file_store_get($unit_id){ 
  // Define file to use for storing lates output value
  $state_file=$_SERVER["DOCUMENT_ROOT"]."/var/dev-usb-$unit_id.dat";

  if(file_exists($state_file))
    $data = @json_decode(file_get_contents($state_file),true);
  else{
    $data['state'] = 0;
    $data['mask'] = false;
  }
  return $data;
}

private function file_store_set($unit_id,$new_data){ 
  // Define file to use for storing lates output value
  $state_file=$_SERVER["DOCUMENT_ROOT"]."/var/dev-usb-$unit_id.dat";

  if(file_exists($state_file))
    $data = @json_decode(file_get_contents($state_file),true);

  if(is_array($data))
    if(empty($new_data['state']))
      $new_data['state'] = $data['state'];
    if(empty($new_data['mask']))
      $new_data['mask'] = $data['mask'];

  // Write state to file store
  $rc=file_put_contents($state_file,json_encode($new_data,JSON_NUMERIC_CHECK|JSON_PRETTY_PRINT));
  if($rc<1){
    $error=error_get_last(); 
    return "Unable to store data in temporary file: $state_file. - $error[message]";
  }
}

}
?>




