<?php
/**
 * Piping Class
 * Support a tag in this format: [survey-link:instrument_name], [survey-queue-link], [redcap-link:instrument]
 * -- If found, this piping tag will trigger a search and replace of the insturment_name with the appropriate
 * survey link.
 */
class EnhancedPiping {
    
    public static function downloadFile($project_id, $fieldname, $record, $event_id) {
        logIt("DOWNLOADED downloadFile $project_id is $fieldname record $record adn $event_id");
        
        // Should prepend with a random string?
        // generate random string of 8 characters
        // $prependHashToFilename = Utility::generateRandomString(8);
        // logIt("DOWNLOADED FILE HASH IS $prependHashToFilename");
        
        // get $edoc_id
        $edoc_id = Utility::getEdocID($project_id, $event_id, $record, $fieldname);
        
        if ($edoc_id == null) {
            logIt("There was no valid EdocID for this $fieldname", "ERROR");
            return null;
        }
        
        // $filename_tmp = Files::copyEdocToTemp($edoc_id, $prependHashToFilename);
        $filename_tmp = Files::copyEdocToTemp($edoc_id);
        
        return $filename_tmp;
    }
    
    public static function getSurveyTimestamp($record, $form_name, $event_id, $project_id) {

        $timestamp_field = $form_name . '_timestamp';
        $fields = array($timestamp_field,$form_name . '_complete');
        
        //                    format rec        fields event      grps chkbx dag sur filt labels
        $q = REDCap::getData($project_id,'json', $record, $fields, $event_id, NULL, FALSE, FALSE, TRUE);
        // $q = REDCap::getData('json', $record, null, NULL, NULL, FALSE, FALSE, TRUE);
        
        $results = json_decode($q, true);
        // logIt("this is result from getData ".print_r($results, true));
        $timestamp = $results[0][$timestamp_field];
        return $timestamp;
    
    }
    
    public static function getSurveyDatestamp($record, $form_name, $event_id, $project_id) {
        // TODO: handle events
        $re = '/(\d{4}-\d{2}-\d{2}) (.*)/mi';
        $timestamp = self::getSurveyTimestamp($record, $form_name,$event_id,$project_id);
        
        preg_match_all($re, $timestamp, $matches);
        
        // Print the entire match result
        //logIt("</br></br>matches" . print_r($matches, true));
        return $matches[1][0];
    }
    
    // A wrapper for piping values...
    public static function pipeThis($input, $record, $event_id, $project_id) {
        $input = self::pipeTags($input, $record, $event_id, $project_id);
     
        // label record eventid inst rec underline project_id span
        $result = Piping::replaceVariablesInLabel($input, $record, $event_id, null, null, false, $project_id, false);
          
        return $result;
    
    }
    
