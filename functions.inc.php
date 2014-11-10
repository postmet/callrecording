<?php
// vim: set ai ts=4 sw=4 ft=php:
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
//	License for all code of this FreePBX module can be found in the license file inside the module directory
//	Copyright 2013 Schmooze Com Inc.
//
/* TODO:
 *
 * - Add outbound routes force recording (see pinssets for example similar code
 * - Move Extension Recording sections from core to here and add as hook
 *   see languages for similar code to implement
 * - Move the common macros from core to here
 * - Make functionality in other modules conditional on this stuff being here or if not
 *   overly complex, maybe move some of their functionality into hooks provdied from here
 */
function callrecording_destinations() {
	global $module_page;

	// it makes no sense to point at another queueprio (and it can be an infinite loop)
	if ($module_page == 'callrecording') {
		return false;
	}

	// return an associative array with destination and description
	foreach (callrecording_list() as $row) {
		$extens[] = array('destination' => 'ext-callrecording,' . $row['callrecording_id'] . ',1', 'description' => $row['description']);
	}
	return isset($extens)?$extens:null;
}

function callrecording_destination_popovers() {
	global $module_page;
	if ($module_page != 'callrecording') {
		$ret['callrecording'] = 'Call Recording';
	} else {
		$ret = array();
	}
	return $ret;
}

function callrecording_getdest($exten) {
	return array('ext-callrecording,'.$exten.',1');
}

function callrecording_getdestinfo($dest) {
	global $active_modules;

	if (substr(trim($dest),0,14) == 'ext-callrecording,') {
		$exten = explode(',',$dest);
		$exten = $exten[1];
		$thisexten = callrecording_get($exten);
		if (empty($thisexten)) {
			return array();
		} else {
			$type = isset($active_modules['callrecording']['type'])?$active_modules['callrecording']['type']:'setup';
			return array('description' => sprintf(_("Call Recording: %s"),$thisexten['description']),
				'edit_url' => 'config.php?display=callrecording&type='.$type.'&extdisplay='.urlencode($exten),
			);
		}
	} else {
		return false;
	}
}

