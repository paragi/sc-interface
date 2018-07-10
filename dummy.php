<?php
/*============================================================================*\
  Dummy device handler
  
  Simulates a device

  Namespace should reflect the directory containing this file.
  Class name should be the file name without extention.
\*============================================================================*/
namespace device;
class dummy {
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
    ,"status"
    ,"capabilities"
    ,"description"
    ,"get"
    ,"set"
    ,"toggle"
    ,"diagnostic"
  ];

  $description="Dummy device";

  // Execute commands
  if(!is_array($command)) $command=[$command];
  foreach($command as $cmd){
    // Split command into operation and bit number operated on
    $opr=strtok(strtolower($cmd)," \n\r");
    $action=strtok(" \n\r");
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
        switch (substr($cmd,strpos($cmd," ")+1)){
          case "2": // Report error
            $response['result']="No errors found";
            break;
          case "3": // Any error 
            $response['result']="This device has a flawless record of operation";
            break;
          case "4": // stress test
            $response['result']="There is a slight flutter in the force, when operating. Nothing to worry about though";
            break;
          default: // 1, curent state
            $response['result']="All is well";
            break;
        }
        break;
      default:
        $response=$this->operate($unit_id,$opr,$action);
        break;
    }
  }

  // Fill with default values
  //if(!isset($response['state']) && empty($response['result'])) 
  //  $response['state']='off-line';
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
        $byte=$this->file_store_get($unit_id);
      }
    }
    
  //Initialize one unit  
  }else{
    // Preset unit to last know settings
    // Get device name
    $dev_name=$this->list_devices($unit_id);
    if(!empty($dev_name)){
      $byte=$this->file_store_get($unit_id);
      // Reset port to high value
      // $this->operate($unit_id,"set");
    }
  }     
}

/*==========================================================================*\
  Do operation on device
\*==========================================================================*/
private function operate($unit_id,$opr,$action=null){

  $dev_name=$this->list_devices($unit_id);
  $state=intval($this->file_store_get($unit_id));
  
  if(empty($state)) $state = 0;
  if($opr=="set") 
    $state = isset($action) ? $action : 'on';

  if($opr=="toggle"){
    if($state == 'on') $state = 'off'; 
    elseif($state == 'off') $state = 'on'; 
    else $state = ~$state & 0xff;
  }
  
  $response["error"] = $this->file_store_set($unit_id,$state); 
  $response['state'] = $state;
  return $response;
}

/*==========================================================================*\
  List devices 

  Return array of <device ID> => <device name> for alle devices that are on-line
  or if unit ID is given, the device name of that unit, if its on-line or null
   
\*==========================================================================*/
private function list_devices($unit_id=null){
  return ["dummy"];  
} 

/*==========================================================================*\
  File store functions  
\*==========================================================================*/
private function file_store_get($unit_id){ 
  // Define file to use for storing lates output value
  $state_file=$_SERVER["DOCUMENT_ROOT"]."/var/dummy-$unit_id.dat";
  
  return @file_get_contents($state_file);
}

private function file_store_set($unit_id,$state){ 
  // Define file to use for storing lates output value
  $state_file=$_SERVER["DOCUMENT_ROOT"]."/var/dummy-$unit_id.dat";

  // Write state to file store
  $rc=file_put_contents($state_file,$state);
  if($rc === false){
    $error=error_get_last(); 
    return "Unable to store data in temporary file: $state_file. - $error[message]";
  }
}

}
?>