    // A wrapper for piping values...
    public static function pipeTags($input, $record, $event_id, $project_id) {
        $default_event_id = $event_id;
        global $redcap_version;
        
        // grep for the survey-link tag. if it exists replace with the link before passing on to
        $re_1 = '/(\[(\S*)\])?\[(survey-link|survey-url|survey-queue-link|survey-queue-url|redcap-link|redcap-url):?(\S*)\]/';
        $re = '/((\[(?\'event_name\'\S*)\])?\[(?\'command\'[A-Za-z_-]*):?(?\'param1\'[A-Za-z_]*):?(?\'param2\'[^\]:]*):?(?\'param3\'[^\]:]*)\])/m';
        // find all the tags that match the above reg expression
        if (preg_match_all($re, $input, $matches, PREG_PATTERN_ORDER)) {
            //logIt("</br></br>matches" . print_r($matches, true));
            // look up the survey link for each tagged and store in array under '99'
            // 0 = full, 2=event_id, 3=type (survey/file), 4, file_name
            foreach ($matches['command'] as $key => $value) {
                //reset the event id the default passed in.
                $event_id = $default_event_id;
                $matches['pre-pipe'][$key] = "/" . str_replace(array('[',']'), array('\[','\]'), $matches[0][$key]) . "/";
                switch ($value) {
                    case "survey-link" :
                        // render the survey as a href tag with the survey name as the text.
                        if ($matches['event_name'][$key] != null) {
                            $event_name = $matches['event_name'][$key];
                            if (REDCap::isLongitudinal()) {
                                $event_id = REDCap::getEventIdFromUniqueEvent($event_name);
                            }
                        }
                        
                        $link = self::getSurveyLink($matches['param1'][$key], $record, $event_id,$project_id);
                        //logIt("THIS IS THE SURVEY LINK ".$link . " with value $value record $record event_id $event_id pid $project_id");
                        //if there is a param2 set that as title
                        $survey_title = (($matches['param2'][$key] == null) ? $matches['param1'][$key] : $matches['param2'][$key]);

                        $matches['post-pipe'][$key] = "<a href='$link'>" . $survey_title . "</a>";
                        break;
                    case "survey-url" :
                        // only return the plain text url (assuming it will be wrapped in other html).
                        // add event if there
                        if ($matches['event_name'][$key] != null) {
                            $event_name = $matches['event_name'][$key];
                            if (REDCap::isLongitudinal()) {
                                $event_id = REDCap::getEventIdFromUniqueEvent($event_name);
                            }
                        }
                        
                        $link = self::getSurveyLink($matches['param1'][$key], $record, $event_id,$project_id);
                        
                        $matches['post-pipe'][$key] = $link;
                        break;
                    case "survey-queue-url" :
                        // just the plain url
                        // deal as survey-queue
                        $link = self::getSurveyQueueLink($record, $project_id);
                        $matches['post-pipe'][$key] = $link;
                        break;
                    case "survey-queue-link" :
                        // format it as a link
                        // deal as survey-queue
                        $link = self::getSurveyQueueLink($record, $project_id);
                        $matches['post-pipe'][$key] = "<a  href='$link'>" . "Survey Queue Link" . "</a>";
                        break;
                    case "redcap-link" :
                        if ($matches['event_name'][$key] != null) {
                            $event_name = $matches['event_name'][$key];
                            if (REDCap::isLongitudinal()) {
                                $event_id = REDCap::getEventIdFromUniqueEvent($event_name);
                            }
                        }
                        // deal as redcap-link
                        $url = APP_PATH_WEBROOT_FULL . "redcap_v{$redcap_version}/" . "DataEntry/index.php?pid={$project_id}&page={$matches['param1'][$key]}&id={$record}&event_id={$event_id}";
                        
                        $matches['post-pipe'][$key] = "<a  href='$url'>" . $url . "</a>";
                        break;
                    case "survey-date-completed" :
                        if ($matches['event_name'][$key] != null) {
                            $event_name = $matches['event_name'][$key];
                            if (REDCap::isLongitudinal()) {
                                $event_id = REDCap::getEventIdFromUniqueEvent($event_name);
                            }
                        }
                        $timestamp = self::getSurveyDatestamp($record, $matches['param1'][$key], $event_id,$project_id);

                        if ($timestamp == "") {
                            //leave as it was
                            $matches['post-pipe'][$key] = $matches[0][$key];
                        } else {
                            $matches['post-pipe'][$key] = $timestamp;
                        }
                        break;
                    case "survey-time-completed" :
                        if ($matches['event_name'][$key] != null) {
                            $event_name = $matches['event_name'][$key];
                            if (REDCap::isLongitudinal()) {
                                $event_id = REDCap::getEventIdFromUniqueEvent($event_name);
                            }

                        }

                        $timestamp = self::getSurveyTimestamp($record, $matches['param1'][$key], $event_id,$project_id);

                        if ($timestamp == "") {
                            //leave as it was
                            $matches['post-pipe'][$key] = $matches[0][$key];
                        } else {
                            $matches['post-pipe'][$key] = $timestamp;
                        }
                        break;
                    case "survey-button" :
                        if ($matches['event_name'][$key] != null) {
                            $event_name = $matches['event_name'][$key];
                            if (REDCap::isLongitudinal()) {
                                $event_id = REDCap::getEventIdFromUniqueEvent($event_name);
                            }
                        }
                        
                        $link = self::getSurveyLink($matches['param1'][$key], $record, $event_id,$project_id);
                        $button2 = "<div style='margin:auto;'><a href='" . $link . "' style=\"background-color:#8C1515;border:1px solid #1e3650;border-radius:4px;color:#ffffff;display:inline-block;font-family:sans-serif;font-size:13px;font-weight:bold;line-height:40px;text-align:center;text-decoration:none;width:200px;-webkit-text-size-adjust:none;\">" . $matches['param2'][$key] . "</a></div>";
                        $button = "<div><!--[if mso]><v:roundrect xmlns:v='urn:schemas-microsoft-com:vml' xmlns:w='urn:schemas-microsoft-com:office:word' href='" . $link . "' style='height:40px;v-text-anchor:middle;width:200px;' arcsize='10%' strokecolor='#1e3650' fillcolor='#8C1515'><w:anchorlock/><center style='color:#ffffff;font-family:sans-serif;font-size:13px;font-weight:bold;'>" . $matches['param2'][$key] 
                        ."</center> </v:roundrect><![endif]--><a href='" . $link . "' style='background-color:#8C1515;border:1px solid #1e3650;border-radius:4px;color:#ffffff;display:inline-block;font-family:sans-serif;font-size:13px;font-weight:bold;line-height:40px;text-align:center;text-decoration:none;width:200px;-webkit-text-size-adjust:none;mso-hide:all;'>" . $matches['param2'][$key] . "</a></div>";
                        
                        $matches['post-pipe'][$key] = $button;
                        
                        break;
                    default :
                        $matches['post-pipe'][$key] = $matches[0][$key];
                }
            }
        }
        // logIt("matches" .print_r($matches, true), "DEBUG");
        
        //sometimes there is nothing to pipe
        if ($matches['pre-pipe']!=null) {
            $input = preg_replace($matches['pre-pipe'], $matches['post-pipe'], $input);
        }

        return $input;
    
    }