function callrecording_get_config($engine) {
	global $ext;
	switch ($engine) {
	case 'asterisk':
		$context = 'ext-callrecording';
		foreach (callrecording_list() as $row) {
			$ext->add($context, $row['callrecording_id'], '', new ext_noop_trace('Call Recording: [' . $row['callrecording_mode'] . '] Event'));
			switch ($row['callrecording_mode']) {
			case 'force':
				$ext->add($context, $row['callrecording_id'], '', new ext_gosub('1','s','sub-record-check','generic,${FROM_DID},always'));
				break;
			case 'delayed':
				$ext->add($context, $row['callrecording_id'], '', new ext_set('__REC_POLICY_MODE','always'));
				break;
			case 'never':
				$ext->add($context, $row['callrecording_id'], '', new ext_gosub('1','s','sub-record-check', 'generic,${FROM_DID},never'));
				$ext->add($context, $row['callrecording_id'], '', new ext_set('__REC_POLICY_MODE','never'));
				break;
			default: // allowed
				$ext->add($context, $row['callrecording_id'], '', new ext_execif('$["${REC_POLICY_MODE}"="never"]','Set','__REC_POLICY_MODE='));
				break;
			}
			$ext->add($context, $row['callrecording_id'], '', new ext_goto($row['dest']));
		}

		/*
		 * This aborts and removes any call recordings that have been made on the current call.
		 */
		$context = 'sub-record-cancel';
		$exten = 's';

		$ext->add($context, $exten, '', new ext_set('__REC_POLICY_MODE', '${REC_POLICY_MODE_SAVE}'));
		$ext->add($context, $exten, '', new ext_execif('$["${REC_STATUS}"!="RECORDING"]','Return'));
		$ext->add($context, $exten, '', new ext_stopmixmonitor());
		// This probably never worked. It's MIXMONITOR_FILENAME.
		$ext->add($context, $exten, '', new ext_set('MON_BASE','${IF($[${LEN(${MIXMON_DIR})}]?${MIXMON_DIR}:${ASTSPOOLDIR}/monitor/)}${YEAR}/${MONTH}/${DAY}/'));
		$ext->add($context, $exten, '', new ext_execif('$[${LEN(${CALLFILENAME})} & ${STAT(f,${MON_BASE}${CALLFILENAME}.${MON_FMT})}]','System','rm -f ${MON_BASE}${CALLFILENAME}.${MON_FMT}'));
		$ext->add($context, $exten, '', new ext_set('__CALLFILENAME',''));
		$ext->add($context, $exten, '', new ext_set('CDR(recordingfile)',''));
		$ext->add($context, $exten, '', new ext_return(''));

		/*
		; ARG1: type
		;       exten, out, rg, q, conf
		; ARG2: called_exten
		; ARG3: action (if we know it)
		;       always, never (note dontcare only applies to extensions, group, etc. must specify yes/no)
		;
		*/


		$context = 'sub-record-check';
		$exten = 's';

		$ext->add($context, $exten, '', new ext_gotoif('$[${LEN(${REC_STATUS})}]', 'initialized'));
		$ext->add($context, $exten, '', new ext_set('__REC_STATUS','INITIALIZED'));
		$ext->add($context, $exten, '', new ext_set('NOW','${EPOCH}'));
		$ext->add($context, $exten, '', new ext_set('__DAY','${STRFTIME(${NOW},,%d)}'));
		$ext->add($context, $exten, '', new ext_set('__MONTH','${STRFTIME(${NOW},,%m)}'));
		$ext->add($context, $exten, '', new ext_set('__YEAR','${STRFTIME(${NOW},,%Y)}'));
		$ext->add($context, $exten, '', new ext_set('__TIMESTR','${YEAR}${MONTH}${DAY}-${STRFTIME(${NOW},,%H%M%S)}'));
		$ext->add($context, $exten, '', new ext_set('__FROMEXTEN','${IF($[${LEN(${AMPUSER})}]?${AMPUSER}:${IF($[${LEN(${REALCALLERIDNUM})}]?${REALCALLERIDNUM}:unknown)})}'));
		// MON_FMT is the format that MixMon knows about - we're only caring about gsm here (WAV) which non-case-sensitve filesystems
		// will confuse with sln (wav).  Yes, I know THIS isn't case sensitive, but wait till you copy it to a windows box. Then
		// you're sad.
		$ext->add($context, $exten, '', new ext_set('__MON_FMT','${IF($["${MIXMON_FORMAT}"="WAV"]?wav49:${MIXMON_FORMAT})}'));
		$ext->add($context, $exten, 'initialized', new ext_noop('Recordings initalized'));

		// Backup our current setting, just in case we need to roll back to it.
		$ext->add($context, $exten, '', new ext_set('REC_POLICY_MODE_SAVE','${REC_POLICY_MODE}'));

		// Figure out what this blind transfer stuff is about later.
		/*
		$ext->add($context, $exten, '', new ext_gotoif('$["${BLINDTRANSFER}" = ""]', 'check'));
		$ext->add($context, $exten, '', new ext_resetcdr(''));
		$ext->add($context, $exten, '', new ext_gotoif('$["${REC_STATUS}" != "RECORDING"]', 'check'));
		$ext->add($context, $exten, '', new ext_set('AUDIOHOOK_INHERIT(MixMonitor)','yes'));
		$ext->add($context, $exten, '', new ext_mixmonitor('${MIXMON_DIR}${YEAR}/${MONTH}/${DAY}/${CALLFILENAME}.${MIXMON_FORMAT}','a','${MIXMON_POST}'));
		 */

		// If we weren't given a type, error. This is a bug.
		$ext->add($context, $exten, 'next', new ext_gotoif('$[${LEN(${ARG1})}]','checkaction'));
		$ext->add($context, $exten, 'recorderror', new ext_playback('something-terribly-wrong,error'));
		$ext->add($context, $exten, '', new ext_hangup());

		// If we don't have a current mode, and we were explicitly given a command, we can set our current mode
		// to that. This is what we CURRENTLY think we should be doing. This may change if it's an exten.
		$ext->add($context, $exten, 'checkaction', new ext_execif('$["${REC_POLICY_MODE}"="" & "${ARG3}"!=""]','Set','__REC_POLICY_MODE=${TOUPPER(${ARG3})}'));

		// Now jump to the dialplan handler. If it doesn't exist, do the generic test (rg, force, q use these).
		$ext->add($context, $exten, '',  new ext_gotoif('$[${DIALPLAN_EXISTS('.$context.',${ARG1})]', $context.',${ARG1},1'));

		// Generic check
		$ext->add($context, $exten, '', new ext_noop('Generic ${ARG1} Recording Check - ${EXTEN} ${ARG2}'));
		$ext->add($context, $exten, '', new ext_gosub('1', 'recordcheck',false,'${ARG3},${EXTEN},${REC_POLICY_MODE},${FROMEXTEN}'));
		$ext->add($context, $exten, '', new ext_return(''));

		// Check to see what should be done, based on the request type.
		$exten = 'recordcheck';
		$ext->add($context, $exten, '', new ext_noop('Starting recording check against ${ARG1}'));
		$ext->add($context, $exten, '', new ext_goto('${ARG1}'));

		// Don't care - just return, nothing's changed.
		$ext->add($context, $exten, 'dontcare', new ext_return(''));

		// ALWAYS: Extensions used 'Always' and 'Never'. Alias 'Always' to 'Force'
		$ext->add($context, $exten, 'always', new ext_noop('Detected legacy "always" entry. Mapping to "force"'));
		// FORCE: Always start recording, if you're not already.
		$ext->add($context, $exten, 'force', new ext_set('__REC_POLICY_MODE', 'FORCE'));
		$ext->add($context, $exten, '', new ext_gotoif('$["${REC_STATUS}"!="RECORDING"]', 'startrec'));
		$ext->add($context, $exten, '', new ext_return(''));

		// YES: Start recording if we haven't been told otherwise.
		$ext->add($context, $exten, 'yes', new ext_execif('$["${REC_POLICY_MODE}" = "NEVER" | "${REC_POLICY_MODE}" = "NO" | "${REC_STATUS}" = "RECORDING"]', 'Return'));
		$ext->add($context, $exten, '', new ext_set('__REC_POLICY_MODE', 'YES'));
		$ext->add($context, $exten, '', new ext_goto('startrec'));

		// NO: Don't record this. This won't STOP a recording that's already happening though.
		$ext->add($context, $exten, 'no', new ext_set('__REC_POLICY_MODE', 'NO'));
		$ext->add($context, $exten, '', new ext_return(''));

		// NEVER: Don't record this call, and stop recording if we are.
		$ext->add($context, $exten, 'never', new ext_set('__REC_STATUS', 'NEVER'));
		$ext->add($context, $exten, '', new ext_gotoif('$["${REC_STATUS}" = "RECORDING"]', 'stoprec'));
		$ext->add($context, $exten, '', new ext_return(''));

		// Start recording if requested
		$ext->add($context, $exten, 'startrec', new ext_noop('Starting recording: ${ARG2}, ${ARG3}'));
		$ext->add($context, $exten, '', new ext_set('AUDIOHOOK_INHERIT(MixMonitor)','yes'));
		$ext->add($context, $exten, '', new ext_set('__CALLFILENAME','${ARG2}-${ARG3}-${FROMEXTEN}-${TIMESTR}-${UNIQUEID}'));
		$ext->add($context, $exten, '', new ext_mixmonitor('${MIXMON_DIR}${YEAR}/${MONTH}/${DAY}/${CALLFILENAME}.${MON_FMT}','a','${MIXMON_POST}'));
		$ext->add($context, $exten, '', new ext_set('__REC_STATUS','RECORDING'));
		$ext->add($context, $exten, '', new ext_set('CDR(recordingfile)','${CALLFILENAME}.${MON_FMT}'));
		$ext->add($context, $exten, '', new ext_return(''));

		// Stop recording if requested.
		$ext->add($context, $exten, 'stoprec', new ext_noop('Stopping recording: ${ARG2}, ${ARG3}'));
		$ext->add($context, $exten, '', new ext_set('__REC_STATUS','STOPPED'));
		$ext->add($context, $exten, '', new ext_stopmixmonitor());
		$ext->add($context, $exten, '', new ext_return(''));

		// RECORDING POLICY LOGIC HERE
		//
		// OUTBOUND ROUTES
		$exten = 'out';
		$ext->add($context, $exten, '', new ext_noop('Outbound Recording Check from ${FROMEXTEN} to ${ARG2}'));

		// The Extension is first in the chain. 
		$ext->add($context, $exten, '', new ext_set('RECMODE', '${DB(AMPUSER/${FROMEXTEN}/recording/out/external)}'));

		// If the route is set to DONTCARE, we don't care. 
		$ext->add($context, $exten, '', new ext_execif('$["${ARG3}" = "dontcare"]', 'Goto', 'fin'));

		// If the route is set to FORCE or NEVER, that wins. 
		$ext->add($context, $exten, '', new ext_execif('$["${ARG3}" = "never" | "${ARG3}" = "force"]', 'Set', 'RECMODE=${ARG3}'));
		$ext->add($context, $exten, '', new ext_execif('$["${ARG3}" = "never" | "${ARG3}" = "force"]', 'Goto', 'fin'));

		// If the EXTENSION is set to FORCE or NEVER, that now beats a yes or no from the route.
		$ext->add($context, $exten, '', new ext_execif('$["${RECMODE}" = "never" | "${RECMODE}" = "force"]', 'Goto', 'fin'));

		// The route is set to yes or no.
		$ext->add($context, $exten, '', new ext_set('RECMODE', '${ARG3}'));

		$ext->add($context, $exten, 'fin', new ext_gosub('1', 'recordcheck', false, '${RECMODE},${ARG2},${FROMEXTEN}'));
		$ext->add($context, $exten, '', new ext_return(''));

		// CALLS BETWEEN EXTENSIONS
		$exten = 'exten';
		$ext->add($context, $exten, '', new ext_noop('Exten Recording Check between ${FROMEXTEN} and ${ARG2}'));
		$ext->add($context, $exten, '', new ext_set('CALLTYPE','${IF($[${LEN(${FROM_DID})}]?external:internal)}'));

		$ext->add($context, $exten, '', new ext_set('CALLEE','${DB(AMPUSER/${ARG2}/recording/in/${CALLTYPE})}'));
		// Make sure CALLEE isn't empty. Bad astdb entry?
		$ext->add($context, $exten, '', new ext_execif('$[!${LEN(${CALLEE})}]','Set', 'CALLEE=dontcare'));

		// Is it an external call? It's not going to be caller.
		$ext->add($context, $exten, '', new ext_gotoif('$["${CALLTYPE}"="external"]','callee'));

		// Does the callee care about it? If not, we let the caller choose.
		$ext->add($context, $exten, '', new ext_gotoif('$["${CALLEE}"="dontcare"]','caller'));

		// It does. We may have a priority battle on our hands.
		$ext->add($context, $exten, '', new ext_execif('$[${LEN(${DB(AMPUSER/${FROMEXTEN}/recording/priority)})}]','Set','CALLER_PRI=${DB(AMPUSER/${FROMEXTEN}/recording/priority)}','Set','CALLER_PRI=0'));
		$ext->add($context, $exten, '', new ext_execif('$[${LEN(${DB(AMPUSER/${ARG2}/recording/priority)})}]','Set','CALLEE_PRI=${DB(AMPUSER/${ARG2}/recording/priority)}','Set','CALLEE_PRI=0'));

		// Who wins?
		$ext->add($context, $exten, '', new ext_gotoif('$["${CALLER_PRI}"="${CALLEE_PRI}"]', '${REC_POLICY}','${IF($[${CALLER_PRI}>${CALLEE_PRI}]?caller:callee)}'));

		// Recpient of the call wins. We've already sanity checked them above, so we can use the CALLEE var.
		$ext->add($context, $exten, 'callee', new ext_gosub('1', 'recordcheck', false, '${CALLEE},${ARG2},${FROMEXTEN}'));
		$ext->add($context, $exten, '', new ext_return(''));

		// Originator of the call wins. Always out/internal.
		$ext->add($context, $exten, 'caller', new ext_set('RECMODE','${DB(AMPUSER/${FROMEXTEN}/recording/out/internal)}'));
		$ext->add($context, $exten, '', new ext_execif('$[!${LEN(${RECMODE})}]','Set', 'RECMODE=dontcare'));
		$ext->add($context, $exten, '', new ext_gosub('1', 'recordcheck', false, '${RECMODE},${ARG2},${FROMEXTEN}'));
		$ext->add($context, $exten, '', new ext_return(''));

		// For confernecing we will set the variables (since the actual meetme does the recording) in case an option were to exist to do on-demand recording
		// of the conference which doesn't currenly seem like it is supported but might.
		//
		$exten = 'conf';
		$ext->add($context, $exten, '', new ext_noop('Recording Check ${EXTEN} ${ARG2}'));
		$ext->add($context, $exten, '', new ext_gosub('1','recconf',false,'${EXTEN},${ARG2},${ARG2}'));
		$ext->add($context, $exten, '', new ext_return(''));

		$exten = 'page';
		$ext->add($context, $exten, '', new ext_noop('Recording Check ${EXTEN} ${ARG2}'));
		$ext->add($context, $exten, '', new ext_gosubif('$["${REC_POLICY_MODE}"="always"]','recconf,1',false,'${EXTEN},${ARG2},${FROMEXTEN}'));
		$ext->add($context, $exten, '', new ext_return(''));

		/* Queue Recording Section */
		$exten = 'recq';
		$ext->add($context, $exten, '', new ext_noop('Setting up recording: ${ARG1}, ${ARG2}, ${ARG3}'));
		$ext->add($context, $exten, '', new ext_set('AUDIOHOOK_INHERIT(MixMonitor)','yes'));
		$ext->add($context, $exten, '', new ext_set('MONITOR_FILENAME','${MIXMON_DIR}${YEAR}/${MONTH}/${DAY}/${CALLFILENAME}'));
		$ext->add($context, $exten, '', new ext_mixmonitor('${MONITOR_FILENAME}.${MON_FMT}','${MONITOR_OPTIONS}','${MIXMON_POST}'));
		$ext->add($context, $exten, '', new ext_set('__REC_STATUS','RECORDING'));
		$ext->add($context, $exten, '', new ext_set('CDR(recordingfile)','${CALLFILENAME}.${MON_FMT}'));
		$ext->add($context, $exten, '', new ext_return(''));

		$exten = 'recconf';
		$ext->add($context, $exten, '', new ext_noop('Setting up recording: ${ARG1}, ${ARG2}, ${ARG3}'));
		if (FreePBX::Config()->get('ASTCONFAPP')) {
			$ext->add($context, $exten, '', new ext_set('__CALLFILENAME','${IF($[${CONFBRIDGE_INFO(parties,${ARG2})}]?${DB(RECCONF/${ARG2})}:${ARG1}-${ARG2}-${ARG3}-${TIMESTR}-${UNIQUEID})}'));
			$ext->add($context, $exten, '', new ext_execif('$[!${CONFBRIDGE_INFO(parties,${ARG2})}]','Set','DB(RECCONF/${ARG2})=${CALLFILENAME}'));
			$ext->add($context, $exten, '', new ext_set('CONFBRIDGE(bridge,record_file)','${MIXMON_DIR}${YEAR}/${MONTH}/${DAY}/${CALLFILENAME}.${MON_FMT}'));
		} else {
			// Conferencing must set the path to MIXMON_DIR explicitly since unlike other parts of Asterisk
			// Meetme does not default to the defined monitor directory.
			//
			$ext->add($context, $exten, '', new ext_set('__CALLFILENAME','${IF($[${MEETME_INFO(parties,${ARG2})}]?${DB(RECCONF/${ARG2})}:${ARG1}-${ARG2}-${ARG3}-${TIMESTR}-${UNIQUEID})}'));
			$ext->add($context, $exten, '', new ext_execif('$[!${MEETME_INFO(parties,${ARG2})}]','Set','DB(RECCONF/${ARG2})=${CALLFILENAME}'));
			$ext->add($context, $exten, '', new ext_set('MEETME_RECORDINGFILE','${IF($[${LEN(${MIXMON_DIR})}]?${MIXMON_DIR}:${ASTSPOOLDIR}/monitor/)}${YEAR}/${MONTH}/${DAY}/${CALLFILENAME}'));
			$ext->add($context, $exten, '', new ext_set('MEETME_RECORDINGFORMAT','${MON_FMT}'));
		}
		$ext->add($context, $exten, '', new ext_execif('$["${REC_POLICY_MODE}"!="always"]','Return'));
		if (FreePBX::Config()->get('ASTCONFAPP') == 'app_confbridge') {
			$ext->add($context, $exten, '', new ext_set('CONFBRIDGE(bridge,record_conference)','yes'));
		}
		$ext->add($context, $exten, '', new ext_set('__REC_STATUS','RECORDING'));
		$ext->add($context, $exten, '', new ext_set('CDR(recordingfile)','${CALLFILENAME}.${MON_FMT}'));
		$ext->add($context, $exten, '', new ext_return(''));

		/* macro-one-touch-record */

		$context = 'macro-one-touch-record';
		$exten = 's';

		$ext->add($context, $exten, '', new ext_set('ONETOUCH_REC_SCRIPT_STATUS', ''));
		$ext->add($context, $exten, '', new ext_system($amp_conf['ASTVARLIBDIR'] . '/bin/one_touch_record.php ${CHANNEL(name)}'));
		$ext->add($context, $exten, '', new ext_noop('ONETOUCH_REC_SCRIPT_STATUS: [${ONETOUCH_REC_SCRIPT_STATUS}]'));
		$ext->add($context, $exten, '', new ext_noop_trace('ONETOUCH_REC: [${ONETOUCH_REC}] REC_STATUS: [${REC_STATUS}]'));
		$ext->add($context, $exten, '', new ext_noop_trace('ONETOUCH_RECFILE: [${ONETOUCH_RECFILE}] CDR(recordingfile): [${CDR(recordingfile)}]'));
		$ext->add($context, $exten, '', new ext_execif('$["${ONETOUCH_REC}"="RECORDING"]','Playback','beep'));
		$ext->add($context, $exten, '', new ext_execif('$["${ONETOUCH_REC}"="PAUSED"]','Playback','beep&beep'));
		$ext->add($context, $exten, '', new ext_macroexit());

	}
}

