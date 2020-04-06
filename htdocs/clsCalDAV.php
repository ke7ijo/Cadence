<?php
require_once ("Hydrogen/libDebug.php");
class CalDAV {
	public static function uid() {
		/** Get a random string
		* @return string 32 hexadecimal characters
		*/
		return md5(uniqid(mt_rand(), true));
	}
	
	public static function PushAllReminders() {
		global $dds;
		$sql="SELECT id from " . DB::$reminder_table . " WHERE calendar_id is not null order by id";
		$dds->setSQL($sql);
		$keys=$dds->getDataset();
		for ($i=0; $i < count($keys); $i++) {
			CalDAV::PushReminderUpdate($keys[$i][0],true);
		}
	}
	
	public static function PushReminderUpdate($id, $force=false){
		global $dds;
		require_once ("caldav-client.php");
		require_once ("clsReminder.php");
		$sql="SELECT a.ruser as ruser, a.rpassword as rpassword, a.rhost as rhost, a.rport as rport, c.uid as cal_uid, r.etag as etag, r.uid as rem_uid, r.owner as owner ";
		$sql.=" FROM " . DB::$reminder_table . " r ";
		$sql.=" INNER JOIN " . DB::$caldav_cal_table ." c" ;
		$sql.="	ON r.calendar_id = c.id";
		$sql.=" INNER JOIN " . DB::$caldav_acct_table . " a ";
		$sql.="	ON c.remote_acct_id = a.id";
		$sql.=" WHERE r.id =" . $id	;
		debug (__FILE__ . ": PushReminderUpdate: SQL=" . $sql);
		$dds->setSQL($sql);
		debug (__FILE__ . ": PushReminderUpdate: SQL executed");
		if ($result_row = $dds->getNextRow("labelled")) {
			$uri="http://" . $result_row['rhost'] . ":" . $result_row['rport']. "/" . $result_row['ruser'] . "/";
			$calDAVClient = new CalDAVClient( $uri, $result_row["ruser"], $result_row["rpassword"], "dummy" );
			$reminder = new Reminder($id,$result_row["owner"]);
			$icalendar = $reminder->serialize();
			debug (__FILE__ . ": PushReminderUpdate: ICS formatted output: \n" . $icalendar . "\n");
			if (!$force) {
				$newETAG = $calDAVClient->DoPUTRequest($result_row["cal_uid"] . "/" . $result_row["rem_uid"] . ".ics", $icalendar, '"' .$result_row["etag"] . '"');
			} else {
				//force update
				debug (__FILE__ . ": PushReminderUpdate: target URL: " . $result_row["cal_uid"] . "/" . $result_row["rem_uid"] . ".ics" );
				$newETAG = $calDAVClient->DoPUTRequest($result_row["cal_uid"] . "/" . $result_row["rem_uid"] . ".ics", $icalendar);
			}
			debug (__FILE__ . ": PushReminderUpdate: Data PUT with return eTag of " . $newETAG);
			if (strpos($newETAG,'HTTP/1.0 4')===false) {
				$sql = "UPDATE " . DB::$reminder_table . " SET etag='" . $newETAG . "' WHERE id=" . $id;
				$dds->setSQL($sql);
				debug (__FILE__ . ": PushReminderUpdate: ETAG updated in database");
				return true;
			}	else {
				debug (__FILE__ . ": PushReminderUpdate: Bad request");
				return false;
			}
		} // else : this is not a reminder with a remote calendar
	}
		
	public static function LoadCalendarReminders($calDAVClient,$CalendarUID) {
		global $dds;
		//To be used synchronously with user logged in
		$sql="SELECT id from " . DB::$caldav_cal_table . " WHERE uid='" . $CalendarUID . "'";
		$dds->setSQL($sql);
		if ($result_row=$dds->GetNextRow()) $calendar_id=$result_row[0];
		$events=CalDAV::GetReminders($calDAVClient,$CalendarUID);
		$j=rand(0,1000000000);
		foreach ( $events as $k => $event ) {
			debug ("LoadCalendarReminders: loading event: \n" . $event['data'] . "\n");
			$j = $j + 1;
			$parsed=CalDAV::parseEvent($event['data']);
			$S=CalDAV::getSQLBuilder($parsed);
			$S->addColumn("etag",$event['etag']);
			//$S->addColumn("url",$event['href']);
			$S->addColumn("calendar_id",$calendar_id);
			$S->addColumn("sequence",(string) $j);
			$dds->setSQL($S->getSQL());
		}				
	}
		
