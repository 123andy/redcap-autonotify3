<?php

/**
 * Autonotify3 plugin by Andy Martin and Jae Lee, Stanford University
 *
 * Substantially revised on 2016-03-01 to support saving to the log and additional features
 * 18Oct2016 - support sending links:  add wrapper around pipe that will transliterate the survey link set in this format: [survey:instrument_name]
 *
 */

error_reporting(E_ALL);

require_once "EnhancedPiping.php";
require_once "Utility.php";


class AutoNotify {

//    const AutoNotifyConfigPid = 9223;

    // Note that this plugin name is important as it is a key for logging events to your log table.  This version of the
    // Autonotify plugin will behave 'separately' from existing versions.  Meaning the same rule will re-notify exsiting
    // records that were already notified with version 2 or 1.

    const PluginName = "AutoNotify3";

    // Message Parameters
    public $to, $cc, $bcc, $from, $subject, $message, $file_file, $file_event;

    // Other properties
    public $config, $triggers, $pre_det_url, $post_det_url, $longitudinal;

    // DET Project Parameters
    public $project_id, $instrument, $record, $redcap_event_name, $event_id, $redcap_data_access_group, $instrument_complete;

    // Instantiate the object with the project_id
    public function __construct($project_id) {
        if ($project_id) {
            $this->project_id = intval($project_id);
        } else {
            logIt("Called outside of context of project", "ERROR");
            exit();
        }

        global $Proj;
        if ($Proj == null) {
            $Proj = new Project($project_id);
        }
        $this->longitudinal = $Proj->longitudinal;
    }

    // Adds information from the DET post into the current object
    public function loadDetPost() {
        $this->project_id = voefr('project_id');
        $this->instrument = voefr('instrument');
        $this->record = voefr('record');
        $this->redcap_event_name = voefr('redcap_event_name');
        if ($this->longitudinal) {
            $events = REDCap::getEventNames(true,false);
            $this->event_id = array_search($this->redcap_event_name, $events);
        } else {
            global $Proj;
            $this->event_id = $Proj->firstEventId;
        }
        $this->redcap_data_access_group = voefr('redcap_data_access_group');
        $this->instrument_complete = voefr($this->instrument.'_complete');

    }

    // Converts old autonotify configs that used the url into new ones that use the log table
    public function checkForUpgrade() {
        // To be back-compatible, we need to be able to convert old autonotify calls into the newer format.
        global $data_entry_trigger_url;
        $det_qs = parse_url($data_entry_trigger_url, PHP_URL_QUERY);

        parse_str($det_qs,$params);
        if (!empty($params['an'])) {
            // Step 1:  We have identified an old DET-based config
            logIt("Updating older DET url: $data_entry_trigger_url", "DEBUG");
            $an = $params['an'];
            $old_config = self::decrypt($an);

            // Step 2:  Save the updated autonotify object
            $this->config = $old_config;
            $this->saveConfig();
            $log_data = array(
                'action' => self::PluginName . ' config moved from querystring to log',
                'an' => $an,
                'config' => $old_config
            );
            REDCap::logEvent(self::PluginName . " Update", "Moved querystring config to log table", json_encode($log_data), null, null, $this->project_id);

            // Step 3:  Update the DET URL to be plain-jane
            self::isDetUrlNotAutoNotify(true);
        }
    }

    // Scans the log for the latest autonotify configuration
    public function loadConfig() {
//		logIt(__FUNCTION__, "DEBUG");

        // Convert old querystring-based autonotify configurations to the log-based storage method
        $this->checkForUpgrade();

        // Load from the log
        $sql = "SELECT l.sql_log, l.ts
			FROM redcap_log_event l WHERE
		 		l.project_id = " . intval($this->project_id) . "
			AND l.description = '" . self::PluginName . " Config'
			ORDER BY ts DESC LIMIT 1";
        $q = db_query($sql);
		// logIt(__FUNCTION__ . ": sql: $sql","DEBUG");
        if (db_num_rows($q) == 1) {
            // Found config!
            $row = db_fetch_assoc($q);
            $this->config = json_decode($row['sql_log'], true);
            if (isset($this->config['triggers'])) {
                $this->triggers = json_decode(htmlspecialchars_decode($this->config['triggers'], ENT_QUOTES), true);
            }
            //logIt(__FUNCTION__ . ": Found version with ts ". $row['ts'],"INFO");
            return true;
        } else {
            // No previous config was found in the logs
            logIt(__FUNCTION__ . ": No config saved in logs for this project", "INFO");
            return false;
        }
    }

    // Write the current config to the log
    public function saveConfig() {
        $sql_log = json_encode($this->config);
        REDCap::logEvent(self::PluginName . " Config", "Configuration Updated", $sql_log, null, null, $this->project_id);
        logIt(__FUNCTION__ . ": Saved configuration", "INFO");

        // Update the DET url if needed
        self::isDetUrlNotAutoNotify(true);
//        $this->saveConfig2();
    }


    // Execute the loaded DET.  Returns false if any errors
    public function execute($cron_only = false) {
        // logIt(__FUNCTION__, "DEBUG");
        $triggers_fired = array();

        // Check for Pre-DET url
        if ($cron_only === false) self::checkPreDet();

        // Decode the triggers from the config
        $triggers = json_decode(htmlspecialchars_decode($this->config['triggers'], ENT_QUOTES), true);

        // Loop through each notification
        foreach ($triggers as $i => $trigger) {
            $logic = $trigger['logic'];
            $logic = EnhancedPiping::pipeTags($logic, $this->record, $this->event_id, $this->project_id);

            $title = $trigger['title'];
            $enabled = $trigger['enabled'];
            $scope = isset($trigger['scope']) ? $trigger['scope'] : 0;  // Get the scope or set to 0 (default)

            if (!$enabled) {
//				logIt(__FUNCTION__ . ": The current trigger ($title) is not set as enabled - skipping", "DEBUG");
                continue;
            }

            // If cron_only triggers, skip all that do not contain datediff
            if ($cron_only AND strpos($logic, "datediff") === false) continue;

            if (empty($title)) {
                logIt("Cannot process alert $i because it has an empty title: " . json_encode($trigger),"ERROR");
                continue;
            }

            // Append current event prefix to lonely fields if longitidunal
            if ($this->longitudinal && $this->redcap_event_name && $cron_only == false) $logic = LogicTester::logicPrependEventName($logic, $this->redcap_event_name);

            if (!empty($logic) && !empty($this->record)) {
//                logIt($this->record . ": Proj {$this->project_id} : Logic: " . json_encode($logic), "DEBUG");
                if (LogicTester::evaluateLogicSingleRecord($logic, $this->record, null, $this->project_id)) {
                    // Condition is true, check to see if already notified
                    if (!self::checkForPriorNotification($title, $scope)) {
                        $result = self::notify($title, $trigger);
                        $this_result = ($result ? 'Success' : 'Failure');
                        //logIt("{$this->record}: [$title] --- $this_result");
                    } else {
                        // Already notified
                        $this_result = "Already notified";
                    }
                } else {
                    // Logic did not pass
                    //logIt("Logic: $logic / Record: " . $this->record . " / Project: " . $this->project_id, "DEBUG");
                    $this_result = "Logic false";
                }
            } else {
                // object missing logic or record
                $this_result = "Unable to execute: missing logic or record";
            }
            $triggers_fired[] = "$title = $this_result";
            logIt("{$this->record}: [{$this->project_id}] [$title] -> $this_result / " . json_encode($logic));
        }

        // Check for Post-DET url
        if ($cron_only === false) self::checkPostDet();
        return implode(",",$triggers_fired);
    }