function callrecording_hookGet_config($engine) {
	global $ext;
	global $version;
	switch($engine) {
	case "asterisk":

		// Inbound Routes Forced Recordings
		$routes=callrecording_display_get('did');
		foreach($routes as $current => $route){
			if($route['extension']=='' && $route['cidnum']){//callerID only
				$extension='s/'.$route['cidnum'];
				$context=$route['pricid']?'ext-did-0001':'ext-did-0002';
			}else{
				if(($route['extension'] && $route['cidnum'])||($route['extension']=='' && $route['cidnum']=='')){//callerid+did / any/any
					$context='ext-did-0001';
				}else{//did only
					$context='ext-did-0002';
				}
				$extension=($route['extension']!=''?$route['extension']:'s').($route['cidnum']==''?'':'/'.$route['cidnum']);
			}
			$ext->splice($context, $extension, 1, new ext_gosub('1','s','sub-record-check','exten,${EXTEN},'.$route['callrecording']));
		}

		// Outbound Routes Forced Recordings
		$routes=callrecording_display_get('routing');
		// get the place to splice
		foreach($routes as $current => $route){
			$context = 'outrt-'.$route['route_id'];
			$patterns = core_routing_getroutepatternsbyid($route['route_id']);
			foreach ($patterns as $pattern) {
				$fpattern = core_routing_formatpattern($pattern);
				$extension = $fpattern['dial_pattern'];
				$ext->splice($context, $extension, 1, new ext_gosub('1','s','sub-record-check','out,${EXTEN},'.$route['callrecording']));
			}
		}
		break;
	}
}