	public static function PullCalendarUpdates() {
		/* 
			To be run as background process. All these "requires" need to be done in the calling script
			To make the variables set in them be global.
		

		require_once "clsDB.php";
		require_once "settingsHydrogen.php";
		require_once "settingsPasswords.php";
		require_once "Hydrogen/clsDataSource.php";
		require_once ("clsReminder.php");
		*/
		global $dds;
		debug("PullCalendarUpdates: Reading owners");
		$sql="SELECT distinct owner from " . DB::$caldav_acct_table ;
		$dds->setSQL($sql);
		$owners = $dds->GetDataset();
		for($x = 0; $x < count($owners); $x++) {
			debug("PullCalendarUpdates: 	Reading accounts for owner ". $owners[$x][0]);
			$sql="SELECT * from " . DB::$caldav_acct_table . " WHERE owner='" . $owners[$x][0] . "'";
			$dds->setSQL($sql);
			$accounts = $dds->GetDataset("labelled");
			for($y = 0; $y < count($accounts); $y++) {
				debug("PullCalendarUpdates: 		Reading calendars for account " . $accounts[$y]['alias']);					
				$uri="http://" . $accounts[$y]['rhost'] . ":" . $accounts[$y]['rport']. "/" . $accounts[$y]['ruser'] . "/";
				$calDAVClient = new CalDAVClient( $uri, $accounts[$y]["ruser"], $accounts[$y]["rpassword"], "dummy" );
				$rcalendars= $calDAVClient->DoCalendarRequest();
				$sql="SELECT * from " . DB::$caldav_cal_table . " WHERE remote_acct_id=" . $accounts[$y]['id'] . "";
				$dds->setSQL($sql);
				$dbcalendars = $dds->GetDataset("labelled");
				debug("PullCalendarUpdates: 		" . count($dbcalendars) . " calendars found for account " . $accounts[$y]['alias']);
				for($z = 0; $z < count($dbcalendars); $z++) {	
					debug("PullCalendarUpdates: 		Matching CTAGs for calendar $z :" . $dbcalendars[$z]['name']);
					//Check if CTAG has changed: Loop through all the CTAGs found on the 
					//   remote server and look for a match.
					$cmatch=false;
					for($i = 0; $i < count($rcalendars); $i++) {	
						if ($rcalendars[$i]['ctag']==$dbcalendars[$z]['ctag']) $cmatch=true;
					}
					if (!$cmatch) {
						//pull all the ETAGs
						debug("PullCalendarUpdates: 			Reading ETAGs for calendar " . $dbcalendars[$z]['name']);
						$etags=CalDAV::GetReminderEtags($calDAVClient,$dbcalendars[$z]['uid']);
						$sql="SELECT etag, summary from " . DB::$reminder_table . " WHERE calendar_id=" . $dbcalendars[$z]['id'] . "";
						$dds->setSQL($sql);
						$dbEtags = $dds->GetDataset("labelled");							
						for($j = 0; $j < count($etags); $j++) {	
							//Check if ETAG has changed: Loop through all the ETAGs found in the 
							//   database and look for a match.	
							debug("PullCalendarUpdates: 			Matching ETAGs");
							$ematch=false;
							$updating = false;
							for($k = 0; $k < count($dbEtags); $k++) {	
								if ($etags[$j]['etag']==$dbEtags[$k]['etag']) $ematch=true;
							}
							if (!$ematch) {
								//Pull the event from the remote server for insert or update
								debug("PullCalendarUpdates: 			Reading event href=" .$etags[$j]['href'] );
								$event=$calDAVClient->GetEntryByHref($etags[$j]['href'],"/" . $dbcalendars[$z]['uid']."/");
								debug("PullCalendarUpdates: 			Event data=\n" .$event . "\n");
								$parsed=CalDAV::parseEvent($event);
								if ($parsed)  debug ("Event successfully parsed");
								$sql="SELECT max(id), max(sequence), max(owner) from " . DB::$reminder_table . " WHERE uid='" . $parsed['UID'] . "'";
								$dds->setSQL($sql);
								$result_row=$dds->getNextRow();
								if (is_null($result_row[0])) {
									//INSERT  (new record)
									debug("PullCalendarUpdates: 			building SQL to insert new reminder \n" . $event . "\n");
									$S=CalDAV::getSQLBuilder($parsed,"INSERT",false);
									debug("PullCalendarUpdates: 			SQL builder instantiated");
									$S->addColumn("owner",$owners[$x][0]);
									$S->addColumn("sequence",(string) rand(0,1000000000));
								} else {
									//UPDATE
									$updating = true;
									debug("PullCalendarUpdates: 			building SQL to update reminder \n" . $event . "\n");
									$S=CalDAV::getSQLBuilder($parsed,"UPDATE",false);
									$S->addWhere("id",$result_row[0]);
								}
								$S->addColumn("etag",$etags[$j]['etag']);
								//$S->addColumn("url",$etags[$j]['href']);
								
								
								$S->addColumn("calendar_id",$dbcalendars[$z]['id']);
								debug("PullCalendarUpdates: 			SQL built");
								$sql=$S->getSQL();
								debug("PullCalendarUpdates: 			SQL:" . $sql);
								$dds->setSQL($sql);
								debug("PullCalendarUpdates: 			Reminder added/updated");
								if ($updating) {
									debug("Incoming update recognized.");
									//if the incoming update has a new STATUS or COMPLETED line,
									//it gets special handling
									$incoming_completed=false;
									//Check COMPLETED for date
									if (isset($parsed['COMPLETED'])) {
										$incoming_completed=true;
										debug("Incoming update : COMPLETED is set to " . $parsed['COMPLETED'],__FILE__);
									}
									//Check STATUS for COMPLETED
									if ($parsed['STATUS']=="COMPLETED") {
										$incoming_completed=true;
										debug("Incoming update : STATUS is set to COMPLETED",__FILE__);
									}
									//We could look at the actual date value of "COMPLETED"
									// but that's a job for another day. 
									// Since we pushed the sequence value out on local updates 
									// we can read it back and see if it matches. We don't want to mark 
									// an item complete more times than appropriate. 
									if (!isset($parsed['X-CADENCE-SEQUENCE'])) $parsed['X-CADENCE-SEQUENCE']="";
									if ($result_row[1] != $parsed['X-CADENCE-SEQUENCE']) {
										$incoming_completed=false;
										debug("Incoming update cancelled due to unmatched sequence",__FILE__);
									}
									if ($incoming_completed) {
										Reminders::MarkComplete($parsed['X-CADENCE-SEQUENCE'],true,$result_row[2]);
										debug("Incoming status update processed",__FILE__);
									}
								}
							}
						}
						debug("PullCalendarUpdates: 			Done matching ETAGs for calendar " . $dbcalendars[$z]['name']);
					}
					debug("PullCalendarUpdates: 			Done processing calendar " . $dbcalendars[$z]['name']);
				}
				debug("PullCalendarUpdates: 		Done processing calendars for account " . $accounts[$y]['alias']);	
			}
			debug("PullCalendarUpdates: 	Done processing accounts for owner ". $owners[$x][0]);	
		}
		debug("PullCalendarUpdates: 	Done processing."); 
	}
	
