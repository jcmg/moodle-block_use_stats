<?php

define('USE_STATS_SITE_SCOPE', 1);
define('USE_STATS_COURSE_SCOPE', 2);
define('USE_STATS_MODULE_SCOPE', 3);

include_once $CFG->dirroot.'/blocks/use_stats/locallib.php';
include_once $CFG->libdir.'/pear/HTML/AJAX/JSON.php';
require_once $CFG->dirroot.'/mnet/xmlrpc/client.php';

/**
 * Constants.
 */
if (!defined('RPC_SUCCESS')) {
	define('RPC_TEST', 100);
	define('RPC_SUCCESS', 200);
	define('RPC_FAILURE', 500);
	define('RPC_FAILURE_USER', 501);
	define('RPC_FAILURE_CONFIG', 502);
	define('RPC_FAILURE_DATA', 503);
	define('RPC_FAILURE_CAPABILITY', 510);
	define('MNET_FAILURE', 511);
	define('RPC_FAILURE_RECORD', 520);
	define('RPC_FAILURE_RUN', 521);
}

/**
 * Invoke the local user who make the RPC call and check his rights.
 * @param	$user					object				The calling user.
 * @param	$capability				string				The capability to check.
 * @param	$context				int					The capability's context (optional / CONTEXT_SYSTEM by default).
 */
function use_stats_invoke_local_user($user, $capability, $context=null) {
	global $CFG, $USER, $DB;

	// Creating response
	$response = new stdclass;
	$response->status = RPC_SUCCESS;

	// Checking user
	// debug_trace(json_encode($user));
	if (!array_key_exists('username', $user) || !array_key_exists('remoteuserhostroot', $user) || !array_key_exists('remotehostroot', $user)) {
		$response->status = RPC_FAILURE_USER;
		$response->errors[] = 'Bad client user format.';
		return(json_encode($response));
	}

	if (empty($user['username'])) {
		$response->status = RPC_FAILURE_USER;
		$response->errors[] = 'Empty username.';
		return(json_encode($response));
	}

	// Get local identity
	if (!$remotehost = get_record('mnet_host', 'wwwroot', $user['remotehostroot'])){
		$response->status = RPC_FAILURE;
		$response->errors[] = 'Calling host is not registered. Check MNET configuration';
		return(json_encode($response));
	}


	$userhost = $DB->get_record('mnet_host', array('wwwroot' => $user['remoteuserhostroot']));

	if (!$localuser = $DB->get_record('user', array('username' => addslashes($user['username']), 'mnethostid' => $userhost->id))){
		$response->status = RPC_FAILURE_USER;
		$response->errors[] = "Calling user has no local account. Register remote user first";
		return(json_encode($response));
	}
	// Replacing current user by remote user

	$USER = $localuser;

	// Checking capabilities
	if (is_null($context))
	    $context = get_context_instance(CONTEXT_SYSTEM);
	if ((is_string($capability) && !has_capability($capability, $context)) || (is_string($capability) && !has_one_capability($capability, $context))) {
		$response->status = RPC_FAILURE_CAPABILITY;
		$response->errors[] = 'Local user\'s identity has no capability to run';
		return(json_encode($response));
	}
	
	return '';
}