/**  Get a list of all callrecording
 */
function callrecording_list() {
	global $db;
	$sql = "SELECT callrecording_id, description, callrecording_mode, dest FROM callrecording ORDER BY description ";
	$results = $db->getAll($sql, DB_FETCHMODE_ASSOC);
	if(DB::IsError($results)) {
		die_freepbx($results->getMessage()."<br><br>Error selecting from callrecording");	
	}
	return $results;
}

function callrecording_get($callrecording_id) {
	global $db;
	$sql = "SELECT callrecording_id, description, callrecording_mode, dest FROM callrecording WHERE callrecording_id = ".$db->escapeSimple($callrecording_id);
	$row = $db->getRow($sql, DB_FETCHMODE_ASSOC);
	if(DB::IsError($row)) {
		die_freepbx($row->getMessage()."<br><br>Error selecting row from callrecording");	
	}

	return $row;
}

function callrecording_add($description, $callrecording_mode, $dest) {
	global $db;
	global $amp_conf;
	$sql = "INSERT INTO callrecording (description, callrecording_mode, dest) VALUES (".
		"'".$db->escapeSimple($description)."', ".
		"'".$db->escapeSimple($callrecording_mode)."', ".
		"'".$db->escapeSimple($dest)."')";
	$result = $db->query($sql);
	if(DB::IsError($result)) {
		die_freepbx($result->getMessage().$sql);
	}
	if(method_exists($db,'insert_id')) {
		$id = $db->insert_id();
	} else {
		$id = $amp_conf["AMPDBENGINE"] == "sqlite3" ? sqlite_last_insert_rowid($db->connection) : mysql_insert_id($db->connection);
	}
	return($id);
}