    // THIS IS A REWRITE OF THE REDCap::getSurveyLink to permit the passing of a project_id as opposed to running in context
    static function getSurveyLink($instrument='', $record='',  $event_id='', $project_id = null)
    {
        global $Proj;
        $thisProj = empty($project_id) ? $Proj : new Project($project_id);
        $longitudinal = $thisProj->longitudinal;
        // Return NULL if no record name or not instrument name
        if ($record == '' || $instrument == '') return null;
        // If a longitudinal project and no event_id is provided, return null
//        logIt("pid: $project_id longitu:$longitudinal and rec:$record  and inst  $instrument and event $event_id is numeric ". is_numeric($event_id));
        
        if ($longitudinal && !is_numeric($event_id)) return null;
        // If a non-longitudinal project, then set event_id automatically
        if (!$longitudinal) $event_id = $thisProj->firstEventId;
        
        // If instrument is not a survey, return null
        if (!isset($thisProj->forms[$instrument]['survey_id'])) return null;
        // Get arm number if a longitudinal project
        $arm_num = $longitudinal ? $thisProj->eventInfo[$event_id]['arm_num'] : null;
        
        // Make sure record exists
        if (!self::projectRecordExists($record, $arm_num, $project_id)) return null;
        // Get hash
        $array = Survey::getFollowupSurveyParticipantIdHash($thisProj->forms[$instrument]['survey_id'], $record, $event_id);
        
        // If did not return a hash, return null
        if (!isset($array[1])) return null;
        // Return full survey URL
        return APP_PATH_SURVEY_FULL . '?s=' . $array[1];
    }
    
    // THIS IS A REWRITE OF THE INIT FUNCTIONS function record exists to permit the passing of a project_id
    // Check if a record exists in the redcap_data table
    static function projectRecordExists($record, $arm_num=null, $project_id=null)
    {
        global $Proj;
        $thisProj = empty($project_id) ? $Proj : new Project($project_id);
    
        // Query data table for record
        $sql = "select 1 from redcap_data where project_id = ".$thisProj->project_id." and field_name = '{$thisProj->table_pk}'
        and record = '" . prep($record) . "'";
        if (is_numeric($arm_num) && isset($thisProj->events[$arm_num])) {
            $sql .= " and event_id in (" . prep_implode(array_keys($thisProj->events[$arm_num]['events'])) . ")";
        }
        $sql .= " limit 1";
        $q = db_query($sql);
        return (db_num_rows($q) > 0);
    }

