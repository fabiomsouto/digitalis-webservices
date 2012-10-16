<?php
/**
 * Digitalis web services external functions and service definitions.
 *
 * @package    local_digitalis
 * @copyright  2012 Digitalis Informática
 * @author     Fábio Souto
 */

/**
 * External Web Service Template
 *
 * @package    local_digitalis
 * @copyright  2012 Digitalis Informática
 * @author    Fábio Souto
 */
require_once($CFG->libdir . "/externallib.php");

class local_digitalis_external extends external_api {

   /**
    * Returns description of method parameters
    *
    * @return external_function_parameters
    * @since Moodle 2.3
    */
   public static function unenrol_users_parameters() {
       return new external_function_parameters(
               array(
                   'unenrolments' => new external_multiple_structure(
                           new external_single_structure(
                                   array(
                                       'roleid' => new external_value(PARAM_INT, 'Role to unassign from the user'),
                                       'userid' => new external_value(PARAM_INT, 'The user that is going to be unenrolled'),
                                       'courseid' => new external_value(PARAM_INT, 'The course to unenrol the user role from')
                                   )
                           )
                   )
               )
       );
   }

   /**
    * Unenrolment of users
    *
    * Function throw an exception at the first error encountered.
    * @param array $unenrolments An array of user unenrolments
    * @since Moodle 2.3
    */
   public static function unenrol_users($unenrolments) {
       global $DB, $CFG;
       require_once($CFG->libdir . '/enrollib.php');

       $params = self::validate_parameters(self::unenrol_users_parameters(),
               array('unenrolments' => $unenrolments));

       $transaction = $DB->start_delegated_transaction(); // Rollback all unenrolment if an error occurs, (except if the DB doesn't support it).

       // Retrieve the manual enrolment plugin.
       $enrol = enrol_get_plugin('manual');
       if (empty($enrol)) {
           throw new moodle_exception('manualpluginnotinstalled', 'enrol_manual');
       }

       foreach ($params['unenrolments'] as $unenrolment) {
           // Ensure the current user is allowed to run this function in the enrolment context.
           $context = get_context_instance(CONTEXT_COURSE, $unenrolment['courseid']);
           self::validate_context($context);

           // Check that the user has the permission to manual enrol.
           require_capability('enrol/manual:unenrol', $context);

           // Throw an exception if user is not able to assign the role.
           $roles = get_assignable_roles($context);
           if (!key_exists($unenrolment['roleid'], $roles)) {
               $errorparams = new stdClass();
               $errorparams->roleid = $unenrolment['roleid'];
               $errorparams->courseid = $unenrolment['courseid'];
               $errorparams->userid = $unenrolment['userid'];
               throw new moodle_exception('wsusercannotassign', 'enrol_manual', '', $errorparams);
           }

           // Check manual enrolment plugin instance is enabled/exist.
           $enrolinstances = enrol_get_instances($unenrolment['courseid'], true);
           foreach ($enrolinstances as $courseenrolinstance) {
             if ($courseenrolinstance->enrol == "manual") {
                 $instance = $courseenrolinstance;
                 break;
             }
           }
           if (empty($instance)) {
             $errorparams = new stdClass();
             $errorparams->courseid = $unenrolment['courseid'];
             throw new moodle_exception('wsnoinstance', 'enrol_manual', $errorparams);
           }

           // Check that the plugin accept enrolment (it should always the case, it's hard coded in the plugin).
           if (!$enrol->allow_enrol($instance)) {
               $errorparams = new stdClass();
               $errorparams->roleid = $unenrolment['roleid'];
               $errorparams->courseid = $unenrolment['courseid'];
               $errorparams->userid = $unenrolment['userid'];
               throw new moodle_exception('wscannotenrol', 'enrol_manual', '', $errorparams);
           }

           $enrol->unenrol_user($instance, $unenrolment['userid'], $unenrolment['roleid']);
       }

       $transaction->allow_commit();
   }

   /**
    * Returns description of method result value
    *
    * @return null
    * @since Moodle 2.3
     */
   public static function unenrol_users_returns() {
       return null;
   }