function callrecording_delete($callrecording_id) {
	global $db;
	$sql = "DELETE FROM callrecording WHERE callrecording_id = ".$db->escapeSimple($callrecording_id);
	$result = $db->query($sql);
	if(DB::IsError($result)) {
		die_freepbx($result->getMessage().$sql);
	}
}

function callrecording_edit($callrecording_id, $description, $callrecording_mode, $dest) { 
	global $db;
	$sql = "UPDATE callrecording SET ".
		"description = '".$db->escapeSimple($description)."', ".
		"callrecording_mode = '".$db->escapeSimple($callrecording_mode)."', ".
		"dest = '".$db->escapeSimple($dest)."' ".
		"WHERE callrecording_id = ".$db->escapeSimple($callrecording_id);
	$result = $db->query($sql);
	if(DB::IsError($result)) {
		die_freepbx($result->getMessage().$sql);
	}
}

function callrecording_hook_core($viewing_itemid, $target_menuid){

	switch ($target_menuid) {
	case 'did':
		$extension	= isset($_REQUEST['extension'])		? $_REQUEST['extension']	:'';
		$cidnum		= isset($_REQUEST['cidnum'])		? $_REQUEST['cidnum']		:'';
		$extdisplay	= isset($_REQUEST['extdisplay'])	? $_REQUEST['extdisplay']	:'';
		$action		= isset($_REQUEST['action'])		? $_REQUEST['action']		:'';
		$callrecording	= isset($_REQUEST['callrecording'])		? $_REQUEST['callrecording']		:'';
		//set $extension,$cidnum if we dont already have them
		if(!$extension && !$cidnum){
			$opts		= explode('/', $extdisplay);
			$extension	= $opts['0'];
			$cidnum		= isset($opts['1']) ? $opts['1'] : '';
		}else{
			$extension 	= $extension;
			$cidnum		= $cidnum;
		}

		//update if we have enough info
		if($action == 'edtIncoming' || ( $extension != '' || $cidnum != '') && $callrecording != ''){
			callrecording_display_update('did',$callrecording,$extension,$cidnum);
		}
		if($action=='delIncoming'){
			callrecording_display_delete('did',$extension,$cidnum);
		}
		$callrecording = callrecording_display_get($target_menuid, $extension,$cidnum);
		break;

	case 'routing':
		$route_id	= isset($_REQUEST['route_id']) ? $_REQUEST['route_id'] : (isset($_REQUEST['extdisplay']) ? $_REQUEST['extdisplay'] : '');
		if (!empty($_SESSION['callrecordingAddRoute'])) {
			$callrecording = $_SESSION['callrecordingAddRoute'];
		} else {
			$callrecording = callrecording_display_get($target_menuid, $route_id);
		}
		break;
	}
	$html = '';
	//if ($target_menuid == 'did'){
	if ($target_menuid == 'did' || $target_menuid == 'routing') {
		global $tabindex;
		if ($target_menuid == 'did') {
			$html.='<tr><td colspan="2"><h5>'._("Call Recording").'<hr></h5></td></tr>';
		}
		$html .= '<tr><td colspan=2><p>'._("Note that these options have changed in FreePBX 12.")." <a href='http://wiki.freepbx.org/display/F2/Call+Recording+walk+through'>"._("Please read the wiki on these changes.")."</a></p></td></tr>\n";
		$html.='<tr><td><a href="#" class="info">'._('Call Recording').'<span>'._("This sets the call recording behavior for calls coming into this DID. Please read the wiki for information on what these settings mean.").'</span></a>:</td>';
		$html .= '<td><span class="radioset">';
		// Fix any old options.
		if ($callrecording == "delayed") {
			$callrecording = "yes";
		}
		if ($callrecording == "") {
			$callrecording = "dontcare";
		}
		$options = array(_("Force") => "force", _("Yes") => "yes", _("Don't Care") => "dontcare", _("No") => "no", _("Never") => "never");
		foreach ($options as $disp => $name) {
			if ($callrecording == $name) {
				$checked = "checked";
			} else {
				$checked = "";
			}
			$html .= "<input type='radio' id='record_${name}' name='callrecording' value='$name' $checked><label for='record_${name}'>$disp</label>";
		}
		$html .= "</span></td>\n";
	}
	return $html;
}