	public static function GetReminderETags ($calDAVClient,$CalendarUID) {
		/**  
		* @return array of events, each with etag and href 
		*/			
		$events = $calDAVClient->GetAllTodos($CalendarUID ."/",true);
		debug ("GetReminderETags: " . count($events) . "  tags found for calendar ID $CalendarUID");
		return $events;
	}

	public static function GetReminders ($calDAVClient,$CalendarUID) {
		/**  
		* @return array of events, each with etag, href, and data
		*/
		$events = $calDAVClient->GetAllTodos($CalendarUID ."/",false);
		return $events;
	}
	
	public static function parseEvent($ICS) {
		//debug("parseEvent: \n" . $ICS);
		/** 
		* @return array of key-value pairs from ICS string
		*/
		$lines= explode("\n",$ICS);
		$return = array();
		$calendar=false;
		$uid = false;
		$summary=false;
		$arrlength = count($lines);
		for($x = 0; $x < $arrlength; $x++) {
			//debug("Processing line: " . $lines[$x]);
			
			//CR LF chars cause a couple of problems:
			$lines[$x] = str_replace(chr(10),"",$lines[$x]);
			$lines[$x] = str_replace(chr(13),"",$lines[$x]);
			
			$parts= explode(":",$lines[$x]);
			$partstring=$parts[0];
			if (count($parts)>1)  $partstring .= " [1] length (" . strlen($parts[1]) . ")= " . $parts[1];
			//debug(count($parts) . " line parts: [0] length (" . strlen($parts[0]) . ")= " . $partstring);
			//Assumption for now is that each input $ICS contains only one event or todo.
			//Some validation would be good. Require a UID, SUMMARY.
			if ($parts[0]=="BEGIN" and $parts[1]=="VCALENDAR")	{					
				$calendar=true;
				//debug ("VCALENDAR recognized");
			}
			if ($parts[0]=="BEGIN" and $parts[1]=="VEVENT")	$return["VCALENDAR"]="VEVENT";
			if ($parts[0]=="BEGIN" and $parts[1]=="VTODO") $return["VCALENDAR"]="VTODO";	
			//After finding the VCALENDAR line, ignore any BEGIN and END lines
			if ($parts[0]!="BEGIN" and $parts[0]!="END") {
				if ($parts[0]=="UID" and count($parts)>1) $uid = true;
				if ($parts[0]=="SUMMARY" and count($parts)>1) $summary = true;
				//discard any (header) content before the BEGIN:VCALENDAR line
				if (count($parts)>1 and $calendar) {
					//debug ("Line $x content: " . $parts[0] . "=" . $parts[1]);
					$return[$parts[0]]=$parts[1];
				}
				
			}
		}
		//Ignore any HTTP resp codes, etc. at beginning
		$return['ics']=substr($ICS,strpos($ICS,"BEGIN:VCALENDAR"));

		if ($calendar and $uid and $summary) return $return; else {
			debug("parseEvent ERROR:");
			if (!$uid) debug ("No UID");
			if (!$summary) debug ("No SUMMARY");
			if (!$calendar) debug ("Not a VCALENDAR");
			return false;
		}
	}
	