  /**
   * Returns description of method parameters
   *
   * @return external_function_parameters
   */
    public static function get_users_parameters() {
        return new external_function_parameters(
            array(
                'criteria' => new external_multiple_structure(
                    new external_single_structure(
                        array(
                            'key'        => new external_value(PARAM_ALPHA, 'the user column to search, expected keys (value format) are:
                                                                                "id" (int) the user id,
                                                                                "fullname" (string) the user full name (ATTENTION: searching by fullname can be extremely slow!),
                                                                                "idnumber" (string) the user idnumber,
                                                                                "username" (string) the user username,
                                                                                "email" (string) the user email (ATTENTION: searching by email can be extremely slow!),
                                                                                "auth" (plugin) the user auth plugin'),
                            'value'      => new external_value(PARAM_RAW, 'the value to match')
                        )
                    ), 'the key/value pairs to be considered in user search. If several are specified, they will be AND\'ed together', VALUE_DEFAULT, array()),
                'limitfrom'             => new external_value(PARAM_INT, 'return a subset of users, starting at this point', VALUE_DEFAULT, 0),
                'limitnum'              => new external_value(PARAM_INT, 'return a subset comprising this many records in total', VALUE_DEFAULT, 30),
            )
        );
    }

    /**
     * Get user information, filtered by key/value pairs.
     * This function can work in three different ways.
     * 1. Specify several times a key (fullname => 'user1', fullname => 'user2', ...) ...
     * This will most likely return a user per key, which is useful for getting several different users at the same time.
     * 2. Specifiy different keys only once (fullname => 'user1', auth => 'manual', ...) ...
     * This is useful for restricting search results
     * 3. Without criteria, which is useful for returning all the users to which you have access to details.
     *
     * @param array $criteria  key/value pairs to consider in user search, AND'ed together.
     * @return array An array of arrays containg user profiles that match the given criteria.
     */
    public static function get_users($criteria = array(), $limitfrom = 0, $limitnum = 30) {
        global $CFG, $USER, $DB;
        require_once($CFG->dirroot . "/user/lib.php");

        $params = self::validate_parameters(self::get_users_parameters(),
            array('criteria' => $criteria, 'limitfrom' => $limitfrom, 'limitnum' => $limitnum));

        // This array will keep all the users that are allowed to be searched, according to the current user's privileges.
        $allowedusers = array();

        // This array will keep the results to be returned.
        $result = array();
        if (!empty($params['criteria'])) {

            // Check if the specified criteria is a legal case.
            // There are two legal situations.
            // 1. Multiple times the same criteria key. (diverse = false).
            // 2. Different criteria keys but only one occurance for each key (diverse = true).
            // For any other case we throw an exception.
            $diversecriteria = self::is_diverse_criteria($params['criteria']);

            if ($diversecriteria) {
                $allowedusers = self::filter_users_to_criteria($params['criteria']);
            } else {
                foreach ($params['criteria'] as $crit) {
                    $allowedusers = $allowedusers + self::filter_users_to_criteria(array('criteria' => $crit));
                }
            }
        }

        // In this situation, since there are no users that can be searched, there is no point to proceed further.
        if (empty($allowedusers) and !empty($params['criteria'])) {
            return $result;
        }

        // Paginate the allowed users to save on the JOIN below.
        $alloweduserschunks = array_chunk($allowedusers, $params['limitnum'], true);
        $allowedusers = $alloweduserschunks[$params['limitfrom']];

        // The following query is performed to save multiple get_context_instance SQL requests.
        list($uselect, $ujoin) = context_instance_preload_sql('u.id', CONTEXT_USER, 'ctx');
        $conditions = array();
        if (!empty($params['criteria'])) {
            list($uin, $conditions) = $DB->get_in_or_equal(array_keys($allowedusers), SQL_PARAMS_NAMED);
            $usersql = "SELECT u.* $uselect
                  FROM {user} u $ujoin
                  WHERE u.id $uin";
            $usersql .= " ORDER BY u.id ASC";
            $users = $DB->get_recordset_sql($usersql, $conditions);
        } else {
            $usersql = "SELECT u.* $uselect
                  FROM {user} u $ujoin
                  ORDER BY u.id ASC";
            $users = $DB->get_recordset_sql($usersql, $conditions, $params['limitfrom'], $params['limitnum']);
        }

        foreach ($users as $user) {
            context_helper::preload_from_record($user);
            $usercontext = context_user::instance($user->id);
            self::validate_context($usercontext);

            $userdetails = self::user_get_user_details_courses($user);

            if ($userdetails != null) {
                // Fields matching permissions from /user/editadvanced.php.
                $hasuserupdatecap =  has_capability('moodle/user:update', context_system::instance());
                $currentuser = ($user->id == $USER->id);

                if ($currentuser or $hasuserupdatecap) {
		    $userdetails['auth'] = $user->auth;	
                    $userdetails['confirmed']  = $user->confirmed;
                    $userdetails['idnumber']   = $user->idnumber;
                    $userdetails['lang']       = $user->lang;
                    $userdetails['theme']      = $user->theme;
                    $userdetails['timezone']   = $user->timezone;
                    $userdetails['mailformat'] = $user->mailformat;
                }
		$result[] = $userdetails;
            }
        }
        return $result;
    }

    /**
     * Auxiliary for webservice get_users.
     * Allows to do a first filtering of users, according to some criteria.
     *
     * @static
     * @param $params The specified webservice parameters.
     * @return array An array containing the user identifiers.
     * @throws moodle_exception
     */
    private static function filter_users_to_criteria($params) {
        global $DB, $USER;
        $result = array();
        $firstcriteria = true;
        foreach ($params as $crit) {
            $key = trim($crit['key']);
            $siteadmin = is_siteadmin($USER);
            $value = null;
            switch ($key) {
                case 'id':
                    $value = clean_param($crit['value'], PARAM_INT);
                    if (!empty($result) || $firstcriteria) {
                        // We add the users since later the function that gets user details will perform complex capability checks.
                        $returnedusers = $DB->get_records('user', array('id' => $value), 'id ASC', 'id');
                        $result = $firstcriteria ? $returnedusers : array_intersect_key($result, $returnedusers);
                    }
                    break;
                case 'idnumber':
                    if (has_capability('moodle/user:update', context_system::instance())) {
                        $value = clean_param($crit['value'], PARAM_RAW);
                        if (!empty($result) || $firstcriteria) {
                            $returnedusers = $DB->get_records('user', array('idnumber' => $value), 'id ASC', 'id');
                            $result = $firstcriteria ? $returnedusers : array_intersect_key($result, $returnedusers);
                        }
                    } else {
                        throw new moodle_exception('missingrequiredcapability', 'webservice', '', $key);
                    }
                    break;
                case 'username':
                    if ($siteadmin || ($USER->username == $value)) {
                        $value = clean_param($crit['value'], PARAM_USERNAME);
                        if (!empty($result) || $firstcriteria) {
                            $returnedusers = $DB->get_records('user', array('username' => $value), 'id ASC', 'id');
                            $result = $firstcriteria ? $returnedusers : array_intersect_key($result, $returnedusers);
                        }
                    } else {
                        throw new moodle_exception('missingrequiredcapability', 'webservice', '', $key);
                    }
                    break;
                case 'deleted':
                    if ($siteadmin) {
                        $value = clean_param($crit['value'], PARAM_BOOL);
                        if (!empty($result) || $firstcriteria) {
                            $returnedusers = $DB->get_records('user', array('deleted' => $value), 'id ASC', 'id');
                            $result = $firstcriteria ? $returnedusers : array_intersect_key($result, $returnedusers);
                        }
                    } else {
                        throw new moodle_exception('missingrequiredcapability', 'webservice', '', $key);
                    }
                    break;
                case 'fullname':
                    $value = clean_param($crit['value'], PARAM_NOTAGS);
                    $fullname = $DB->sql_fullname();
                    if (!empty($result) || $firstcriteria) {
                        $returnedusers = $DB->get_records_select('user', $DB->sql_like($fullname, ':searchfullname', false), array('searchfullname' => "$value"), 'id ASC', 'id');
                        $result = $firstcriteria ? $returnedusers : array_intersect_key($result, $returnedusers);
                    }
                    break;
                case 'email':
                    $value = clean_param($crit['value'], PARAM_EMAIL);
                    // We add the users since later the function that gets user details will perform complex capability checks.
                    if (!empty($result) || $firstcriteria) {
                        $returnedusers = $DB->get_records_select('user', $DB->sql_like('email', ':searchemail', false), array('searchemail' => "$value"), 'id ASC', 'id');
                        $result = $firstcriteria ? $returnedusers : array_intersect_key($result, $returnedusers);
                    }
                    break;
                case 'auth':
                    if (has_capability('moodle/user:update', context_system::instance())) {
                        $value = clean_param($crit['value'], PARAM_PLUGIN);
                        if (!empty($result) || $firstcriteria) {
                            $returnedusers = $DB->get_records('user', array('auth' => $value), 'id ASC', 'id');
                            $result = $firstcriteria ? $returnedusers : array_intersect_key($result, $returnedusers);
                        }
                    } else {
                        throw new moodle_exception('missingrequiredcapability', 'webservice', '', $key);
                    }
                    break;
                default:
                    throw new moodle_exception('invalidextparam', 'webservice', '', $key);
            }
            $firstcriteria = false;
        }
        return $result;
    }

    /**
     * Auxiliary function for webservice get_users.
     * Returns the type of criteria specified, which can be one of two types.
     * 1. Multiple times the same criteria key.
     * 2. Different criteria keys but only one occurance for each key.
     *
     * @param $criteria The criteria
     * @return bool T if the criteria is diverse (case 2.) F otherwise (case 1.)
     * @throws moodle_exceptioninvalidkey If the criteria keys are incorrectly specified.
     */
    private static function is_diverse_criteria($criteria) {
        $result = false;
        $criteriacount = array();

        foreach ($criteria as $crit) {
            $criteriacount[$crit['key']]++;
        }

        if (count($criteriacount) > 1) {
            foreach ($criteriacount as $crit) {
                if ($crit > 1) {
                    throw new moodle_exception('invalidkey');
                }
            }
            $result = true;
        }

        return $result;
    }

/**
 * Tries to obtain user details, either recurring directly to the user's system profile
 * or trough one of the user's course enrollments (course profile).
 *
 * @param $user The user.
 * @param $courses The courses that the user is enrolled in.
 * @param array $userfields The userfields that are to be returned.
 * @return null if unsuccessful or the allowed user details.
 */
private static function user_get_user_details_courses($user) {
    global $USER, $CFG;
    require_once($CFG->dirroot . "/user/lib.php");

    $userdetails = null;

    //  Get the courses that the user is enrolled in (only active).
    $courses = enrol_get_users_courses($user->id, true);

    $systemprofile = self::can_view_user_details_cap($user);
    $systemprofile |= ($user->id == $USER->id);
    $systemprofile |= has_coursecontact_role($user->id);

    // Try using system profile
    if ($systemprofile) {
        $userdetails = user_get_user_details($user, null);
    } else {
        // Try through course profile
        foreach ($courses as $course) {
            $courseprofile = self::can_view_user_details_cap($user, $course);
            $courseprofile |= ($user->id == $USER->id);
            $courseprofile |= has_coursecontact_role($user->id);

            if ($courseprofile) {
                $userdetails = user_get_user_details($user, $course);
            }
        }
    }

    return $userdetails;
}

/**
 * Does $USER have the necessary capabilities to obtain user details
 * using a mdl_user record?
 * @param $user The mdl_user record
 * @param null $course Null only to consider system profile or course to also consider that course's profile.
 * @return bool T if he does, false otherwise
 */
private static function can_view_user_details_cap($user, $course = null) {
    if (!empty($course)) {
        $context = get_context_instance(CONTEXT_COURSE, $course->id);
        $usercontext = get_context_instance(CONTEXT_USER, $user->id);
        $result = (has_capability('moodle/user:viewdetails', $context) || has_capability('moodle/user:viewdetails', $usercontext));
    }
    else {
        $context = get_context_instance(CONTEXT_USER, $user->id);
        $usercontext = $context;
        $result = has_capability('moodle/user:viewdetails', $usercontext);
    }
    return $result;
}


    /**
     * Returns description of method result value
     *
     * @return external_description
     * @since Moodle 2.3
     */
    public static function get_users_returns() {
        return new external_multiple_structure(
            new external_single_structure(
                array(
                    'id'    => new external_value(PARAM_NUMBER, 'ID of the user'),
                    'username'    => new external_value(PARAM_RAW, 'Username policy is defined in Moodle security config', VALUE_OPTIONAL),
                    'firstname'   => new external_value(PARAM_NOTAGS, 'The first name(s) of the user', VALUE_OPTIONAL),
                    'lastname'    => new external_value(PARAM_NOTAGS, 'The family name of the user', VALUE_OPTIONAL),
                    'fullname'    => new external_value(PARAM_NOTAGS, 'The fullname of the user'),
                    'email'       => new external_value(PARAM_TEXT, 'An email address - allow email as root@localhost', VALUE_OPTIONAL),
                    'address'     => new external_value(PARAM_MULTILANG, 'Postal address', VALUE_OPTIONAL),
                    'phone1'      => new external_value(PARAM_NOTAGS, 'Phone 1', VALUE_OPTIONAL),
                    'phone2'      => new external_value(PARAM_NOTAGS, 'Phone 2', VALUE_OPTIONAL),
                    'icq'         => new external_value(PARAM_NOTAGS, 'icq number', VALUE_OPTIONAL),
                    'skype'       => new external_value(PARAM_NOTAGS, 'skype id', VALUE_OPTIONAL),
                    'yahoo'       => new external_value(PARAM_NOTAGS, 'yahoo id', VALUE_OPTIONAL),
                    'aim'         => new external_value(PARAM_NOTAGS, 'aim id', VALUE_OPTIONAL),
                    'msn'         => new external_value(PARAM_NOTAGS, 'msn number', VALUE_OPTIONAL),
                    'department'  => new external_value(PARAM_TEXT, 'department', VALUE_OPTIONAL),
                    'institution' => new external_value(PARAM_TEXT, 'institution', VALUE_OPTIONAL),
                    'interests'   => new external_value(PARAM_TEXT, 'user interests (separated by commas)', VALUE_OPTIONAL),
                    'firstaccess' => new external_value(PARAM_INT, 'first access to the site (0 if never)', VALUE_OPTIONAL),
                    'lastaccess'  => new external_value(PARAM_INT, 'last access to the site (0 if never)', VALUE_OPTIONAL),
                    'auth'        => new external_value(PARAM_PLUGIN, 'Auth plugins include manual, ldap, imap, etc', VALUE_OPTIONAL),
                    'confirmed'   => new external_value(PARAM_NUMBER, 'Active user: 1 if confirmed, 0 otherwise', VALUE_OPTIONAL),
                    'idnumber'    => new external_value(PARAM_RAW, 'An arbitrary ID code number perhaps from the institution', VALUE_OPTIONAL),
                    'lang'        => new external_value(PARAM_SAFEDIR, 'Language code such as "en", must exist on server', VALUE_OPTIONAL),
                    'theme'       => new external_value(PARAM_PLUGIN, 'Theme name such as "standard", must exist on server', VALUE_OPTIONAL),
                    'timezone'    => new external_value(PARAM_TIMEZONE, 'Timezone code such as Australia/Perth, or 99 for default', VALUE_OPTIONAL),
                    'mailformat'  => new external_value(PARAM_INTEGER, 'Mail format code is 0 for plain text, 1 for HTML etc', VALUE_OPTIONAL),
                    'description' => new external_value(PARAM_RAW, 'User profile description', VALUE_OPTIONAL),
                    'descriptionformat' => new external_value(PARAM_INT, 'User profile description format', VALUE_OPTIONAL),
                    'city'        => new external_value(PARAM_NOTAGS, 'Home city of the user', VALUE_OPTIONAL),
                    'url'         => new external_value(PARAM_URL, 'URL of the user', VALUE_OPTIONAL),
                    'country'     => new external_value(PARAM_ALPHA, 'Home country code of the user, such as AU or CZ', VALUE_OPTIONAL),
                    'profileimageurlsmall' => new external_value(PARAM_URL, 'User image profile URL - small version'),
                    'profileimageurl' => new external_value(PARAM_URL, 'User image profile URL - big version'),
                    'customfields' => new external_multiple_structure(
                        new external_single_structure(
                            array(
                                'type'  => new external_value(PARAM_ALPHANUMEXT, 'The type of the custom field - text field, checkbox...'),
                                'value' => new external_value(PARAM_RAW, 'The value of the custom field'),
                                'name' => new external_value(PARAM_RAW, 'The name of the custom field'),
                                'shortname' => new external_value(PARAM_RAW, 'The shortname of the custom field - to be able to build the field class in the code'),
                            )
                        ), 'User custom fields (also known as user profil fields)', VALUE_OPTIONAL),
                    'preferences' => new external_multiple_structure(
                        new external_single_structure(
                            array(
                                'name'  => new external_value(PARAM_ALPHANUMEXT, 'The name of the preferences'),
                                'value' => new external_value(PARAM_RAW, 'The value of the custom field'),
                            )
                        ), 'User preferences', VALUE_OPTIONAL)
                )
            )
        );
    }

}
