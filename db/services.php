<?php
/**
 * Digitalis web services external functions and service definitions.
 *
 * @package    local_digitalis
 * @copyright  2012 Digitalis Informática
 * @author     Fábio Souto
 */

$functions = array(
        'local_digitalis_get_users' => array(
                'classname'   => 'local_digitalis_external',
                'methodname'  => 'get_users',
                'classpath'   => 'local/digitalis/externallib.php',
                'description' => 'Return Moodle users and their associated information',
                'type'        => 'read',
	),
	'local_digitalis_unenrol_users' => array(
	        'classname'   => 'local_digitalis_external',
        	'methodname'  => 'unenrol_users',
		'classpath'   => 'local/digitalis/externallib.php',
		'description' => 'Manual unenrol users',
		'capabilities'=> 'enrol/manual:unenrol',
		'type'        => 'write',
	)
);

// We define the services to install as pre-build services. A pre-build service is not editable by administrator.
$services = array(
        'Digitalis webservices' => array(
                'functions' => array ('local_digitalis_get_users', 'local_digitalis_unenrol_users'),
                'restrictedusers' => 0,
                'enabled'=>1,
        )
);
