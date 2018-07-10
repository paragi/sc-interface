<?php
/*============================================================================*\
  GPIO direct pin handler

  Namespace should reflect the directory containing this file.
  Class name should be the file name without extention.
\*============================================================================*/
namespace device\rpi;
class gpio_w1 {
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

  Provids access to the one-wire thermo, through the kernel pseudo filesystem 
  sysfs interface.

  To enable one-wire support in the kernel:

  Edit /etc/modules and add the lines:   
    w1_gpio
    w1_therm
  
  Edit /boot/config.txt and add the lines:
    #Enable one-wire
    dtoverlay=w1-gpio
    dtoverlay=w1-gpio-pullup,gpiopin=4,pullup=4
   
 
 
  One wire devices are handled with a driver that produces pseudo files.
  On a DS18 Only  you can only read the temperature and in 12 mode.

  A directory are made for each device under /sys/bus/w1/devices/

  So far this works under linux
  NB! Don't forget to set permissions for the webuser to be in group dialout
  or use UDEV:
  sudoedit /etc/udev/rules.d/50-ttyusb.rules and stick this in there:
    KERNEL=="ttyUSB[0-9]*",NAME="tts/USB%n",SYMLINK+="%k",GROUP="uucp",MODE="066

 
\*============================================================================*/

var $description="RPI GPIO One-wire (w1) device handler";


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

  // Execute commands
  if(!is_array($command)) $command=[$command];
  foreach($command as $cmd_str){
    $cmd=explode(" ", $cmd_str);

    if($cmd[0] == 'capabilities'){
      $response['result']=["get","status","list","diagnostic"];
      
    }elseif($cmd[0] == 'description'){
      $response['result']=$description;
      
    }elseif($cmd[0] == 'list'){
      // List connected device IDs
      // Get a list of w1 devices attached to the GPIO and remove the bus master
      // from, the list
      $list_of_devices=glob("/sys/bus/w1/devices/*");
      if(is_array($list_of_devices))
        foreach($list_of_devices as $key=>$val)
          if(strpos($val,"bus_master") === false)
            $response['result'][]=substr($val,strrpos($val,"/")+1);

    }elseif(empty($unit_id)){
      $response['error']="Please specify the unit ID";
    
    }elseif($cmd[0] == 'status'){
      $response['state'] = 
        file_exists("/sys/bus/w1/devices/".$unit_id."/w1_slave") 
          ?"on-line"
          :"off-line";

    }elseif($cmd[0] == 'get'){
      if(!file_exists("/sys/bus/w1/devices/".$unit_id."/w1_slave")){
        $response['state']="off-line";
      }else{
        $data=file_get_contents("/sys/bus/w1/devices/".$unit_id."/w1_slave");
        if(!$data){
          $response['state']="off-line";
        }else{
          $temp=substr($data,strrpos($data,"=")+1);    
          if(!empty($temp)) $response['state']=(float)$temp/1000;
        }
      }

    }elseif($cmd[0] == 'diagnostic'){
      $file="/sys/bus/w1/devices/".$unit_id."/w1_slave";
      if(file_exists($file))
        $response['reply'][]="The device is on-line";
      else    
        $response['reply'][]="The device is off-line";
      if(!is_dir("/sys/bus/w1/devices/w1_bus_master1"))
        $response['reply'][]="The gpio-w1 module dose not seem to be configured in the kernel";

    }else{  
      $response['error'] = 
        "The {$this->description} dose not support the command: '{$cmd[0]}'";
    }
  }

  // Fill with default values
  if(!isset($response['error'])) $response['error']=null;
  return $response;
}

/*==========================================================================*\
  Initialize device

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
  echo "her";      
  static $initialized=false;
  if(!$unit_id && $initialized) return;
  $initialized=true;

}

}
?>