function callrecording_hookProcess_core($viewing_itemid, $request) {
	switch ($request['display']) {
	case 'routing':
		$action = (isset($request['action']))?$request['action']:null;
		$route_id = $viewing_itemid;
		//dbug("got request for callrecording process for route: $route_id action: $action");
		if (isset($request['Submit']) ) {
			$action = (isset($action))?$action:'editroute';
		}

		// $action won't be set on the redirect but callrecordingAddRoute will be in the session
		//
		if (!$action && !empty($_SESSION['callrecordingAddRoute'])) {
			callrecording_adjustroute($route_id,'delayed_insert_route',$_SESSION['callrecordingAddRoute']);
			unset($_SESSION['callrecordingAddRoute']);
		} elseif ($action){
			callrecording_adjustroute($route_id,$action,$request['callrecording']);
		}
		break;
	}
}

function callrecording_adjustroute($route_id,$action,$callrecording='') {
	global $db;
	$dispname = 'routing';
	$route_id = $db->escapeSimple($route_id);
	$callrecording = $db->escapeSimple($callrecording);

	//dbug("in adjustroute with route_id: $route_id, action: $action, callrecording: $callrecording"); 
	switch ($action) {
	case 'delroute':
		callrecording_display_delete($dispname,$route_id);
		break;
		case 'addroute';
		if ($callrecording != '') {
			// we don't have the route_id yet, it hasn't been inserted yet :(, put it in the session 
			// and when returned it will be available on the redirect_standard
			$_SESSION['callrecordingAddRoute'] = $callrecording;
		}
		break;
		case 'delayed_insert_route';
		callrecording_display_update($dispname, $callrecording, $route_id);
		break;
		case 'editroute';
		//dbug("in editroute ready to insert dispnam: $dispname, route: $route_id, mode $callrecording");
		if ($callrecording != '') {
			callrecording_display_update($dispname, $callrecording, $route_id);
		} else {
			callrecording_display_delete($dispname,$route_id);
		}
		break;
	}
}

