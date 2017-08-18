<?php
/**
 * Bag of utility tools that can be used for multiple projects
 */

class Utility {
    /***
     * Get the Edoc ID for the given project and fieldname
     * @param unknown $project_id
     * @param unknown $filename
     * @return unknown
     */
    public static function getEdocID($project_id, $event_id, $record_id, $field_name) {
        // Lookup the EdocID  for the supplied fieldname in this project
        $sql = sprintf("select value from redcap_data where project_id = '%s' and record = '%s' and field_name = '%s'",
                db_real_escape_string(strip_tags($project_id)),
                db_real_escape_string(strip_tags($record_id)),
                db_real_escape_string(strip_tags($field_name))
                );
        if (!(strlen($event_id) == 0)) {
            $sql_event = sprintf(" and event_id = '%s'",
                    db_real_escape_string(strip_tags($event_id)));
            $sql = $sql . $sql_event;
        }

        // logit("SQL is $sql");
        $q = db_query($sql);
        if (db_num_rows($q) < 1) {
            // logIt('Unable to find a valid EdocID for $fieldname.');
            return null;
        }
    
        $token = db_result($q, 0, 'value');
        return $token;
    }
    
    
    /**
     * Retrieve the API token given a project_id and token_user
     * @param unknown $project_id
     * @param unknown $token_user
     * @return unknown
     */
    public static function getAPIToken($project_id, $token_user) {
        // Lookup the API token for the supplied token_user
        $sql = sprintf("SELECT api_token FROM redcap.redcap_user_rights where project_id = '%s' and username = '%s' AND api_token IS NOT NULL limit 1",
                db_real_escape_string(strip_tags($project_id)),
                db_real_escape_string(strip_tags($token_user))
                );
        $q = db_query($sql);
        if (db_num_rows($q) < 1) {
            // logIt('Unable to find a valid API token for $token_user.');
            exit();
        }
        
        $token = db_result($q, 0, 'api_token');
        //define('API_TOKEN',$token);
        return $token;
    }
    
    /**
     * Checks list of emails and returns count of valid Emails
     * @param unknown $email
     * @return boolean
     */
    public static function isValidEmail($email_list){
        $from_array = preg_split( "/(,|;)/", $email_list );
        $int = 0;

        foreach ($from_array as &$value) {
            $int = $int + self::checkOneEmail(trim($value));
        }

        if ($int == 0) {
            return null;
        } 
        return $int;
        
    }
    
    /**
     *
     * @param unknown $email
     * @return boolean
     */
    public static function checkOneEmail($email){
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    /**
     * 
     * @param unknown $var
     * @return string|unknown
     */
    public static function voefr($var) {
        $result = isset($_REQUEST[$var]) ? $_REQUEST[$var] : "";
        return $result;
    }
    
    /**
     * Check the depth of an array
     * 
     * @param unknown $array
     * @return number
     */
    function getArrayDepth($array) {
        $max_indentation = 1;
    
        $array_str = print_r($array, true);
        $lines = explode("\n", $array_str);
    
        foreach ($lines as $line) {
            $indentation = (strlen($line) - strlen(ltrim($line))) / 4;
    
            if ($indentation > $max_indentation) {
                $max_indentation = $indentation;
            }
        }
    
        return ceil(($max_indentation - 1) / 2) + 1;
    }
    
    /**
     * Display a bold message
     * 
     * @param unknown $msg
     */
    function showMessage($msg) {
        global $isMobileDevice;
        if ($isMobileDevice || $_REQUEST['mobile']) {
            echo "<ul class='form'><li class='error'>$msg</li></ul>";
        } else {
            echo "<div class='red' style='font-size:18px; margin-bottom: 15px;'><center>$msg</center></div>";
        }
    }
    
    
    /**
     * Creates random alphanumeric string
     * @param number $length
     * @param string $addNonAlphaChars
     * @param string $onlyHandEnterableChars
     * @param string $alphaCharsOnly
     * @return unknown
     */
    public static function generateRandomString($length=25, $addNonAlphaChars=false, $onlyHandEnterableChars=false, $alphaCharsOnly=false) {
        // Use character list that is human enterable by hand or for regular hashes (i.e. for URLs)
        if ($onlyHandEnterableChars) {
            $characters = '34789ACDEFHJKLMNPRTWXY'; // Potential characters to use (omitting 150QOIS2Z6GVU)
        } else {
            $characters = 'abcdefghijkmnopqrstuvwxyzABCDEFGHIJKLMNPQRSTUVWXYZ23456789'; // Potential characters to use
            if ($addNonAlphaChars) $characters .= '~.$#@!%^&*-';
        }
        // If returning only letter, then remove all non-alphas from $characters
        if ($alphaCharsOnly) {
            $characters = preg_replace("/[^a-zA-Z]/", "", $characters);
        }
        // Build string
        $strlen_characters = strlen($characters);
        $string = '';
        for ($p = 0; $p < $length; $p++) {
            $string .= $characters[mt_rand(0, $strlen_characters-1)];
        }
        // If hash matches a number in Scientific Notation, then fetch another one
        // (because this could cause issues if opened in certain software - e.g. Excel)
        if (preg_match('/^\d+E\d/', $string)) {
            return generateRandomString($length, $addNonAlphaChars, $onlyHandEnterableChars);
        } else {
            return $string;
        }
    }
    
    /**
     *
     * @param unknown $msg
     * @param string $level
     */
    public static function logIt($msg, $level = "INFO") {
        global $log_file, $project_id;
        if ( !empty($log_file) ) file_put_contents( $log_file,
                date( 'Y-m-d H:i:s' ) . "\t" . $project_id . "\t" . $level . "\t" . $msg . "\n",
                FILE_APPEND );
    }
}