/**
* get a complete report of user stats for a single user.
*
* @param array $callinguser
* @param string $targetuser 
* @param string $wherefrom
* // @param string $courseidfield 
* // @param string $courseidentifier 
* @param string $statsscope 
*/
function use_stats_rpc_get_stats($callinguser, $targetuser, $whereroot, /* $courseidfield, $courseidentifier, */ $statsscope = USE_STATS_SITE_SCOPE, $timefrom = 0, $json_response = true){
	global $CFG, $USER, $DB;
	
	$extresponse = new stdclass;
	$extresponse->status = RPC_SUCCESS;
	$extresponse->errors[] = array();

	// Invoke local user and check his rights
	// debug_trace("checking calling user ".json_encode($callinguser));	
	if ($auth_response = use_stats_invoke_local_user((array)$callinguser, array('block/use_stats:seesitedetails', 'block/use_stats:seecoursedetails'))){
		if ($json_response){
		    return $auth_response;
		} else {
			return json_decode($auth_response);
		}
	}

	if (empty($whereroot) || $whereroot == $CFG->wwwroot){
		// debug_trace("local get stats values for $targetuser in $wherewwwroot scoping $statsscope from ".userdate($timefrom));
		// Getting remote_course definition
		/*
		switch($courseidfield){
			case 'id':
				$course = get_record('course', 'id', $courseidentifier);
				break;
			case 'shortname':
				$course = get_record('course', 'shortname', $courseidentifier);
				break;
			case 'idnumber':
				$course = get_record('course', 'idnumber', $courseidentifier);
				break;		
		}
		
		if (!$course){
			$extresponse->status = RPC_FAILURE_RECORD;
			$extresponse->errors[] = 'Unkown course.';
			if ($json_response){
	    		return json_encode($extresponse);
	    	} else {
	    		return $extresponse;
	    	}
		}
		*/
		if (!$targetuser = $DB->get_record('user', array('username' => $targetuser))){
			$extresponse->status = RPC_FAILURE_RECORD;
			$extresponse->errors[] = 'Target user does not exist.';
			if ($json_response){
	    		return json_encode($extresponse);
	    	} else {
	    		return $extresponse;
	    	}
		}
		
		// get stats and report answer
		if (empty($CFG->block_use_stats_threshold)){
			set_config('block_use_stats_threshold', 60);
		}

        $logs = use_stats_extract_logs($timefrom, time(), $targetuser->id);
        $lasttime = $timefrom;
        $totalTime = 0;
        $totalTimeCourse = array();
        $totalTimeModule = array();

		// debug_trace('processing '.count($logs).' log entries');
        
        if ($logs){
            foreach($logs as $aLog){
                $delta = $aLog->time - $lasttime;
                if ($delta < $CFG->block_use_stats_threshold * MINSECS){
                    $totalTime = $totalTime + $delta;

                    if ($statsscope >= USE_STATS_COURSE_SCOPE){
	                    if (!array_key_exists($aLog->course, $totalTimeCourse))
	                        $totalTimeCourse[$aLog->course] = 0;
	                    else
	                        $totalTimeCourse[$aLog->course] = $totalTimeCourse[$aLog->course] + $delta;
					}

                    if ($statsscope >= USE_STATS_MODULE_SCOPE){
	                    if (!array_key_exists($aLog->course, $totalTimeModule))
	                        $totalTimeModule[$aLog->course][$aLog->module] = 0;
	                    elseif (!array_key_exists($aLog->module, $totalTimeModule[$aLog->course]))
	                        $totalTimeModule[$aLog->course][$aLog->module] = 0;
	                    else
	                        $totalTimeModule[$aLog->course][$aLog->module] = $totalTimeModule[$aLog->course][$aLog->module] + $delta;
	                }
                }
                $lasttime = $aLog->time;
            }

			// debug_trace('processed '.$totalTime.' seconds');
            
            $elapsed = floor($totalTime/MINSECS);

    		$data .= "\t<USERNAME>{$targetuser->username}</USERNAME>\n";
    		$data .= "\t<FIRSTNAME>{$targetuser->firstname}</FIRSTNAME>\n";
    		$data .= "\t<LASTNAME>{$course->idnumber}</LASTNAME>\n";
    		$data .= "\t<FROM>{$timefrom}</FROM>\n";
    		$data .= "\t<ELAPSED>{$elapsed}</ELAPSED>\n";
    		$message = "<USER>\n$data\n</USER>";
            
            if ($statsscope >= USE_STATS_COURSE_SCOPE){
 				$sitedata = '';
            	foreach($totalTimeCourse as $courseid => $statvalue){
            		$courseinfo = $DB->get_record('course', array('id' => $courseid));
            		$elapsed = floor($statvalue / MINSECS);
            		if ($elapsed < 5) continue; // cleaning output from unsignificant values
            		$coursedata = "\t<NAME>{$courseinfo->fullname}</NAME>\n";
            		$coursedata .= "\t<SHORTNAME>{$courseinfo->shortname}</SHORTNAME>\n";
            		$coursedata .= "\t<IDNUMBER>{$courseinfo->idnumber}</IDNUMBER>\n";
            		$coursedata .= "\t<ELAPSED>{$elapsed}</ELAPSED>\n";
		            if ($statsscope >= USE_STATS_MODULE_SCOPE){
		            	$moddata = '';
		            	foreach($totalTimeModule as $cmid => $statvalue){
		            		$elapsed = floor($statvalue / MINSECS);
		            		if ($elapsed < 2) continue; // cleaning output from unsignificant values
				            $cm = $DB->get_record('course_modules', array('id' => $cmid));
				            if ($cm){
				                $modulename = $DB->get_field('modules', 'name', array('id' => $cm->module));
				                $modrecname = $DB->get_field($modulename, 'name', array('id' => $cm->instance));
				            } else {
				            	$modulename = 'N.C.';
				            	$modrecname = 'N.C.';
				            }
		            		$data = "\t<NAME>{$mudrecname}</NAME>\n";
		            		$data .= "\t<TYPE>{$modulename}</TYPE>\n";
		            		$data .= "\t<IDNUMBER>{$cm->idnumber}</IDNUMBER>\n";
		            		$data .= "\t<ELAPSED>{$elapsed}</ELAPSED>\n";
		            		$moddata .= "<MODULE>\n$data\n</MODULE>\n";
		            	}
	            		$coursedata .= "<MODULES>\n$moddata\n</MODULES>\n";
		           	}
            		$sitedata .= "<COURSE>\n{$coursedata}\n</COURSE>\n";
            	}
	        	$message .= "<COURSES>\n{$sitedata}\n</COURSES>\n";
        		$extresponse->message = "<USE_STATS>\n{$message}\n</USE_STATS>";
           	} else {
        		$extresponse->message = "<USE_STATS>\n{$message}\n</USE_STATS>";
           	}
        } else {
        	$extresponse->message = "<USE_STATS><EMPTYSET /></USE_STATS>";
        }
		$extresponse->status = RPC_SUCCESS;

		// debug_trace('output built '.$extresponse->message);
				
		if ($json_response){
    		return json_encode($extresponse);
    	} else {
    		return $extresponse;
    	}
	} else {
		// debug_trace("remote source process : $wherewwwroot <> $CFG->wwwroot");	
		// Make remote call
	    $userhostroot = $DB->get_field('mnet_host', 'wwwroot', array('id' => $USER->mnethostid)); 
	        
        $rpcclient = new mnet_xmlrpc_client();
		$rpcclient->set_method('blocks/use_stats/rpclib.php/use_stats_rpc_get_stats');
		$caller->username = $USER->username;
		$caller->remoteuserhostroot = $userhostroot;
		$caller->remotehostroot = $CFG->wwwroot;
		$rpcclient->add_param($caller, 'struct'); // caller user
		$rpcclient->add_param($targetuser, 'string');
	    $rpcclient->add_param($wherewwwroot, 'string');
	    // $rpcclient->add_param($courseidfield, 'string');
	    // $rpcclient->add_param($courseidentifier, 'string');
	    $rpcclient->add_param($statsscope, 'string');
	    $rpcclient->add_param($timefrom, 'int');
	
        $mnet_host = new mnet_peer();
        $mnet_host->set_wwwroot($whereroot);
        if (!$rpcclient->send($mnet_host)){
            $extresponse->status = RPC_FAILURE;
            $extresponse->errors[] = 'REMOTE : '.implode("<br/>\n", $rpcclient->errors);        
            $extresponse->errors[] = json_encode($rpcclient);
			if ($json_response){
				return json_encode($extresponse);
			} else {
				return $extresponse;
			}
        }
    
        $response = json_decode($rpcclient->response);
        // debug_trace($rpcclient->response);
    
        if ($response->status == 100){
            $extresponse->message = "Remote Test Point : ".$response->teststatus;
			if ($json_response){
				return json_encode($extresponse);
			} else {
				return $extresponse;
			}
        }
        if ($response->status == 200){
            $extresponse->message = $response->message;
			if ($json_response){
				return json_encode($extresponse);
			} else {
				return $extresponse;
			}
        } else {
        	$extresponse->status = RPC_FAILURE;
		    $extresponse->errors[] = 'Remote application error : ';
		    $extresponse->errors[] = $response->errors;
			if ($json_response){
				return json_encode($extresponse);
			} else {
				return $extresponse;
			}
        }
    }

}