function callrecording_display_get($display, $extension=null,$cidnum=null){
	global $db;

	//dbug("display_get with display: $display, exten $extension, cid $cidnum", $_REQUEST);
	switch ($display) {
	case 'did':
		if($extension || $cidnum || (isset($_REQUEST['extdisplay']) && $_REQUEST['extdisplay']=='/') || (isset($_REQUEST['display']) && $_REQUEST['display']=='did')){
			$sql='SELECT callrecording FROM callrecording_module WHERE display = ? AND extension ';
			$sql .= $extension === null ? "IS NULL" : "= ?";
			$sql .= " AND cidnum ";
			$sql .= $cidnum === null ? "IS NULL" : "= ?";
			$params[] = $display;
			if ($extension !== null) {
				$params[] = $extension;
			}
			if ($cidnum !== null) {
				$params[] = $cidnum;
			}
			//dbug("executing getOne code: $sql", $params);
			$mode=$db->getOne($sql, $params);
			//$mode=$db->getOne($sql, array($display, $extension, $cidnum));
		}else{
			$sql="SELECT callrecording_module.*,incoming.pricid FROM callrecording_module, incoming WHERE callrecording_module.cidnum=incoming.cidnum AND callrecording_module.extension=incoming.extension AND callrecording_module.display = '$display'";
			$mode=$db->getAll($sql, DB_FETCHMODE_ASSOC);
		}
		break;
	case 'routing':
		if($extension) {
			$sql='SELECT callrecording FROM callrecording_module WHERE display = ? AND extension ';
			$sql .= $extension === null ? "IS NULL" : "= ?";
			$params[] = $display;
			if ($extension !== null) {
				$params[] = $extension;
			}
			//dbug("executing getOne code: $sql", $params);
			$mode=$db->getOne($sql, $params);
			//$mode=$db->getOne($sql, array($display, $extension, $cidnum));
		} else {
			$sql="SELECT extension as route_id, callrecording FROM callrecording_module WHERE display = '$display'";
			$mode=$db->getAll($sql, DB_FETCHMODE_ASSOC);
		}
		break;
	}
	return $mode;
}

