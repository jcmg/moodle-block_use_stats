<?php


/*
 * Moodle - Modular Object-Oriented Dynamic Learning Environment
 *          http://moodle.org
 * Copyright (C) 1999 onwards Martin Dougiamas  http://dougiamas.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @package    block-use-stats
 * @category   blocks
 * @author     Valery Fremaux <valery.fremaux@club-internet.fr>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL
 * @copyright  (C) 1999 onwards Martin Dougiamas  http://dougiamas.com
 *
 */

    /**
    * Requires and includes
    */
    require('../../config.php');
    require_once($CFG->dirroot.'/blocks/use_stats/locallib.php');
	require_once($CFG->dirroot . '/user/profile/lib.php');


    $courseid    = required_param('course', PARAM_INT);
    $userid    = required_param('userid', PARAM_INT);
    $id        = required_param('id', PARAM_INT); // ID of the calling use_stat block
    $fromwhen  = optional_param('ts_from', $CFG->block_use_stats_fromwhen, PARAM_INT);
    $towhen    = optional_param('ts_to', time(), PARAM_INT);
    $onlycourse    = optional_param('restrict', false, PARAM_BOOL);

    require_login($courseid);

	if ($COURSE->id > SITEID){
	    $returnurl = $CFG->wwwroot.'/course/view.php?id='.$COURSE->id;
	} else {
	    $returnurl = $CFG->wwwroot;
	}

    $blockcontext = get_context_instance(CONTEXT_BLOCK, $id);
    $coursecontext = get_context_instance(CONTEXT_COURSE, $COURSE->id);
    
    // check for capability to view user details and resolve
	$cansee = false;
	if (has_capability('block/use_stats:seesitedetails', $blockcontext)){
		$cansee = true;
	}
    else if ($USER->id != $userid){
    	if (has_capability('block/use_stats:seegroupdetails', $blockcontext)){
    		// if not in a group of mine, is an error
    		$mygroups = groups_get_user_groups($COURSE->id);
    		$groups = array();
    		foreach($mygroups as $grouping){
    			$groups = $groups + $grouping;
    		}
    		if (!empty($groups)){
    			foreach(array_keys($groups) as $groupid){
					if (groups_is_member($groupid)){
						$cansee = true;
						break;
					}
    			}
    		}
	    }

    	if (has_capability('block/use_stats:seecoursedetails', $blockcontext)){
    		// if not user in current course of mine, is an error
    		if (has_capability('moodle/course:view', $coursecontext, $userid)){
		    	$cansee = true;
		    }
	    }
	
		// final resolution
    } else {
        if (!has_capability('block/use_stats:seeowndetails', $blockcontext)){
        	$cansee = false;
        }
    }

	if (!$cansee){
    	print_error('notallowed', 'block_use_stats');
	}

	$user = $DB->get_record('user', array('id' => $userid), 'id,firstname,lastname,picture,imagealt,email');

	$PAGE->set_title(get_string('modulename', 'block_use_stats'));
	$PAGE->set_heading('');
	$PAGE->set_focuscontrol('');
	$PAGE->set_cacheable(true);
	$PAGE->set_button('');
	$PAGE->set_url($CFG->wwwroot.'/blocks/use_stats/detail.php');
	$PAGE->set_headingmenu('');
	$PAGE->navbar->add(get_string('blockname', 'block_use_stats'));
	$PAGE->navbar->add(fullname($user));
	echo $OUTPUT->header(); 
   
    $daystocompilelogs = $fromwhen * DAYSECS;
    $timefrom = $towhen - $daystocompilelogs;

	echo '<table class="list" summary=""><tr><td>';
	echo $OUTPUT->user_picture($user, array('size'=> 100));
	echo '</td><td>';
	echo '<h2><a href="'.$CFG->wwwroot.'/user/view.php?id='.$user->id.'">'.fullname($user).'</a></h2>';
	echo '<table class="list" summary="" width="100%">';
	profile_display_fields($user->id);
	echo '</table>';
	echo '</td></tr></table>';

    $logs = use_stats_extract_logs($timefrom, $towhen, $userid);
    
    // log aggregation function

    $aggregate = use_stats_aggregate_logs($logs, 'module');
    
    $dimensionitemstr = get_string('dimensionitem', 'block_use_stats');
    $timestr = get_string('timeelapsed', 'block_use_stats');
    $eventsstr = get_string('eventscount', 'block_use_stats');

	$table = new html_table();
    $table->head = array("<b>$dimensionitemstr</b>", "<b>$timestr</b>", "<b>$eventsstr</b>");
    $table->width = '100%';
    $table->size = array('70%', '30%');
    $table->align = array('left', 'left');
    foreach($aggregate as $module => $moduleset){
        $table->data[] = array("<b>$module</b>", '');
        foreach($moduleset as $key => $value){
            $cm = $DB->get_record('course_modules', array('id' => $key));
            if ($cm){
                $module = $DB->get_record('modules', array('id' => $cm->module));
                $modrec = $DB->get_record($module->name, array('id' => $cm->instance));
                $table->data[] = array($modrec->name, format_time($value->elapsed), $value->events);
            } else {
                $table->data[] = array('', format_time($value->elapsed));
            }
        }
    }

    if (!empty($table->data)){
        echo html_writer::table($table);
    } else {
        notice(get_string('errornorecords', 'block_use_stats'), $returnurl);
    }

    print_continue($returnurl);

    $OUTPUT->footer();            

	function print_row($left, $right) {
	    echo "\n<tr><td class=\"label c0\">$left</td><td class=\"info c1\">$right</td></tr>\n";
	}
?>