    // THIS IS A REWRITE OF THE REDCap::getSurveyLink to permit the passing of a project_id as opposed to running in context
    public static function getSurveyQueueLink($record='', $project_id = null)
    {
        global $Proj;
        
        $thisProj = empty($project_id) ? $Proj : new Project($project_id);
        if ($record == '') return null;

        // Make sure queue is enabled
        if (!self::surveyQueueEnabled($project_id)) return null;

        // Return full survey URL
        ///what is $array[1]???
        //return APP_PATH_SURVEY_FULL . '?s=' . $array[1];

        // Obtain the survey queue hash for this record
        $survey_queue_hash = self::getRecordSurveyQueueHash($record, false, $project_id);
        if ($survey_queue_hash == '') return null;

        // Return full survey URL
        return APP_PATH_SURVEY_FULL . '?sq=' . $survey_queue_hash;
    }


    // THIS IS A REWRITE OF THE Survey::surveyQueueEnabled function to include PROJECT_ID
    public static function surveyQueueEnabled($project_id = null)
    {
        // Order by event then by form order
        $this_project_id = $project_id == null ? PROJECT_ID : $project_id;
        if (empty($this_project_id)) {
            error_log("Invalid context for " . __FUNCTION__);
            return false;
        }

        $sql = "select count(1) from redcap_surveys_queue q, redcap_surveys s, redcap_metadata m, redcap_events_metadata e,
				redcap_events_arms a where s.survey_id = q.survey_id and s.project_id = ".$this_project_id." and m.project_id = s.project_id
				and s.form_name = m.form_name and q.event_id = e.event_id and e.arm_id = a.arm_id and q.active = 1 and s.survey_enabled = 1";
        $q = db_query($sql);

        return (db_result($q, 0) > 0);
    }


    // THIS IS A REWRITTE OF THE Survey::getRecordSurveyQueueHash to inlcude Project ID
    // Get the Survey Queue hash for this record. If doesn't exist yet, then generate it.
    // Use $hashExistsOveride=true to skip the initial check that the hash exists for this record if you know it does not.
    public static function getRecordSurveyQueueHash($record=null, $hashExistsOveride=false, $project_id = null)
    {
        $project_id = (empty($project_Id) ? PROJECT_ID : $project_id);

        // Validate record name
        if ($record == '') return null;
        // Default value
        $hashExists = false;
        // Check if record already has a hash
        if (!$hashExistsOveride) {
            $sql = "select hash from redcap_surveys_queue_hashes where project_id = ".$project_id."
					and record = '".prep($record)."' limit 1";
            $q = db_query($sql);
            
            $hashExists = (db_num_rows($q) > 0);
        }
        // If hash exists, then get it from table
        if ($hashExists) {
            // Hash already exists
            $hash = db_result($q, 0);
            
        } else {
            // Hash does NOT exist, so generate a unique one
            do {
                // Generate a new random hash
                $hash = generateRandomHash(10);
                // Ensure that the hash doesn't already exist in either redcap_surveys or redcap_surveys_hash (both tables keep a hash value)
                $sql = "select hash from redcap_surveys_queue_hashes where hash = '$hash' limit 1";
                $hashExists = (db_num_rows(db_query($sql)) > 0);
            } while ($hashExists);
            // Add newly generated hash for record
            $sql = "insert into redcap_surveys_queue_hashes (project_id, record, hash)
					values (".$project_id.", '".prep($record)."', '$hash')";
            if (!db_query($sql) && $hashExistsOveride) {
                // The override failed, so apparently the hash DOES exist, so get it
                $hash = self::getRecordSurveyQueueHash($record, false, $project_id);
            }
        }
        // Return the hash
        return $hash;
    }



}