function callrecording_display_update($display,$recording_code=null,$extension=null,$cidnum=null){
	global $db;
	$sql="DELETE FROM callrecording_module WHERE display = ? AND extension ";
	$sql .= $extension === null ? "IS NULL" : "= ?";
	$sql .= " AND cidnum ";
	$sql .= $cidnum === null ? "IS NULL" : "= ?";
	$params[] = $display;
	if ($extension !== null) {
		$params[] = $extension;
	}
	if ($cidnum !== null) {
		$params[] = $cidnum;
	}
	//dbug("executing delete code: $sql", $params);
	//$db->query($sql,array($display,$extension,$cidnum));
	$db->query($sql, $params);
	if(isset($recording_code) && $recording_code!=''){
		$sql="INSERT INTO callrecording_module (display,extension,cidnum,callrecording) VALUES (?, ?, ?,?)";
		$db->query($sql,array($display,$extension,$cidnum,$recording_code));
	};
}

//NULL is treated as a wildcard here. For example if we pass in a space, we 
//	only want the one with a space
function callrecording_display_delete($display,$extension=null,$cidnum=null){
	global $db;

	$sql="DELETE FROM callrecording_module WHERE display = ?";
	$data[] = $display;

	if ($extension !== null) {
		$sql .= " AND extension = ?";
		$data[] = $extension;
	}
	if ($cidnum !== null) {
		$sql .= " AND cidnum = ?";
		$data[] = $cidnum;
	}
	$db->query($sql,$data);
}

function callrecording_check_destinations($dest=true) {
	global $active_modules;

	$destlist = array();
	if (is_array($dest) && empty($dest)) {
		return $destlist;
	}
	$sql = "SELECT callrecording_id, dest, description FROM callrecording ";
	if ($dest !== true) {
		$sql .= "WHERE dest in ('".implode("','",$dest)."')";
	}
	$results = sql($sql,"getAll",DB_FETCHMODE_ASSOC);

	$type = isset($active_modules['callrecording']['type'])?$active_modules['callrecording']['type']:'setup';

	foreach ($results as $result) {
		$thisdest = $result['dest'];
		$thisid   = $result['callrecording_id'];
		$destlist[] = array(
			'dest' => $thisdest,
			'description' => sprintf(_("Call Recording: %s"),$result['description']),
			'edit_url' => 'config.php?display=callrecording&type='.$type.'&extdisplay='.urlencode($thisid),
		);
	}
	return $destlist;
}

function callrecording_change_destination($old_dest, $new_dest) {
	$sql = 'UPDATE callrecording SET dest = "' . $new_dest . '" WHERE dest = "' . $old_dest . '"';
	sql($sql, "query");
}
?>