    // Used to test the logic and return an appropriate image
    public function testLogic($logic) {
//		logIt('Testing record '. $this->record . ' with ' . $logic, "DEBUG");

        $piped_logic = EnhancedPiping::pipeTags($logic, $this->record, $this->event_id, $this->project_id);
        if ($piped_logic != $logic) logIt(__FUNCTION__ . ": logic updated from \n$logic\nto\n$piped_logic", "INFO");
        $logic = $piped_logic;

        if (LogicTester::isValid($logic)) {
            // Append current event details
            if ($this->longitudinal && $this->redcap_event_name) {
                $logic = LogicTester::logicPrependEventName($logic, $this->redcap_event_name);
                logIt(__FUNCTION__ . ": logic updated with selected event as " . $logic, "INFO");
            }

            if (LogicTester::evaluateLogicSingleRecord($logic, $this->record)) {
                $result = RCView::img(array('class'=>'imgfix', 'src'=>'accept.png'))." True";
            } else {
                $result = RCView::img(array('class'=>'imgfix', 'src'=>'cross.png'))." False";
            }
        } else {
            $result = RCView::img(array('class'=>'imgfix', 'src'=>'error.png'))." Invalid Syntax";
        }
        return $result;
    }

    // Used to test by sending a message
    public function testMessage($trigger) {
        // Get email for current user
        global $user_email;
        $trigger['to'] = $user_email;
//        $trigger['bcc'] = '';
        $trigger['cc'] = '';
        $title = "[TEST] " . $trigger['title'];

        $result = self::notify($title, $trigger, true);
        if ($result) {
            return "Test message sent to $user_email";
        } else {
            return "Error sending message - check logs.";
        }
    }

    // Check if there is a pre-trigger DET configured - if so, post to it.
    public function checkPreDet() {
        if ($this->config['pre_script_det_url']) {
            self::callDets($this->config['pre_script_det_url']);
        }
    }

    // Check if there is a post-trigger DET configured - if so, post to it.
    public function checkPostDet() {
        if ($this->config['post_script_det_url']) {
            self::callDets($this->config['post_script_det_url']);
        }
    }

    // Takes a pipe-separated list of urls and calls them as DETs
    private function callDets($urls) {
        $dets = explode('|',$urls);
        foreach ($dets as $det_url) {
            $det_url = trim($det_url);
            http_post($det_url, $_POST, 10);
        }
    }

