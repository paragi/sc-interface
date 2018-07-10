<?php
/*============================================================================*\
  Device handler for CanaKit UK 1104 PICaxe 18 relay board
  
  In general:
  ------------------------------------------------------------------------------
  This file are included by command handler

  Let error handling be done with PHP error

  A devices that are unavailable are NOT an error. The state is just off-line

  Commands should be device specific or close to it. Translations is done with 
  the interaction descriptor. Commands should be at leas something like this:
    - status : request status of unit. Is it on-line etc
    - get      
    - set		
    - capabilities : list of commands
    - description : a text string
    (Empty means status request of unit)

  Device ID is a code is used to identify which device is being addressed.

  Sensitivity is an array with the keys "set" and "get" set to sensitivity for 
  the particular interaction. Only the handler know what type of operation a 
  command really is. (meta=all, get/set=some)

  If a series of commands are requested (array), only the last commands reply
  are returned. 
  Execution are stopped on error.

  USB devices that are unavailable are NOT an error. The state is just off-line


  Device specifics:
  ------------------------------------------------------------------------------
  So far this works under linux
  NB! Don't forget to set permissions for the webuser to be in group dialout
  or use UDEV:
  sudoedit /etc/udev/rules.d/50-ttyACM.rules and stick this in there:
    KERNEL=="ttyACM[0-9]*",GROUP="dialout",MODE="666"
  sudo /etc/init.d/udev restart

  Device ID is a two letter identification code, coded into the device.
  It is used to identify wich device is being adressed if there multible same units
  attached.

  NB: Reply of relay states are translated from 0/1 to off/onls /dev


\*============================================================================*/
//define("_DEV_DEBUG",true);

$handler=function($command,$device_id=null){

  $retry=5;

  // List supported commmands and there security type (Get/set/meta)
  $keyword=array(
     "about"=>"get"
    ,"get"=>"get"
    ,"gettemp"=>"get"
    ,"getanalog"=>"get"
    ,"on"=>"set"
    ,"off"=>"set"
    ,"toggle"=>"set"
    ,"setmode"=>"set"
    ,"settempres"=>"set"
    ,"setid"=>"set"
    ,"list"=>"set"
    ,"capabilities"=>"meta"
    ,"description"=>"meta"
  );

  $description="CanaKit UK 1104 PICaxe 18 micro controler relay board";

  $response=array("error"=>"");

  // Make sure command is an array
  if(!is_array($command)) $command=array($command);

  /*==========================================================================*\
    Check security clearance
  \*==========================================================================*/
  $get_status=false;
  $cmd_obj=array();
  foreach($command as $key=>$cmd){
    // Default to about command
    if(!$cmd) $command[$key]=$cmd="ABOUT";

    //Determin object of operation
    $obj=strtok(strtolower($cmd),".(\n\r");
    $opr=strtok(".(\n\r");
    // If object less operation, first word is the operation
    if(!$opr) $opr=$obj;

    //Determin type of operation
    $type=$keyword[$opr];
    if(!$type)
      return array('error'=>"The device handler did not recognize command [$obj.$opr] ($cmd) ");

    // Mark device for on-line status request
    if($type!='meta' && $opr!='list'){
      // Check ID
      if(!$device_id) return array("error"=>"No device ID given");
      $get_status=true;
      $responce['status']="off-line";
    }

    // Add to command object
    $cmd_obj[]=array('cmd'=>$cmd,'opr'=>$opr,'type'=>$type);
  }

  /*==========================================================================*\
    Check that device are online
  \*==========================================================================*/
  // (Unix) List all ACM devices currently attached.
  // The device name are assigned by order of connection (Not usable for id)
  $device=glob("/dev/*ACM*",GLOB_NOSORT | GLOB_MARK );
  foreach($device as $name){
    // Set sane values
    //    @exec("/bin/stty -F $name sane raw cs8 hupcl cread clocal -echo -onlcr",$output,$rc);
    //  if($rc) return array('error'=>"Server unable to access device: $output");

    // Connect to unit
    // echo "Open $name<br>";
    $fp=@fopen($name,"c+");
    if(!$fp){
      echo "Open failed<br>";
      $error=error_get_last(); 
      return array('error'=>"Server unable to access device: ".$error[message]);
    }
 
    // Get ID string
    
    for($i=0;$i<$retry;$i++){
      fwrite($fp,"ABOUT\r\n");
      $reply=$uk1104_read($fp);

 //    echo "Reply: ",print_r($reply,true)."<br>";
//echo "$reply[0] ==ABOUT && ". substr($reply[2],0,2)."==ID";
      if($reply && $reply[0]=="ABOUT" && substr($reply[2],0,2)=="ID") break;
    }

    if($i>=$retry) 
      return array('error'=>"Communication with device failed $retry times.");
    
    $id=substr($reply[2],3,2);

    // Set on-line status
    if($get_status && $device_id==$id){
      $responce['status']="on-line";
      break;

    // Collect ID's
    }else{
      $did[]=$id;
    }
    fclose($fp);
  }
  // echo "DID's ".print_r($did,true);
  /*==========================================================================*\
    Execute commands
  \*==========================================================================*/
  foreach($cmd_obj as $cmd){
    switch ($cmd['opr']) {
      // Meta commands
      case "capabilities":
        $response['result']=array_keys($keyword);
        break;
      case "description":
        $response['result']=$description;
        break;
      case "status":
        break;
      case "list":
        $response['result']=$did;
        break;
      default:
        for($i=0;$i<$retry;$i++){
          // Send command
          fwrite($fp,$cmd['cmd']."\r\n");

          // Get reply
          $reply=$uk1104_read($fp);
          if(!$reply) return array('error'=>"The unit UK1104_$device_id had a timeout or other failure in communication");

          // Retry if command is not echoed back correctly
          if($reply[0]!=$cmd['cmd']) continue;

          // Look for error message
          if(isset($reply[1]) && strpos(" ".$reply[1],"ERROR"))
            return array('error'=>"The device replies: $reply[1]");

          // exit on succes
          $response['state']=$reply[1];
          break;
        }
    }

    if(defined("_DEV_DEBUG")){
      echo "response from device: " . print_r($response,true) ."</pre>";
    }

  }
  return $response;
};