function use_stats_rpc_get_stats_wrapped($wrap) {
	// debug_trace("WRAP : ".json_encode($wrap));	
	return use_stats_rpc_get_stats(@$wrap['callinguser'], @$wrap['targetuser'], @$wrap['whereroot'], @$wrap['statsscope'], @$wrap['timefrom'], @$wrap['json_response']);
}

/**
* get a complete report of user scoring for a single user.
*
* @param array $callinguser
* @param string $targetuser 
* @param string $wherefrom
* // @param string $courseidfield 
* // @param string $courseidentifier 
* @param string $statsscope 
*/
function use_stats_rpc_get_scores($callinguser, $targetuser, $whereroot, $scorescope = 'notes/global', $courseidfield, $courseidentifier, $json_response = true){
	global $CFG, $USER, $DB;
	
	$extresponse = new stdclass;
	$extresponse->status = RPC_SUCCESS;
	$extresponse->errors[] = array();

	// Invoke local user and check his rights
	// debug_trace("checking calling user");	
	if ($auth_response = use_stats_invoke_local_user((array)$callinguser, array('block/use_stats:seesitedetails', 'block/use_stats:seecoursedetails'))){
		if ($json_response){
		    return $auth_response;
		} else {
			return json_decode($auth_response);
		}
	}

	if (empty($whereroot) || $whereroot == $CFG->wwwroot){
		// debug_trace("local get scores values for $targetuser in $wherewwwroot scoping $scorescope ");

		// Getting remote_course definition
		switch($courseidfield){
			case 'id':
				$course = $DB->get_record('course', array('id' => $courseidentifier));
				break;
			case 'shortname':
				$course = $DB->get_record('course', array('shortname' => $courseidentifier));
				break;
			case 'idnumber':
				$course = $DB->get_record('course', array('idnumber' => $courseidentifier));
				break;		
		}
		
		if (!$course){
			$extresponse->status = RPC_FAILURE_RECORD;
			$extresponse->errors[] = 'Unkown course.';
			if ($json_response){
	    		return json_encode($extresponse);
	    	} else {
	    		return $extresponse;
	    	}
		}

		if (!$targetuser = $DB->get_record('user', array('username' => $targetuser))){
			$extresponse->status = RPC_FAILURE_RECORD;
			$extresponse->errors[] = 'Target user does not exist.';
			if ($json_response){
	    		return json_encode($extresponse);
	    	} else {
	    		return $extresponse;
	    	}
		}
		
		$data .= "\t<USERNAME>{$targetuser->username}</USERNAME>\n";
		$data .= "\t<FIRSTNAME>{$targetuser->firstname}</FIRSTNAME>\n";
		$data .= "\t<LASTNAME>{$targetuser->idnumber}</LASTNAME>\n";

        if ($statsscope == 'notes/global'){
    		$gradeitem = $DB->get_record('grade_items', array('itemtype' => 'course', 'courseid' => $course->id));
        	$grade = $DB->get_record('grade_grades', array('itemid' => $gradeitem->id));
			$message = "<USER>\n$data\n</USER>";
			$message .= "<SCORE>$grade->rawgrade</SCORE>";
       	} else {
       		$message = "<ERROR>Not implemented</ERROR>";
    		$extresponse->message = "<USER_SCORES>\n{$message}\n</USER_SCORES>";
       	}

		$extresponse->status = RPC_SUCCESS;

		// debug_trace('output built '.$extresponse->message);
				
		if ($json_response){
    		return json_encode($extresponse);
    	} else {
    		return $extresponse;
    	}
	} else {
		// debug_trace("remote source process : $wherewwwroot <> $CFG->wwwroot");	
		// Make remote call
	    $userhostroot = $DB->get_field('mnet_host', 'wwwroot', array('id' => $USER->mnethostid)); 
	        
        $rpcclient = new mnet_xmlrpc_client();
		$rpcclient->set_method('blocks/use_stats/rpclib.php/use_stats_rpc_get_scores');
		$caller->username = $USER->username;
		$caller->remoteuserhostroot = $userhostroot;
		$caller->remotehostroot = $CFG->wwwroot;
		$rpcclient->add_param($caller, 'struct'); // caller user
		$rpcclient->add_param($targetuser, 'string');
	    $rpcclient->add_param($whereroot, 'string');
	    $rpcclient->add_param($statsscope, 'string');
	    $rpcclient->add_param($courseidfield, 'string');
	    $rpcclient->add_param($courseidentifier, 'string');
	
        $mnet_host = new mnet_peer();
        $mnet_host->set_wwwroot($whereroot);
        if (!$rpcclient->send($mnet_host)){
            $extresponse->status = RPC_FAILURE;
            $extresponse->errors[] = 'REMOTE : '.implode("<br/>\n", $rpcclient->errors);        
            $extresponse->errors[] = json_encode($rpcclient);
			if ($json_response){
				return json_encode($extresponse);
			} else {
				return $extresponse;
			}
        }
    
        $response = json_decode($rpcclient->response);
    
        if ($response->status == 200){
            $extresponse->message = $response->message;
			if ($json_response){
				return json_encode($extresponse);
			} else {
				return $extresponse;
			}
        } else {
        	$extresponse->status = RPC_FAILURE;
		    $extresponse->errors[] = 'Remote application error : ';
		    $extresponse->errors[] = $response->errors;
			if ($json_response){
				return json_encode($extresponse);
			} else {
				return $extresponse;
			}
        }
    }
}

function use_stats_rpc_get_scores_wrapped($wrap) {
	// debug_trace("WRAP : ".json_encode($wrap));	
	return use_stats_rpc_get_scores(@$wrap['callinguser'], @$wrap['targetuser'], @$wrap['whereroot'], @$wrap['scorescope'], @$wrap['courseidfield'], @$wrap['courseidentifier'], @$wrap['json_response']);
}

?>