    // Now that we're not storing the DET in the query string - this simply needs to be the url to this plugin
    public function getDetUrl() {
        // Build the url of the page that called us
        $isHttps = isset($_SERVER['HTTPS']) AND !empty($_SERVER['HTTPS']) AND $_SERVER['HTTPS'] != 'off';

        // Force http for certain domains
        global $http_only;
        foreach ($http_only as $site) {
            if (strpos($_SERVER['HTTP_HOST'], $site) !== false) $isHttps = false;
        }

        // Build URL
        $url = 'http' . ($isHttps ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

        // Remove query string from DET url
        $url = preg_replace('/\?.*/', '', $url);

        return $url;
    }

    // If there is a different DET url configured in the project, it will return it, otherwise returns false
    public function isDetUrlNotAutoNotify($update = false) {
        global $data_entry_trigger_url;
        $det_url = self::getDetUrl();
        if ($data_entry_trigger_url !== $det_url) {
            logIt("DETS ARE DIFFERENT - DET: [$data_entry_trigger_url] / SELF DET: [$det_url]", "DEBUG");
            if ($update) {
                // Force the update
                $sql = "update redcap_projects set data_entry_trigger_url = '".prep($det_url)."' where project_id = " . intval($this->project_id) . " LIMIT 1;";
                db_query($sql);
                REDCap::logEvent(AutoNotify::PluginName . " Update", "Converted DET Url to $det_url (see log table for old value)", $data_entry_trigger_url, null, null, $this->project_id);
                $data_entry_trigger_url = $det_url;
            }
            return $det_url;
        } else {
            return false;
        }
    }


    // Notify and log
    public function notify($title, $trigger, $test_mode = false) {
        global $redcap_version;
        // Run notification
        //$url = APP_PATH_WEBROOT_FULL . "redcap_v{$redcap_version}/" . "DataEntry/index.php?pid={$this->project_id}&page={$this->instrument}&id={$this->record}&event_id={$this->event_id}";
        $url = APP_PATH_WEBROOT_FULL . "redcap_v{$redcap_version}/" . "DataEntry/record_home.php?pid={$this->project_id}&id={$this->record}";

        $piped_msg = EnhancedPiping::pipeThis($trigger['body'],  $this->record, $this->event_id, $this->project_id);

        // Prepare message
        $email = new Message();
        $email->setTo(self::pipeThis($trigger['to']));
        $email->setCc(self::pipeThis($trigger['cc']));
        $email->setBcc(self::pipeThis($trigger['bcc']));
        $email->setFrom(self::pipeThis($trigger['from']));
        $email->setSubject(self::pipeThis($trigger['subject']));

        //Plugin::log($email, "DEBUG", "CHECKING contents of email fields");
        //check which body type was selected and setBody accordingly
        //         'standard' => 'Standard (red box with link to record)',
        //         'text-only' => 'Text-only (sends plain message body)',
        //         'stanford' => 'Stanford (wrap body in nice Stanford theme html message)'
        if ($trigger['template']=='standard') {
            $msg = self::renderStandardMessage($piped_msg, $url, $trigger['title']);
        } elseif ($trigger['template']=='stanford') {
            $msg = nl2br(self::renderStanfordMessage($piped_msg));
        } else {
            //another wrapper around pipe will transliterate the survey link set in this format: [survey:instrument_name]
            $msg = nl2br($piped_msg);
        }

        //Standard Message has a whole bunch of extra line breaks. Try removing the nl2br only for Standard
        //$email->setBody(nl2br($msg));
        $email->setBody(($msg));
        // if there are files, then attach themto email
        if (($trigger['file_field']) == '0') {
            //logIt("No entry for file-field..." . $trigger['file_field']);
        } else {
            $files = EnhancedPiping::downloadFile($this->project_id, $trigger['file_field'], $this->record,  $trigger['file_event']);
            if (empty($files)) {
                //logIt("This file could not be found:".$trigger['file_field'], "ERROR");
            } else {
                //logIt("Attaching these files:" . print_r($files, true));
                $email->setAttachment($files);
		//                unlink($files);
            }
        }

        // Send Email
        //First, check that there is
        //1) at least one valid email in TO, CC, or BCC?
        //2) a valid email in the 'FROM'
        $foo = array($email->getTo(),$email->getCc(),$email->getBcc(),$email->getFrom());
        $status = self::verifyEmail($foo);
        if (! self::verifyEmail($foo)) {
            REDCap::logEvent(
                    self::PluginName . " Error", "Error sending " . self::PluginName . " Email: " . $title . ". Missing valid email".
                    " To:".$email->getTo() ." CC:".$email->getCc()." Bcc:".$email->getBcc()." FROM:".$email->getFrom(),
                    $email->getSendError() . " with " . json_encode($email),
                    $this->record,
                    $this->event_id,
                    $this->project_id
                    );
            return false;

        }

        if (!$email->send()) {
            logIt("Error sending email:".print_r($email->getSendError(), true));
            error_log('Error sending mail: '.$email->getSendError().' with '.json_encode($email));
            REDCap::logEvent(
                self::PluginName . " Error", "Error sending " . self::PluginName . " Email: " . $title,
                $email->getSendError() . " with " . json_encode($email),
                $this->record,
                $this->event_id,
                $this->project_id
            );
            return false;
        } else {
            // Delete temp file, if applicable
            if (isset($files) && !empty($files)) {
            unlink($files);
            }
        }

        // Add Log Entry
        $data_values = "==> " . self::PluginName . " Rule Fired\n".
            "title,$title\n".
            "record,{$this->record}\n" .
            ($this->longitudinal ? "event,{$this->redcap_event_name}" : "");
        if (!$test_mode) REDCap::logEvent(self::PluginName . ' Alert',$data_values,"",$this->record, $this->event_id, $this->project_id);
        return true;
    }

    /**
     * Return true if there is at least one validly formatted email in To, Cc, Bcc
     * and valid formatted email in From
     */
    public function verifyEmail($emails) {
        $from = Utility::isValidEmail($emails[3]);
        $to = Utility::isValidEmail($emails[0]) + Utility::isValidEmail($emails[1]) + Utility::isValidEmail($emails[2]);

        if ($from < 1) {
            logIt('Error sending mail: From address is not valid:'.$from);
            return false;
        }
        if ($to < 1) {
            logIt("Error sending mail- No valid email address in To/CC/BCC fields:".
                    " To:".$emails[0] ." CC:".$emails[1]." Bcc:".$emails[2]);
            return false;
        }

        return true;
    }


    // A wrapper for piping values...
    public function pipeThis($input) {
      //                                                  rec,           even,          ins,  recdat line   project_id         span
      $result = Piping::replaceVariablesInLabel($input, $this->record, $this->event_id, null, null, false,$this->project_id, false);
        return $result;
    }

    // Go through logs to see if there is a prior alert for this record/event/title
    // Scope 0 is record/event match, scope 1 is record only
    public function checkForPriorNotification($title, $scope=0) {
        if (!$this->longitudinal) $scope = 1; // Record match only is sufficient
        $sql = "SELECT l.data_values, l.ts
			FROM redcap_log_event l WHERE
		 		l.project_id = {$this->project_id}
			AND l.description = '" . self::PluginName . " Alert';";
        $q = db_query($sql);

        while ($row = db_fetch_assoc($q)) {
            $pairs = parseEnum($row['data_values']);
            if (
                $pairs['title'] == trim($title) &&
                $pairs['record'] == $this->record &&
                ( $scope == 1 OR $pairs['event'] == $this->redcap_event_name)
            )
            {
                $date = substr($row['ts'], 4, 2) . "/" . substr($row['ts'], 6, 2) . "/" . substr($row['ts'], 0, 4);
                $time = substr($row['ts'], 8, 2) . ":" . substr($row['ts'], 10, 2);

                // Already triggered
                logIt("Trigger previously matched on $date $time / Row: ". json_encode($row) . " / Pairs: " . json_encode($pairs), "DEBUG");
                return true;
            }
        }
        return false;
    }

    // Takes an encoded string and returns the array representation of the object
    public function decrypt($code) {
        $template_enc = rawurldecode($code);
        $json = decrypt_me($template_enc);	//json string representation of parameters
        $params = json_decode($json, true);	//array representation
        return $params;
    }

    // Takes an array and returns the encoded string
    public function encode($params) {
        $json = json_encode($params);
        $encoded = encrypt($json);
        return rawurlencode($encoded);
    }

    // Renders the triggers portion of the page, or an empty trigger if new
    public function renderTriggers() {
        $html = "<div id='triggers_config'>";
        if (isset($this->triggers)) {
            foreach ($this->triggers as $i => $trigger) {
                $html .= self::renderTrigger($i, $trigger); //['title'], $trigger['logic'], $trigger['test_record'], $trigger['test_event'], $trigger['enabled'], $trigger['scope']);
            }
        } else {
            $html .= self::renderTrigger(1, array());
        }
        $html .= "</div>";
        return $html;
    }

    // Render an individual trigger (also called by Ajax to add a new trigger to the page)
    public function renderTrigger($id, $trigger) {
        //, $title = '', $logic = '', $test_record = null, $test_event = null, $enabled = 1, $scope=0, $to='', $bcc='', $from='',$subject='',$body='') {
        $title = isset($trigger['title']) ? $trigger['title'] : '';
        $logic = isset($trigger['logic']) ? $trigger['logic'] : '';
        $test_record = isset($trigger['test_record']) ? $trigger['test_record'] : null;
        $test_event = isset($trigger['test_event']) ? $trigger['test_event'] : null;
        $enabled = isset($trigger['enabled']) ? $trigger['enabled'] : 1;
        $scope = isset($trigger['scope']) ? $trigger['scope'] : 0;
        $to = isset($trigger['to']) ? $trigger['to'] : '';
        $cc = isset($trigger['cc']) ? $trigger['cc'] : '';
        $bcc = isset($trigger['bcc']) ? $trigger['bcc'] : '';
        $from = isset($trigger['from']) ? $trigger['from'] : 'no-reply@stanford.edu';
        $subject = isset($trigger['subject']) ? $trigger['subject'] : 'SECURE:';
        $template = isset($trigger['template']) ? $trigger['template'] : 'standard';
        $body = isset($trigger['body']) ? $trigger['body'] : '';
        $file_field = isset($trigger['file_field']) ? $trigger['file_field'] : null;
        $file_event = isset($trigger['file_event']) ? $trigger['file_event'] : null;


        $html = RCView::div(array('class'=>'round chklist trigger','idx'=>"$id"),
            RCView::div(array('class'=>'chklisthdr', 'style'=>'color:rgb(128,0,0); margin-bottom:5px; padding-bottom:5px; border-bottom:1px solid #AAA;'), "Trigger $id: $title".
                RCView::a(array('href'=>'javascript:','onclick'=>"removeTrigger('$id')"), RCView::img(array('style'=>'float:right;padding-top:0px;', 'src'=>'cross.png')))
            ).
            RCView::table(array('cellspacing'=>'5', 'class'=>'tbi'),
                self::renderEnabledRow($id,'Status', $enabled) .  // Enable or Disable the current trigger
                self::renderRow('title-'.$id,'Title',$title, 'title').
                self::renderLogicRow($id,'Logic',$logic).
                (REDCap::isLongitudinal() ? self::renderScopeRow($id, 'Evaluate', $scope) : "") .
                self::renderTestRow($id,'Test', $test_record, $test_event).
                self::renderRow('to-'.$id,'To', $to, 'to') .
                self::renderRow('cc-'.$id,'Cc', $cc, 'cc') .
                self::renderRow('bcc-'.$id,'Bcc', $bcc, 'bcc') .
                self::renderRow('from-'.$id,'From', $from, 'from') .
                self::renderRow('subject-'.$id,'Subject', $subject, 'subject') .
                self::renderBodyTemplate('template-'.$id, 'Template', $template) .
                self::renderRow('body-'.$id,'Message', $body, 'body', 'textarea') .
                self::renderFileRow($id,'File', $file_field, $file_event)
            )
        );
        return $html;
    }

    public function getProjectTitle() {
        global $Proj;
        if ($Proj == null || $Proj->project_id != $this->project_id) {
            $Proj = new Project($this->project_id);
        }
        return $Proj->project_id['app_title'];
    }

    public function renderStandardMessage($piped_msg, $url, $title) {
        $dark = "#800000";	//#1a74ba  1a74ba
        $light = "#FFE1E1";		//#ebf6f3
        $border = "#800000";	//FF0000";	//#a6d1ed	#3182b9

        // Message (email html painfully copied from box.net notification email)
        $msg = RCView::table(array('cellpadding'=>'0', 'cellspacing'=>'0','border'=>'0','style'=>'border:1px solid #bbb; font:normal 12px Arial;color:#666'),
                RCView::tr(array(),
                        RCView::td(array('style'=>'padding:13px'),
                                RCView::table(array('style'=>'font:normal 15px Arial'),
                                        RCView::tr(array(),
                                                RCView::td(array('style'=>'font-size:18px;color:#000;border-bottom:1px solid #bbb'),
                                                        RCView::span(array('style'=>'color:black'),
                                                                RCVieW::a(array('style'=>'color:black'),
                                                                        'REDCap AutoNotification Alert'
                                                                        )
                                                                ).
                                                        RCView::br()
                                                        )
                                                ).
                                        RCView::tr(array(),
                                                RCView::td(array('style'=>'padding:10px 0'),
                                                        RCView::table(array('style'=>'font:normal 12px Arial;color:#666'),
                                                                RCView::tr(array(),
                                                                        RCView::td(array('style'=>'text-align:right'),
                                                                                "Title"
                                                                                ).
                                                                        RCView::td(array('style'=>'padding-left:10px;color:#000'),
                                                                                RCView::span(array('style'=>'color:black'),
                                                                                        RCView::a(array('style'=>'color:black'),
                                                                                                "<b>$title</b>"
                                                                                                )
                                                                                        )
                                                                                )
                                                                        ).
                                                                RCView::tr(array(),
                                                                        RCView::td(array('style'=>'text-align:right'),
                                                                                "Project"
                                                                                ).
                                                                        RCView::td(array('style'=>'padding-left:10px;color:#000'),
                                                                                RCView::span(array('style'=>'color:black'),
                                                                                        RCView::a(array('style'=>'color:black'),
                                                                                                self::getProjectTitle()
                                                                                                )
                                                                                        )
                                                                                )
                                                                        ).
                                                                ($this->redcap_event_name ? (RCView::tr(array(),
                                                                        RCView::td(array('style'=>'text-align:right'),
                                                                                "Event"
                                                                                ).
                                                                        RCView::td(array('style'=>'padding-left:10px;color:#000'),
                                                                                RCView::span(array('style'=>'color:black'),
                                                                                        RCView::a(array('style'=>'color:black'),
                                                                                                "$this->redcap_event_name"
                                                                                                )
                                                                                        )
                                                                                )
                                                                        )) : '').
                                                                RCView::tr(array(),
                                                                        RCView::td(array('style'=>'text-align:right'),
                                                                                "Instrument"
                                                                                ).
                                                                        RCView::td(array('style'=>'padding-left:10px;color:#000'),
                                                                                RCView::span(array('style'=>'color:black'),
                                                                                        RCView::a(array('style'=>'color:black'),
                                                                                                $this->instrument
                                                                                                )
                                                                                        )
                                                                                )
                                                                        ).
                                                                RCView::tr(array(),
                                                                        RCView::td(array('style'=>'text-align:right'),
                                                                                "Record"
                                                                                ).
                                                                        RCView::td(array('style'=>'padding-left:10px;color:#000'),
                                                                                RCView::span(array('style'=>'color:black'),
                                                                                        RCView::a(array('style'=>'color:black'),
                                                                                                $this->record
                                                                                                )
                                                                                        )
                                                                                )
                                                                        ).
                                                                RCView::tr(array(),
                                                                        RCView::td(array('style'=>'text-align:right'),
                                                                                "Date/Time"
                                                                                ).
                                                                        RCView::td(array('style'=>'padding-left:10px;color:#000'),
                                                                                RCView::span(array('style'=>'color:black'),
                                                                                        RCView::a(array('style'=>'color:black'),
                                                                                                date('Y-m-d H:i:s')
                                                                                                )
                                                                                        )
                                                                                )
                                                                        ).
                                                                RCView::tr(array(),
                                                                        RCView::td(array('style'=>'text-align:right'),
                                                                                "Message"
                                                                                ).
                                                                        RCView::td(array('style'=>'padding-left:10px;color:#000'),
                                                                                RCView::span(array('style'=>'color:black'),
                                                                                        RCView::a(array('style'=>'color:black'),
                                                                                                $piped_msg
                                                                                                )
                                                                                        )
                                                                                )
                                                                        )
                                                                )
                                                        )
                                                ).
                                        RCView::tr(array(),
                                                RCView::td(array('style'=>"border:1px solid $border;background-color:$light;padding:20px"),
                                                        RCView::table(array('style'=>'font:normal 12px Arial', 'cellpadding'=>'0','cellspacing'=>'0'),
                                                                RCView::tr(array('style'=>'vertical-align:middle'),
                                                                        RCView::td(array(),
                                                                                RCView::table(array('cellpadding'=>'0','cellspacing'=>'0'),
                                                                                        RCView::tr(array(),
                                                                                                RCView::td(array('style'=>"border:1px solid #600000;background-color:$dark;padding:8px;font:bold 12px Arial"),
                                                                                                        RCView::a(array('class'=>'hide','style'=>'color:#fff;white-space:nowrap;text-decoration:none','href'=>$url),
                                                                                                                "View Record"
                                                                                                                )
                                                                                                        )
                                                                                                )
                                                                                        )
                                                                                ).
                                                                        RCView::td(array('style'=>'padding-left:15px'),
                                                                                "To view this record, visit this link:".
                                                                                RCView::br().
                                                                                RCView::a(array('style'=>"color:$dark",'href'=>$url),
                                                                                        $url
                                                                                        )
                                                                                )
                                                                        )
                                                                )
                                                        )
                                                )
                                        )
                                )
                        )
                );
        $msg = "<html><head></head><body>".$msg."</body></html>";

        return $msg;
    }

    public function renderStanfordMessage($piped_msg) {
        // Here is a logo but it is formatted too large.  We should make a nice logo and store it locally for this...
        //                <img src="https://stanfordmedicine.box.com/shared/static/kt332z069mdcc812iwiztw44vumz20b4.jpg" alt="Stanford Medicine" style="width:60%;max-width:400px; min-width:200px; display:block; margin-bottom: 50px;">
        $pre_msg = <<<'EOT'
<body style="background-color: #F0F4F5; padding:20px 0px; margin:0px">
    <div style="margin: auto; border:30px solid #00425a;padding:40px; min-width:400px; max-width:800px;background-color: #FFFFFF;">
        <div style="display:block;border:4px solid #4D4F53;padding:50px;">
            <div style="color: #909090; font-size: 1.2em; line-height: 1.5em; font-family:'PT Serif', Georgia, Calibri, Verdana">
                ---MESSAGE---
            </div>
        </div>
    </div>
</body>
EOT;
        $msg = preg_replace('/\n/','',$pre_msg);
        $msg = str_replace("---MESSAGE---",$piped_msg,$msg);
        logIt($msg, "DEBUG");
        return $msg;
    }

    // Adds a single row with an input
    public function renderRow($id, $label, $value, $help_id = null, $format='input') {
        $help_id = ( $help_id ? $help_id : $id);
        $input_element = '';
        if ($format == 'input') {
            $input_element = RCView::input(array('class'=>'tbi x-form-text x-form-field','id'=>$id, 'value'=>$value));
        } elseif ($format == 'textarea') {
            $input_element = RCView::textarea(array('class'=>'tbi x-form-text x-form-field','id'=>$id), $value) .
                RCView::div(array('style'=>'text-align:right'),
                    RCView::a(array('onclick'=>'growTextarea("' . $id. '")', 'style'=>'font-weight:normal;text-decoration:none;color:#999;font-family:tahoma;font-size:10px;', 'href'=>'javascript:;'),'Expand')
                );
        } else {
            $input_element = "Invalid input format!!!";
        }

        $row = RCView::tr(array(),
            RCView::td(array('class'=>'td1'), self::insertHelp($help_id)).
            RCView::td(array('class'=>'td2'), "<label for='$id'><b>$label:</b></label>").
            RCView::td(array('class'=>'td3'), $input_element)
        );
        return $row;
    }

    // Adds a single row with an input
    public function renderSelectRow($id, $label, $value, $help_id = null, $format='input') {
        $help_id = ( $help_id ? $help_id : $id);
        $input_element = '';
        $instrument_names = REDCap::getInstrumentNames();

        foreach ($instrument_names as $unique_name=>$label) {
            $instrument_options[$unique_name] = $unique_name;
        }

        if ($format == 'select') {
            $input_element = RCView::select(array('id'=>"$id", 'name'=>"$id", 'class'=>"tbi x-form-text x-form-field", 'style'=>'height:20px;border:0px;', 'onchange'=>"$id"), $instrument_options);

        } else {
            $input_element = "Invalid input format!!!";
        }

        $row = RCView::tr(array(),
                RCView::td(array('class'=>'td1'), self::insertHelp($help_id)).
                RCView::td(array('class'=>'td2'), "<label for='$id'><b>$label:</b></label>").
                RCView::td(array('class'=>'td3'), $input_element)
                );
        return $row;
    }

    // Renders the logic row with the text area
    public function renderLogicRow($id, $label, $value) {
        $row = RCView::tr(array(),
            RCView::td(array('class'=>'td1'), self::insertHelp('logic')).
            RCView::td(array('class'=>'td2'), "<label for='logic-$id'><b>$label:</b></label>").
            RCView::td(array('class'=>'td3'),
                RCView::textarea(array('class'=>'tbi x-form-text x-form-field','id'=>"logic-$id",'name'=>"logic-$id",'onblur'=>"testLogic('$id');"), $value).
                RCView::div(array('style'=>'text-align:right'),
                    RCView::a(array('onclick'=>'growTextarea("logic-'.$id.'")', 'style'=>'font-weight:normal;text-decoration:none;color:#999;font-family:tahoma;font-size:10px;', 'href'=>'javascript:;'),'Expand')
                )
            )
        );
        return $row;
    }

    // Renders the enabled row
    public function renderEnabledRow($id, $label, $value) {
        //error_log('ID:'.$id.' and VALUE:'.$value);
        $enabledChecked = ($value == 1 ? 'checked' : '');
        $disabledChecked = ($value == 1 ? '' : 'checked');
        $row = RCView::tr(array(),
            RCView::td(array('class'=>'td1'), self::insertHelp('enabled')).
            RCView::td(array('class'=>'td2'), "<label for='logic-$id'><b>$label:</b></label>").
            RCView::td(array('class'=>'td3'),
                RCView::span(array(),
                    RCView::radio(array('name'=>"enabled-$id",'value'=>'1',$enabledChecked=>$enabledChecked)).
                    RCView::span(array('class'=>'radio-option'),'Enabled') . RCView::SP . RCView::SP .
                    RCView::radio(array('name'=>"enabled-$id",'value'=>'0',$disabledChecked=>$disabledChecked)).
                    RCView::span(array('class'=>'radio-option'),'Disabled')
                )
            )
        );
        return $row;
    }

    // Renders a radio that allows selection of eval once per record(1) or record/event (default/0) - only displayed for longitudinal projects
    public function renderScopeRow($id, $label, $value) {
        //error_log('ID:'.$id.' and VALUE:'.$value);
        $perRecordChecked = ($value == 1 ? 'checked' : '');
        $perRecordEventChecked = ($value == 1 ? '' : 'checked');
        $row = RCView::tr(array(),
            RCView::td(array('class'=>'td1'), self::insertHelp('scope')).
            RCView::td(array('class'=>'td2'), "<label for='scope-$id'><b>$label:</b></label>").
            RCView::td(array('class'=>'td3'),
                RCView::span(array(),
                    RCView::radio(array('name'=>"scope-$id",'value'=>'1',$perRecordChecked=>$perRecordChecked)).
                    RCView::span(array('class'=>'radio-option'),'Once per Record') . RCView::SP . RCView::SP .
                    RCView::radio(array('name'=>"scope-$id",'value'=>'0',$perRecordEventChecked=>$perRecordEventChecked)).
                    RCView::span(array('class'=>'radio-option'),'Once per Record/Event')
                )
            )
        );
        return $row;
    }

    // Renders a test row with dropdowns for the various events/records in the project
    public function renderTestRow($id, $label, $selectedRecord, $selectedEvent) {
        // Make a dropdown that contains all record_ids.
        $data = REDCap::getData('array', NULL, REDCap::getRecordIdField());
        //error_log("data: ".print_r($data,true));
        $record_id_options = array();
        foreach ($data as $record_id => $arr) $record_id_options[$record_id] = $record_id;

        // Get all Events
        $events = REDCap::getEventNames(TRUE,FALSE);
        $row = RCView::tr(array(),
            RCView::td(array('class'=>'td1'), self::insertHelp('test')).
            RCView::td(array('class'=>'td2'), "<label for='test-$id'><b>$label:</b></label>").
            RCView::td(array('class'=>'td3'),
                RCView::span(array(), "Test logic using ".REDCap::getRecordIdField().":".
                    RCView::select(array('id'=>"test_record-$id", 'name'=>"test_record-$id", 'class'=>"tbi x-form-text x-form-field", 'style'=>'height:20px;border:0px;', 'onchange'=>"testLogic('$id');"), $record_id_options, $selectedRecord)
                ).
                RCView::span(array('style'=>'display:'. (REDCap::isLongitudinal() ? 'inline;':'none;')), " of event ".
                    RCView::select(array('id'=>"test_event-$id", 'name'=>"test_event-$id", 'class'=>"tbi x-form-text x-form-field", 'style'=>'height:20px;border:0px;', 'onchange'=>"testLogic('$id');"), $events, $selectedEvent)
                ).
                RCView::span(array(),
                    RCView::button(array('class'=>'jqbuttonmed ui-button ui-widget ui-state-default ui-corner-all ui-button-text-only','onclick'=>'testLogic("'.$id.'");', 'style'=>'margin:0px 10px;'), 'Test Logic').
                    RCView::span(array('id'=>'result-'.$id),'')
                ).
                RCView::span(array(),
                    RCView::button(array('class'=>'jqbuttonmed ui-button ui-widget ui-state-default ui-corner-all ui-button-text-only','onclick'=>'testMessage("'.$id.'");', 'style'=>'margin:0px 10px;'), 'Email Test Message')
                )
            )
        );
        return $row;
    }


    // Renders a row with file/event selectors
    public function renderFileRow($id, $label, $selectedField, $selectedEvent) {
        // Make a dropdown that contains all fields of type file-upload.
        $fields = REDCap::getFieldNames();
        $upload_fields = array('');
        foreach ($fields as $field) {
//           if (REDCap::getFieldType($field)== 'file') array_push($upload_fields,$field);
            if (REDCap::getFieldType($field)== 'file') $upload_fields[$field]=$field;
        }

        // Get all events
        $events = REDCap::getEventNames(TRUE, FALSE);
        $row = RCView::tr(array(),
            RCView::td(array('class' => 'td1'), self::insertHelp('file')) .
            RCView::td(array('class' => 'td2'), "<label for='file_field-$id'><b>$label:</b></label>") .
            RCView::td(array('class' => 'td3'),
                RCView::span(array(), "Select field that contains file to include attachment in email: " .
                    RCView::select(array('id' => "file_field-$id", 'name' => "file_field-$id", 'class' => "tbi x-form-text x-form-field", 'style' => 'height:20px;border:0px;'), $upload_fields, $selectedField)
                ) .
                RCView::span(array('style' => 'display:' . (REDCap::isLongitudinal() ? 'inline;' : 'none;')), " of event " .
                    RCView::select(array('id' => "file_event-$id", 'name' => "file_event-$id", 'class' => "tbi x-form-text x-form-field", 'style' => 'height:20px;border:0px;'), $events, $selectedEvent)
                )
            )
        );
        return $row;
    }


    // Allows user to pick which email template to use
    // standard = boxformat from autonotify1
    // text-only = only send the text included in the body
    // stanford = nicely formatted stanford email
    public function renderBodyTemplate($id, $label, $selectedTemplate) {
        $options = array(
            'standard' => 'Standard (red box with link to record)',
            'text-only' => 'Text-only (sends plain message body)',
            'stanford' => 'Stanford (wrap body in nice Stanford theme html message)'
        );
        $row = RCView::tr(array(),
            RCView::td(array('class'=>'td1'), self::insertHelp('template')).
            RCView::td(array('class'=>'td2'), "<label for='template-$id'><b>$label:</b></label>").
            RCView::td(array('class'=>'td3'),
                RCView::span(array(), "Message template:".
                    RCView::select(array('id'=>"$id", 'name'=>"$id", 'class'=>"tbi x-form-text x-form-field", 'style'=>'height:20px;border:0px;'), $options, $selectedTemplate)
                )
            )
        );
        return $row;
    }

    public function insertHelp($e) {
        return "<span><a href='javascript:;' id='".$e."_info_trigger' info='".$e."_info' class='info' title='Click for help'><img class='imgfix' style='height: 16px; width: 16px;' src='".APP_PATH_IMAGES."help.png'></a></span>";
    }

    public function renderHelpDivs() {
        $help = RCView::div(array('id'=>'to_info','style'=>'display:none;'),
                RCView::p(array(),'The following are valid email formats.  Piping is also supported.'.
                    RCView::ul(array('style'=>'margin-left:15px;'),
                        RCView::li(array(),'&raquo; user@example.com').
                        RCView::li(array(),'&raquo; user@example.com, anotheruser@example.com')
                    )
                )
            ).RCView::div(array('id'=>'from_info','style'=>'display:none;'),
                RCView::p(array(),'Please note that some spam filters my classify this email as spam - you should test prior to going into production.'.
                    RCView::ul(array('style'=>'margin-left:15px;'),
                        RCView::li(array(),'A valid format is: user@example.com')
                    )
                )
            ).RCView::div(array('id'=>'subject_info','style'=>'display:none;'),
                RCView::p(array(),'To send a secure message, prefix the subject with <B>SECURE:</b>'.
                    RCView::ul(array('style'=>'margin-left:15px;'),
                        RCView::li(array(),'&raquo; Secure messages open normally for Stanford SOM users but require additional authentication for non-Stanford SOM email accounts.').
                    	RCView::li(array(),'Piping is also supported.')
                    )
                )
            ).RCView::div(array('id'=>'body_info','style'=>'display:none;'),
                RCView::p(array(),'This message will be included in the alert.  A number of custom-piping options are available:
                    <dt>Surveys:</dt>
                        <dl><b>[survey-link:form_name]</b> OR
                            <br/><b>[event_1_arm_1][survey-link:form_name]</b> if longitudinal OR
                            <br/><b>[survey-queue-link]</b>
                        </dl>
                    <dt>File (not-inline, only 1 file):</dt>
                        <dl><b>[file:field_name]</b> OR <b>[event_1_arm_1][file:field_name]</b> if longitudinal</dl>
                    <dt>REDCap Link (back to record that triggered alert):</dt>
                        <dl><b>[redcap-link:form_name]</b> OR <b>[event_1_arm_1][redcap-link:form_name]</b>')
            ).RCView::div(array('id'=>'files_info','style'=>'display:none;'),
                RCView::p(array(),'Uploaded files can be attached to this email. </br></br>Any uploaded files in the fieldname specified will be attached. Prepend with a \'file:\' tag. </br>For example: [file:filed_name]. </br></br>If the project is longitudinal, add the event name before the form name. </br>For example: [event_1_arm_1][file:field_name]')
            ).RCView::div(array('id'=>'title_info','style'=>'display:none;'),
                RCView::p(array(),'An alert will only be fired once per title per record or record/event depending on the setting of the scope.  This means that if you rename a trigger it may re-fire next time you save a previously true record.  The title of the alert will also be included in the notification email.')
            ).
            RCView::div(array('id'=>'logic_info','style'=>'display:none;'),
                RCView::p(array(),'This is an expression that will be evaluated to determine if the saved record should trigger an alert.  If you include a datediff with "today" it will be evaluated once per day at 9am.  You should use the same format you use for branching logic.')
            ).
            RCView::div(array('id'=>'scope_info','style'=>'display:none;'),
                RCView::p(array(),'By default, each trigger will fire once per title/record/event.  So, if you had a repeating survey with a sensitive question in many events, it would re-fire for each event.  However, if you have an alert which should only fire once per record (say, in demographics) - then select once per record.')
            ).
            RCView::div(array('id'=>'test_info','style'=>'display:none;'),
                RCView::p(array(),'You can test your logical expression by selecting a record (and event) to evaluate the expression against.  This is useful if you have an existing record that would be a match for your condition.')
            ).RCView::div(array('id'=>'post_script_det_url_info','style'=>'display:none;'),
                RCView::p(array(),'By inserting a pipe-separated (e.g. | char) list of valid URLs into this field you can trigger additional DETs <b>AFTER</b> this one is complete.  This is useful for chaining DETs together.')
            ).RCView::div(array('id'=>'pre_script_det_url_info','style'=>'display:none;'),
                RCView::p(array(),'By inserting a pipe-separated (e.g. | char) list of valid URLs into this field you can trigger additional DETs to run <b>BEFORE</b> this notification trigger.  This might be useful for running an auto-scoring algorithm, for example.')
            );



        echo $help;
    }

} // End of Class



function renderTemporaryMessage($msg, $title='') {
    $id = uniqid();
    $html = RCView::div(array('id'=>$id,'class'=>'green','style'=>'margin-top:20px;padding:10px 10px 15px;'),
        RCView::div(array('style'=>'text-align:center;font-size:20px;font-weight:bold;padding-bottom:5px;'), $title).
        RCView::div(array(), $msg)
    );
    $js = "<script type='text/javascript'>
	$(function(){
		t".$id." = setTimeout(function(){
			$('#".$id."').hide('blind',1500);
		},10000);
		$('#".$id."').bind( 'click', function() {
			$(this).hide('blind',1000);
			window.clearTimeout(t".$id.");
		});
	});
	</script>";
    echo $html . $js;
}


// Get variable or empty string from _REQUEST
function voefr($var) {
    $result = isset($_REQUEST[$var]) ? (string) $_REQUEST[$var] : "";
    return $result;
}

function insertImage($i) {
    return "<img class='imgfix' style='height: 16px; width: 16px; vertical-align: middle;' src='".APP_PATH_IMAGES.$i.".png'>";
}

#display an error from scratch
function showError($msg) {
    $HtmlPage = new HtmlPage();
    $HtmlPage->PrintHeaderExt();
    echo "<div class='red'>$msg</div>";
}

function injectPluginTabs($pid, $plugin_path, $plugin_name) {
    $msg = '<script>
		jQuery("#sub-nav ul li:last-child").before(\'<li class="active"><a style="font-size:13px;color:#393733;padding:4px 9px 7px 10px;" href="'.$plugin_path.'"><img src="' . APP_PATH_IMAGES . 'email.png" class="imgfix" style="height:16px;width:16px;"> ' . $plugin_name . '</a></li>\');
		</script>';
    echo $msg;
}

function logIt($msg, $level = "INFO") {
    global $log_file, $project_id;
    if (! file_exists($log_file)) {
        file_put_contents($log_file, "Initializing " . AutoNotify::PluginName . " log file");
    }
    // Switch to php error log if can't write to the main log file
    if (! file_exists($log_file)) {
        $log_file = ini_get('error_log');
    }
    file_put_contents( $log_file,
        date( 'Y-m-d H:i:s' ) . "\t" . $project_id . "\t" . $level . "\t" . $msg . "\n",
        FILE_APPEND );
}

// Function for decrypting (from version 643)
function decrypt_643($encrypted_data, $custom_salt=null)
{
    if (!openssl_loaded(true)) return false;
    // $salt from db connection file
    global $salt;
    // If $custom_salt is not provided, then use the installation-specific $salt value
    $this_salt = ($custom_salt === null) ? $salt : $custom_salt;
    // If salt is longer than 32 characters, then truncate it to prevent issues
    if (strlen($this_salt) > 32) $this_salt = substr($this_salt, 0, 32);
    // Define an encryption/decryption variable beforehand
    defined("MCRYPT_IV") or define("MCRYPT_IV", mcrypt_create_iv(mcrypt_get_iv_size(MCRYPT_RIJNDAEL_256, MCRYPT_MODE_ECB), MCRYPT_RAND));
    // Decrypt and return
    return rtrim(mcrypt_decrypt(MCRYPT_RIJNDAEL_256, $this_salt, base64_decode($encrypted_data), MCRYPT_MODE_ECB, MCRYPT_IV),"\0");
}

function decrypt_me($encrypted_data) {
    // Try decrypting using the current format:
    $t1 = decrypt($encrypted_data);
    $t1_json = json_decode($t1,true);
    if (json_last_error() == JSON_ERROR_NONE) return $t1;
    $t2 = decrypt_643($encrypted_data);
    $t2_json = json_decode($t2,true);
    if (json_last_error() == JSON_ERROR_NONE) return $t2;
    print "ERROR DECODING";
    return false;
}

function viewLog($file) {
    // Render the page
    $page = new HtmlPage();
    $page->addExternalJS("https://cdnjs.cloudflare.com/ajax/libs/ace/1.2.2/ace.js");
    //$page->addExternalJS("https://cdnjs.cloudflare.com/ajax/libs/ace/1.2.2/ext-searchbox.js");
    //$page->addExternalJS("https://rawgithub.com/ajaxorg/ace-builds/master/src/ext-language_tools.js");
    //$page->addExternalJs("https://code.jquery.com/jquery-2.0.3.min.js");
    $page->addExternalJS(APP_PATH_JS . "base.js");
    $page->addStylesheet("jquery-ui.min.css", 'screen,print');
    //$page->addStylesheet("style.css", 'screen,print');
    //$page->addStylesheet("home.css", 'screen,print');
    $page->setPageTitle("Log View");
    $page->PrintHeader();

    //	require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';
    print RCView::div(
        array('class'=>'chklisthdr', 'style'=>'color:rgb(128,0,0);margin-top:10px;'),
        "Custom Log File: " . $file
    );

    ?>
    <div id="editor" style="height: 500px; width: 100%; display:none;"><?php
        // Easy method
        //readfile_chunked($file);

        // harder method
        $lines = file($file);
        $re = "/^.+\\t(\\d+)\\t/";
        global $project_id;
        foreach ($lines as $k => $line) {
            if (preg_match($re,$line, $matches)) {
                if ($matches[1] != $project_id) {
                    unset($lines[$k]);
                }
            }
        }
        echo implode("",$lines);
        ?></div>
    <div id="commandline" style="margin-top:10px;">This is the global <?php echo AutoNotify::PluginName ?> log.  Refresh for an update.</div>
    <script>
        $(document).ready(function(){
            //var langTools = ace.require("ace/ext/language_tools");
            var editor = ace.edit("editor");
            editor.$blockScrolling = Infinity;
            editor.resize(true);
            editor.setReadOnly(true);
            editor.setOptions({
                autoScrollEditorIntoView: true,
                showPrintMargin: false,
                fontSize: "8pt"
            });
            $('#editor').css({'border':'1px solid'}).fadeIn('slow');
            var row = editor.session.getLength() - 1
            editor.gotoLine(row, 0);
            editor.resize(true); // There is a bug in current version requiring this...
            editor.scrollToLine(row);
        });
    </script>
    <?php
}




?>