/*==========================================================================*\
  Read responce from device

  Use nonblocking for reading, but not for writing and read one char at a time.

  first line is an echo
  look for prompt "::" as end of transmission

  Return false on timeout
\*==========================================================================*/
$uk1104_read=function($fp){
  $i=0;
  $cc=0;
  $done=false;
  // Set total timeout to 500 ms
  $timeout=microtime(true)+1;
  stream_set_blocking($fp,0);
  // echo "reading: ";

  do{
    // Read one character from serial device
    $c=fgetc($fp);

    // Handle timout
    if($c === false){
      // If we already receiverd the prompt, we are done
      if($cc>1) break;
      if(microtime(true)>$timeout){
        return(false);        
      }else{
        // Wait 50 ms for data to arive
        usleep(50000);
      }
    }else{
      // echo $c;
      // Look for prompt :: but let it read until buffer is empty
      if($c==":"){
        $cc++;
      }else{
         $cc=0;
        // Add to line
        if($c>=' ')
          $line[$i].=$c;
        else if($c=="\n"){
          // echo "(nl)";
          $i++;
        }
      }
    }
  }while(true);

  stream_set_blocking($fp,1);
  // echo "<br>";
  return $line;
};

/*==========================================================================*\
  Initialize device

  Initialize settings and device, opon boot.
  This function is called when the server starts. It should be written to allow
  to be called multiple times. 
  The server is not fully initialised when this script is running. You can not 
  expect all services to respond.  
  preemptime loaded libraries are not likely to be loaded when this function is
  called. 

  Errors are not recordes and no events are send.
  
  When errors occurres, try to continue if at all posible.
  
  Output from this function are redirected to initialize.log
\*==========================================================================*/
$initialize=function($device_id=null){
  return true;
};

?>