	public static function getSQLBuilder ($parsed,$mode="INSERT",$current_user=true) {
		/** 
		* @return SQLBuilder object from parsed ICS string
		*/	
		debug ("CalDAV::getSQLBuilder : loading SQLbuilder class");
		require_once("Hydrogen/clsSQLBuilder.php");
		debug ("CalDAV::getSQLBuilder : Checking UID");
		//require UID to be set
		if (!isset($parsed['UID'])) {
			debug ("CalDAV::getSQLBuilder : no UID set");
			return false;
		}
		debug ("CalDAV::getSQLBuilder : creating instance");
		$sqlb = new SQLBuilder($mode);
		debug ("CalDAV::getSQLBuilder : setting table name");
		$sqlb->setTableName(DB::$reminder_table);
		if ($current_user) $sqlb->addColumn("owner",$_SESSION['username']);
		if ($current_user and ($mode=="UPDATE")) $sqlb->addWhere("owner='" .  $_SESSION['username']. "'");
		if ($mode=="UPDATE") $sqlb->addWhere("uid='" .  $parsed['UID']. "'");
		
		//The 'parsed' array also contains an 'ics' element with the unparsed data string.
		//We will be storing it separately as 'caldav_hidden' after stripping out 
		//the elements we store as individual database fields.
		$caldav_hidden=$parsed['ics'];
		
		
		//Watch out for extra CR/LF characters, and remove a few lines
		if (!is_null($caldav_hidden)) {
			$temp=explode("\n",$caldav_hidden);
			for ($i=0; $i < count($temp); $i++) {
				//$temp[$i]=str_replace(chr(10),"",$temp[$i]); // this is the "\n" character, which we already exploded
				$temp[$i]=str_replace(chr(13),"",$temp[$i]);
				$temp[$i]=str_replace("BEGIN:VCALENDAR","--DELETE-ME--",$temp[$i]);
				$temp[$i]=str_replace("END:VCALENDAR","--DELETE-ME--",$temp[$i]);
				$temp[$i]=str_replace("BEGIN:VTODO","--DELETE-ME--",$temp[$i]);
				$temp[$i]=str_replace("END:VTODO","--DELETE-ME--",$temp[$i]);
				if(strpos($temp[$i],"VERSION:")===0) $temp[$i]="--DELETE-ME--";
				if(strpos($temp[$i],"CALSCALE:")===0) $temp[$i]="--DELETE-ME--";
				if(strpos($temp[$i],"DTSTAMP:")===0) $temp[$i]="--DELETE-ME--";
				if(strpos($temp[$i],"STATUS:")===0) $temp[$i]="--DELETE-ME--";
				//UID is supposed to be filtered out later as a mapped column 
				//if(strpos($temp[$i],"UID:")!==false) $temp[$i]="--DELETE-ME--";
				if($temp[$i]=='') $temp[$i]="--DELETE-ME--";
			}
			$caldav_hidden=implode("\n",$temp);
			$caldav_hidden=str_replace('--DELETE-ME--' . "\n",'',$caldav_hidden);
			$caldav_hidden=rtrim($caldav_hidden,'--DELETE-ME--');
			$caldav_hidden=rtrim($caldav_hidden,"\n");
			$caldav_hidden=rtrim($caldav_hidden,'--DELETE-ME--');
		}
		
		/*
		These CalDAV fields are all mapped to database columns.
		The mapping is defined as an array in the DB class.
		//CREATED:20190617T203340Z
		//LAST-MODIFIED:20190617T203340Z
		//SUMMARY:New Task
		//DESCRIPTION:This is a test
		//UID:b27c8f51-1aa3-4ae7-b9c7-b1b725463bad
		//URL:www.monstro.us
		//LOCATION:here
		//PRIORITY:1
		$sqlb->addColumn("uid",$parsed['UID']);
		$sqlb->addColumn("summary",$parsed['SUMMARY']);
		$sqlb->addColumn("location",$parsed['LOCATION']);
		$sqlb->addColumn("url",$parsed['URL']);
		$sqlb->addColumn("description",$parsed['DESCRIPTION']);
		$sqlb->addColumn("priority",$parsed['PRIORITY']);
		$sqlb->addColumn("last_modified",$parsed['LAST-MODIFIED']);
		$sqlb->addColumn("created",$parsed['CREATED']);
		*/
		
		debug ("CalDAV::getSQLBuilder : reading mapped columns");			
		$fields= DB::calDAV_mapped_columns();

		debug ("CalDAV::getSQLBuilder : looping thru mapped columns");	
		//This line of code will help us find the last line in the string. Will remove the extra char later.
		$caldav_hidden .= "\n";
		$length = count($fields);
		for ($i = 0; $i < $length; $i++) {
			debug ("CalDAV::getSQLBuilder : checking column " . $fields[$i]['col_name']);		
			if (isset($parsed[$fields[$i]['calDAV_name']])) {
				debug ("CalDAV::getSQLBuilder : setting value " . $parsed[$fields[$i]['calDAV_name']]);		
				//$sqlb->addColumn("uid",$parsed['UID']);
				$sqlb->addColumn($fields[$i]['col_name'],$parsed[$fields[$i]['calDAV_name']]);
				//remove this iCal line from 'caldav_hidden'
				$remove_me=$fields[$i]['calDAV_name'].":". $parsed[$fields[$i]['calDAV_name']] . "\n";
				$caldav_hidden=str_replace($remove_me,"",$caldav_hidden);

			}
		}			
		$caldav_hidden=rtrim($caldav_hidden,"\n");
		debug ("CalDAV::getSQLBuilder : hidden CalDAV data: " . $caldav_hidden);		
		$sqlb->addColumn("caldav_hidden",$caldav_hidden);
		
		return $sqlb;
		
	}
}	

?>