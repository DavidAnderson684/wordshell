<?php
$wordshell_version = "0.5.0 (2024-08-19)";

# This is the helper script. You do not run it directly. You can place it in ~/.wordshell (or wherever you configured WordShell to use).

# Keep this as a monotically increasing integer for simplicity
# This line should not have its format changed, nor similar lines added before it; it is read from bash
# Protocol versions change in step with new releases of wordshell requiring newly available commands
$proto_version = "23";

@set_time_limit(300);

// WP Super Cache
@define('DONOTCACHEPAGE', true);

/*
This file is part of WordShell, the command-line management tool for WordPress (www.wordshell.net)
(C) David Anderson 2012-

Remote management helper

License: GPLv2 or later or any other licence used by WordPress core

Some code (for coreupgrade) taken with thanks from the wp-cli project under the GPL

Authentication protocol:
- Must call within 3 minutes of the timestamp of this file (after that, no access at all allowed)
- $our_auth is the authentication key (a shared secret), and is set as a global variable at the top of this script when uploaded
- The transient wordshell-usedauth-${our_auth} keeps a counter of how many times we have been accessed. If not found, defaults to zero. If found as -1, then abort (no longer valid)
- The caller must post variable wpm-a with value md5('${our-auth}${counter}')
Note that this authentication protocol does not allow two callers to call the same file in a single session. They must upload differently named files.
*/

function wordshell_wpc_help() {
	global $wordshell_version, $proto_version;
	echo <<<ENDHERE
Program version: $wordshell_version, interface version: $proto_version

Core commands:
coreupgrade, wpversion

Debug commands:
phpinfo, phpversion, mysqlversion, ping, execphp:<php>

Theme commands:
listthemes, actitheme:<themedir> (just the basename)

Plugin commands:
list(net)plugins, list(net)slugs
(net)status:<plugin[:siteid]>, (net)(de)activate:<plugin[:siteid]> (takes path relative to plugins directory, as used internally in WP)
(net)(de)actislug:<plugin[:siteid> (takes directory name only, i.e. the slug)

Database commands:
dbdump: dumps out a full dump of the MySQL database
dbcheck: verify if we our database schema is up-to-date (useful after a core upgrade)
dbupgrade: call WordPress's internal routine to update the database schema
dbsearchreplace:<search>^<replace>(:tables) - search and replace inside the database, including inside serialised data. Use with caution! Will operate on all tables that begin with the table prefix defined in wp-config.php

Filesystem commands (give paths relative to WP base, e.g. wp-includes)
diskusage:<path> - disk space usage (in bytes) of specified path (recursive)
delfile:<path> - delete the indicated file
deldir:<dir> - recursively delete directory. deldir:. is the new rm -rf /
emptydir:<dir> - make an empty directory, emptying anything already there
delplugdir:<dir> - recursively delete plugin directory
delthemedir:<dir> - recursively delete theme directory
unzipd:<zip>:<where> - unzip zip file and then delete the zip file
findfiles5:<depth>:<where> - list all files (with MD5 checksums) (use depth 0 for unlimited depth; -2 to exclude wp-content and wp-wordshell-controller.php)
findfiles0:<depth>:<where> - like findfiles5, but with empty checksums
getfile:<where> - dump out the indicated file

Option commands:
optadd, optget, optdel, optupdate:option[:value] - add/get/delete/update an option

User commands:
userdelid:<id> - delete a user by ID (deletes all post/comments by user)
userdel:<username|email>[,<username|email|delete>] - delete a user by username or email address (matches against username first, then attempts email address if @ is present). Will re-assign all posts/comments by the user to the indicated username, or to the lowest-numbered admin if none is specified, or delete them if 'delete' is specified.
useradd:<username>:<role>:<email> - create a user. A random password will be generated.
userlist(detailed) - lists users
passwordreset:<username|email> - reset a user's password. A random password will be generated.

Maintenance mode:
maintenancestate: reports on maintenance state

Miscellaneous commands:
die : shuts down all future access to this wordshell-controller instance
ENDHERE;
}

# Debugging
$wordshell_debug = (isset($_POST['wpm-d']));
if ($wordshell_debug) {
	ini_set("display_errors","1");
	ERROR_REPORTING(E_ALL);
	header("X-WP-Controller-Version: $proto_version, $wordshell_version");
}

# This prevents maintenance mode from being triggered when we were the ones who activated it, when we call wp-load.php
$wordshell_maintenance = true;

# Authorisation expires 3 minutes after last modification to this file.
$wordshell_stat = stat(__FILE__);
$our_runtime_limit = $wordshell_stat[10]+180;

# First, check the authentication
if (!isset($our_runtime_limit)) { wordshell_exit("NOAUTH:This script has expired (1)."); }
$our_runtime_ttl = $our_runtime_limit - time();
if ($our_runtime_ttl<0) { wordshell_exit("NOAUTH:This script has expired (3)."); }
if (!isset($our_auth)) { wordshell_exit("NOAUTH:No authentication credentials were found."); }

# Did they send a credential?
if (!isset($_POST['wpm-a'])) { wordshell_exit("NOAUTH:Authentication failure: did not provide a valid credential (1)"); }

# The earliest possible ping; does not require full authentication
# Suitable for testing if you can run PHP at all - before WordPress itself is loaded.
if (isset($_POST['wpm-c']) && $_POST['wpm-c'] == "earlyping") {
	global $wp_version;
	if (file_exists('./wp-includes/version.php')) {
		# This file only defines a few global variables; so should be safe even with a broken WP installation (unless they re-engineer). Can't use ABSPATH yet.
		include('./wp-includes/version.php');
	} else { $wp_version = "?"; }
	$verinfo = "w$wp_version";
	echo "AUTHOK:PONG:$proto_version:".phpversion().":$verinfo:$wordshell_version";
	exit;
}

global $wp_filter, $merged_filters;
$wp_filter['option_active_plugins'][10]['wordshell_pre_option_active_plugins'] = array('function' => 'wordshell_pre_option_active_plugins', 'accepted_args' => 1);
unset( $merged_filters['option_active_plugins'] );

# Now load WP libraries
require_once('wp-load.php');
require_once(ABSPATH.'wp-includes/plugin.php');
require_once(ABSPATH.'wp-admin/includes/plugin.php');

function wordshell_pre_option_active_plugins($x) {
	# W3 Total Cache adds its own footer blurb, to everything, in a way that can't be removed
	if (is_array($x) && false !== ($k = array_search('w3-total-cache/w3-total-cache.php', $x))) unset($x[$k]);
	# WP Maintenance Mode messes with everything
	if (is_array($x) && false !== ($k = array_search('wp-maintenance-mode/wp-maintenance-mode.php', $x))) unset($x[$k]);
	return $x;
}

# These can help in the case where PHP does not have filesystem access
if (defined("WORDSHELL_FTP_USER") && !defined("FTP_USER")) { define("FTP_USER",WORDSHELL_FTP_USER); }
if (defined("WORDSHELL_FTP_PASS") && !defined("FTP_PASS")) { define("FTP_PASS",WORDSHELL_FTP_PASS); }
if (defined("WORDSHELL_FTP_HOST") && !defined("FTP_HOST")) { define("FTP_HOST",WORDSHELL_FTP_HOST); }

# Initially, if this transient is unset, then set as not yet used
$cred_used_counter = 0;
if ( false !== ( $gt = get_transient( 'wordshell-usedauth-'.$our_auth ) ) ) { $cred_used_counter = $gt; }

if (! ($cred_used_counter >= 0)) {
	wordshell_exit("NOAUTH:Authentication failure: the supplied credential has expired (2/$cred_used_counter).");
}

# Now, increase the counter. We do this immediately as the next auth token is independent of success or failure of this script from here.
# set_transient wants to know how many seconds in the future the transient will expire. Make sure it lasts at least as long as this script is valid (otherwise authentications will fail prematurely due to the counter resetting to zero)
$calc_time = 60 + $our_runtime_limit - time();
set_transient('wordshell-usedauth-'.$our_auth,$cred_used_counter+1,$calc_time);

# Is the credential correct?
if ($_POST['wpm-a'] != md5($our_auth.$cred_used_counter)) { wordshell_exit("NOAUTH:Authentication failure: did not provide a valid credential (2)"); }

if ($wordshell_debug) { header("X-WP-Controller-TTL: $our_runtime_ttl"); }

# Hook the filter (we found a plugin that called wp_die when its prerequisites were not found)
$wp_manager_die_handler_triggered = 0;
$wp_manager_die_message = "";
function wpmanagercontroller_diehandler( $message, $title , $args ) {
	global $wp_manager_die_handler_triggered;
	global $wp_manager_die_message;
	$wp_manager_die_message = $message;
	$wp_manager_die_handler_triggered = 1;
	//exit;
}

function wpmanagercontroller_diefilter() {
        return 'wpmanagercontroller_diehandler';
}

add_filter('wp_die_handler', 'wpmanagercontroller_diefilter');

$wordshell_command = $_POST['wpm-c'];
if ($wordshell_debug) { header("X-WP-Controller-Command: ".urlencode($wordshell_command)); }

# Send first part of response
if (substr($wordshell_command,0,8) != "getfile:" && $wordshell_command != "dbdump" ) { echo "AUTHOK:"; }

if (!isset($_POST['wpm-c'])) { wordshell_exit("ERROR:No command sent"); }

# Now invoke the requested function
if ( preg_match("/^([a-z0-9]+):(.*)$/",$wordshell_command,$cmatch) 	) {
	$wordshell_command = $cmatch[1];
	$param = $cmatch[2];
	if (function_exists('wordshell_wpc_param_'.$wordshell_command)) {
		call_user_func('wordshell_wpc_param_'.$wordshell_command,$param);
	} else {
		wordshell_exit("ERROR:Unknown command:".$wordshell_command,1);
	}
} else {
	# "list" is a deprecated alias for "listplugins"; remove after a while
	if ($wordshell_command == "list") {$wordshell_command = "listplugins";}
	$param = "";
	if (function_exists('wordshell_wpc_'.$wordshell_command)) {
		call_user_func('wordshell_wpc_'.$wordshell_command,$param);
	} else {
		wordshell_exit("ERROR:Unknown command",1);
	}
}

exit;

function wordshell_exit($message) {
	echo $message;
	exit;
}

function get_plugins_canonical($slug) {
	$plugs = get_plugins();
	$result = array();
	foreach ($plugs as $file => $plugarr) {
		if (preg_match("/^$slug/",$file)) { array_push($result,$file); }
	}
	if (count($result) == 0 ) { return false; }
	return($result);
}

function wordshell_wpc_ping() {
	global $proto_version, $wp_version, $wordshell_version;
	# Don't use require_once, as some plugins over-write wp-version, supposedly for security.
	require(ABSPATH.'wp-includes/version.php');

	$verinfo = "w${wp_version},m";

	global $wpdb;
	$db_version = @$wpdb->db_version();
	if ($db_version) {
		$verinfo .= $db_version;
	} else {
		$verinfo .= @mysql_get_server_info();
	}

	if (WP_CONTENT_DIR != ABSPATH.'wp-content' && strpos(WP_CONTENT_DIR, ABSPATH) ==0) {
		$sc = substr(WP_CONTENT_DIR, strlen(ABSPATH));
		$verinfo .= ",wpcd=$sc";
	}

	echo "PONG:$proto_version:".phpversion().":$verinfo:$wordshell_version";
}

function wordshell_wpc_maintenancestate() {
	if (is_file(ABSPATH.".maintenance")) {
		global $wordshell_maintenance;
		$wordshell_maintenance = 	false;
		global $upgrading;
		require(ABSPATH.".maintenance");
		if (( time() - $upgrading) >= 600 ) { echo "NOBUTFILE"; } else { echo "YES"; }
	} else {
		echo "NONOFILE";
	}
}

function wordshell_wpc_param_passwordreset($username) {
	$user = get_user_by('login', $username);
	if (false === $user && false !== strpos($username, '@')) $user = get_user_by('email', $username);
	if (false === $user || empty($user->ID)) { echo 'ERROR:NOSUCHUSER'; return; }
	$random_password = wp_generate_password(12, false);
	wp_set_password($random_password, $user->ID);
	echo "PWCHANGED:$random_password";
}

function wordshell_wpc_param_userdel($what) {
	# userdel:<username|email>[,<username|email|novalue>] - delete a user by username or email address (matches against username first, then attempts email address if @ is present). Will re-assign all posts/comments by the user to the indicated username, or to the lowest-numbered admin if none is specified, or delete them if 'novalue' is specified.
	if (preg_match('/^([^,]+),(.+)$/', $what, $matches)) {
		$username = $matches[1];
		$reassign = $matches[2];
	} else {
		$username = $what;
		$reassign = 'novalue';
	}
	$user = get_user_by('login', $username);
	if (false === $user && false !== strpos($username, '@')) $user = get_user_by('email', $username);
	if (false === $user || empty($user->ID)) { echo 'ERROR:NOSUCHUSER'; return; }
	// Reassign
	if ('@admin' === $reassign) {
		$all_users = get_users("fields[]=ID&fields[]=user_pass&role=administrator");
		$found_id = false;
		foreach ($all_users as $admin) {
			// If this is a different user then check using the same password but against the new hash
			if ($admin->ID > 0 && ($found_id == false || $admin->ID < $found_id)) {
				$found_id = $admin->ID;
			}
		}
		if (false == $found_id) { echo 'ERROR:NOSUCHAUSER'; return; }
		$reassign = $found_id;
	} elseif ('novalue' !== $reassign) {
		$reassign_user = get_user_by('login', $reassign);
		if (false === $reassign_user && false !== strpos($reassign, '@')) $reassign_user = get_user_by('email', $reassign);
		if (false === $reassign_user || empty($reassign_user->ID)) { echo 'ERROR:NOSUCHRUSER'; return; }
		$reassign = $reassign_user->ID;
	}
	wordshell_wpc_param_userdelid($user->ID, $reassign);
}

function wordshell_wpc_param_userdelid($what, $reassign = 'novalue') {
	if (is_numeric($what)) {
		if (!function_exists('wp_delete_user')) require_once(ABSPATH.'wp-admin/includes/user.php');
		if (wp_delete_user($what, $reassign) == true ) {
			echo "DELETED";
		} else { echo "ERROR;"; }
	} else { echo "Syntax Error"; }
}

function wordshell_wpc_param_dbsearchreplace($what) {
	if (preg_match('/^(.*):([^:]*)$/', $what, $fmatches)) {
		$what = $fmatches[1];
		$tables = empty($fmatches[2]) ? array() : explode(',', $fmatches[2]);
	} else {
		$tables = array();
	}
	if (preg_match('/^([^\^]+)\^(.*)$/', $what, $matches)) {
		$from = $matches[1];
		$to = $matches[2];
		_wordshell_searchreplace($from, $to, $tables);
	} else {
		echo "Syntax Error";
	}
}

function _wordshell_searchreplace($from, $to, $upon_tables = array()) {

	global $table_prefix, $wpdb;

	// Code from searchreplacedb2.php version 2.1.0 from http://www.davidcoveney.com

	if (!defined('DB_HOST') || !defined('DB_USER') || !defined('DB_PASSWORD')) {
		echo 'ERR:No database details';
		die;
	}

// 	$connection = @mysql_connect( DB_HOST, DB_USER, DB_PASSWORD );
// 	if ( ! $connection ) {
// 		echo 'ERR:MySQL connection error: '.mysql_error();
// 		die;
// 	}
	
// 	if ( defined('DB_CHARSET')) {
// 		if ( function_exists( 'mysql_set_charset' ) )
// 			mysql_set_charset( DB_CHARSET, $connection );
// 		else
// 			mysql_query( 'SET NAMES ' . DB_CHARSET, $connection );  // Shouldn't really use this, but there for backwards compatibility
// 	}
	
	// Do we have any tables and if so build the all tables array

	$stables = array( );
// 	mysql_select_db( DB_NAME, $connection );
// 	$tables_mysql = @mysql_query( 'SHOW TABLES', $connection );
	$tables_mysql = $wpdb->get_results("SHOW TABLES", ARRAY_N);
	$tables_mysql = array_map(create_function('$a', 'return $a[0];'), $tables_mysql);

	if ( ! $tables_mysql ) {
		echo 'ERR:Could not get list of tables:'.$wpdb->last_error;
		die;
	} else {
		foreach ($tables_mysql as $table) {
// 		while ( $table = mysql_fetch_array( $tables_mysql ) ) {
			// Type equality is necessary, as we don't want to match false
			if (strpos($table, $table_prefix) === 0) {
// 			if (strpos($table[0], $table_prefix) === 0) {
				if (empty($upon_tables) || in_array(substr($table, strlen($table_prefix)), $upon_tables)) $tables[] = $table;
			}
		}
	}

	if ( empty( $tables ) ) {
		echo 'ERR:The specified database table(s) could not be found';
		die;
	}

	$report = _wordshell_icit_srdb_replacer( $from, $to, $tables );

	// Output any errors encountered during the db work.
	if ( ! empty( $report[ 'errors' ] ) && is_array( $report[ 'errors' ] ) ) {
		foreach( $report[ 'errors' ] as $k => $error ) { if (!$error) unset($report['errors'][$k]); }
		if ( ! empty( $report[ 'errors' ] )) {
			echo 'ERR:';
			foreach( $report[ 'errors' ] as $error ) { if ( $error) echo "$error"; }
			die;
		}
	}

	// Calc the time taken.
	$time = array_sum( explode( ' ', $report[ 'end' ] ) ) - array_sum( explode( ' ', $report[ 'start' ] ) );

	echo 'DBSRDONE:t'.$report[ 'tables' ].':r'.$report['rows'].':c'.$report['change'].':u'.$report['updates'].':d'.$time.':';

}

function _wordshell_icit_srdb_replacer( $search, $replace, $tables ) {

	global $_wordshell_current_row, $wpdb;

	$report = array( 'tables' => 0,
		'rows' => 0,
		'change' => 0,
		'updates' => 0,
		'start' => microtime( ),
		'end' => microtime( ),
		'errors' => array( ),
	);

	if ( is_array( $tables )) {
		foreach( $tables as $table ) {
			$report[ 'tables' ]++;

			$columns = array( );

			// Get a list of columns in this table
			$fields = $wpdb->get_results('DESCRIBE '.wordshell_backquote($table), ARRAY_A);
			
			$indexkey_field = "";
			$prikey_field = false;

			foreach ($fields as $column) {
				$primary_key = ($column['Key'] == 'PRI') ? true : false;
				$columns[$column['Field']] = $primary_key;
				if ($primary_key) $prikey_field = $column['Field'];
			}

			$where = '';
			
			$count_rows_sql = 'SELECT COUNT(*) FROM '.$table;
			if ($prikey_field) $count_rows_sql .= " USE INDEX (PRIMARY)";
			$count_rows_sql .= $where;

			$row_countr = $wpdb->get_results($count_rows_sql, ARRAY_N);
			// If that failed, try this
			if (false !== $prikey_field && $wpdb->last_error) {
				$row_countr = $wpdb->get_results("SELECT COUNT(*) FROM $table USE INDEX ($prikey_field)".$where, ARRAY_N) ;
				if ($wpdb->last_error) $row_countr = $wpdb->get_results("SELECT COUNT(*) FROM $table", ARRAY_N) ;
			}

			$row_count = $row_countr[0][0];

			if ( $row_count == 0 )
				continue;

			$page_size = 50000;
			$pages = ceil( $row_count / $page_size );

			for ($on_row = 0; $on_row <= $row_count; $on_row = $on_row+$page_size) {

				$_wordshell_current_row = 0;
				$start = $page * $page_size;
				$end = $start + $page_size;
				// Grab the content of the table
				list($data, $page_size) = _wordshell_fetch_sql_result($table, $on_row, $page_size);

				# $sql_line is calculated here only for the purpose of logging errors
				# $where might contain a %, so don't place it inside the main parameter
				$sql_line = sprintf('SELECT * FROM %s LIMIT %d, %d', $table.$where, $on_row, $on_row+$page_size);

				if ($wpdb->last_error) {
					$report['errors'][] = $wpdb->last_error;
				} else {
					foreach ($data as $row) {
						$rowrep = _wordshell_process_row($table, $columns, $row, $search, $replace);
						$report['rows']++;
						$report['updates'] += $rowrep['updates'];
						$report['change'] += $rowrep['change'];
						foreach ($rowrep['errors'] as $err) $report['errors'][] = $err;
					}
				}

			}
		}

	}
	$report[ 'end' ] = microtime( );

	return $report;
}

function _wordshell_process_row($table, $columns, $row, $search, $replace) {

		global $wpdb, $_wordshell_current_row;

		$report = array('change' => 0, 'errors' => array(), 'updates' => 0);

		$_wordshell_current_row++;
		
		$update_sql = array( );
		$where_sql = array( );
		$upd = false;

		foreach ($columns as $column => $primary_key) {

			$edited_data = $data_to_fix = $row[ $column ];

			// Run a search replace on the data that'll respect the serialisation.
			$edited_data = _wordshell_recursive_unserialize_replace($search, $replace, $data_to_fix);

			// Something was changed
			if ( $edited_data != $data_to_fix ) {
				$report['change']++;
				$ed = $edited_data;
				$wpdb->escape_by_ref($ed);
				$update_sql[] = wordshell_backquote($column) . ' = "' . $ed . '"';
				$upd = true;
			}

			if ($primary_key) {
				$df = $data_to_fix;
				$wpdb->escape_by_ref($df);
				$where_sql[] = wordshell_backquote($column) . ' = "' . $df . '"';
			}
		}

		if ( $upd && ! empty( $where_sql ) ) {
			$sql = 'UPDATE '.wordshell_backquote($table).' SET '.implode(', ', $update_sql).' WHERE '.implode(' AND ', array_filter($where_sql));
			
			$wpdb->get_results($sql);

			if ( $wpdb->last_error ) {
				$last_error = $wpdb->last_error;
				$report['errors'][] = $last_error;
			} else { 
				$report['updates']++;
			}

		} elseif ( $upd ) {
			$report['errors'][] = sprintf( '"%s" has no primary key, manual change needed on row %s.', $table, $_wordshell_current_row );
		}

		return $report;

	}

function _wordshell_fetch_sql_result($table, $on_row, $page_size) {

	$sql_line = sprintf('SELECT * FROM %s LIMIT %d, %d', $table, $on_row, $page_size);

	global $wpdb;
	$data = $wpdb->get_results($sql_line, ARRAY_A);
	if (!$wpdb->last_error) return array($data, $page_size);
	
	if (5000 <= $page_size) return _wordshell_fetch_sql_result($table, $on_row, 2000);
	if (2000 <= $page_size) return _wordshell_fetch_sql_result($table, $on_row, 500);

	# At this point, $page_size should be 500; and that failed
	return array(false, $page_size);

}

/**
 * Take a serialised array and unserialise it replacing elements as needed and
 * unserialising any subordinate arrays and performing the replace on those too.
 *
 * @param string $from       String we're looking to replace.
 * @param string $to         What we want it to be replaced with
 * @param array  $data       Used to pass any subordinate arrays back to in.
 * @param bool   $serialised Does the array passed via $data need serialising.
 *
 * @return array	The original array with all elements replaced as needed.
 */
function _wordshell_recursive_unserialize_replace( $from = '', $to = '', $data = '', $serialised = false ) {

	// some unseriliased data cannot be re-serialised eg. SimpleXMLElements
	try {

		if ( is_string( $data ) && ( $unserialized = @unserialize( $data ) ) !== false ) {
			$data = _wordshell_recursive_unserialize_replace( $from, $to, $unserialized, true );
		}

		elseif ( is_array( $data ) ) {
			$_tmp = array( );
			foreach ( $data as $key => $value ) {
				$_tmp[ $key ] = _wordshell_recursive_unserialize_replace( $from, $to, $value, false );
			}

			$data = $_tmp;
			unset( $_tmp );
		}

		else {
			if ( is_string( $data ) )
				$data = str_replace( $from, $to, $data );
		}

		if ( $serialised )
			return serialize( $data );

	} catch( Exception $error ) {

	}

	return $data;
}

function wordshell_wpc_userlist() {
	if (!function_exists('get_users')) include_once(ABSPATH.'wp-includes/user.php');
	$users = get_users( array('orderby' => nicename) );
	foreach ($users as $user) {
		echo "\n".$user->user_login;
	}
}

function wordshell_wpc_userlistdetailed() {
	if (!function_exists('get_users')) include_once(ABSPATH.'wp-includes/user.php');
	$users = get_users( array('orderby' => nicename) );
	foreach ($users as $user) {
		$role = '';
		if (is_array($user->roles)) {
			foreach ($user->roles as $ro) {
				$role = ('' == $role) ? $ro : ",$ro";
			}
		}
		if ('' == $role) $role = "unknown";
		echo "\nLogin:".$user->user_login." Email:".$user->user_email." Role:$role";
	}
}

function wordshell_wpc_param_useradd($what) {
	if (preg_match("/^([^:]+):([^:]+):(.*)$/",$what,$matches)) {
		$username=$matches[1];
		$userrole=$matches[2];
		$usermail=$matches[3];
		$user_id = username_exists($username);
		if ( !$user_id && email_exists($usermail) == false ) {
			$random_password = wp_generate_password(12, false);
			# http://codex.wordpress.org/Function_Reference/wp_insert_user
			$user_id = wp_insert_user( array ( 'user_pass' => $random_password, 'user_login' => $username, 'user_email' => $usermail, 'role' => $userrole) );
			if (is_integer($user_id)) {
				echo "ADDED:$random_password:$user_id";
			} else {
				echo "ERROR:Unknown Error:";
				print_r($user_id);
			}
		} else {
			echo "ERROR:One of the username or email address already exists.";
		}
	} else {
		echo "Syntax Error";
	}
}

function wordshell_wpc_param_optadd($what) {
	if (preg_match("/^([^:]+):(.*)$/",$what,$matches)) {
		if ( !add_option($matches[1],$matches[2]) ) {
			echo "ERROR";
		} else {
			echo "ADDED";
		}
	} else {
		echo "Syntax Error";
	}
}

function wordshell_wpc_param_optupdate($what) {
	if (preg_match("/^([^:]+):(.*)$/",$what,$matches)) {
		if ( update_option($matches[1],$matches[2]) ) {
			echo "UPDATED\n";
		} else {
			echo "ERROR\n";
		}
	} else {
		echo "Syntax Error";
	}
}

function wordshell_wpc_param_optdel($what) {
	echo (delete_option($what)) ? "DELETED" : "NOTFOUND";
}

function wordshell_wpc_param_optget($what) {
	$value = get_option($what);
	if ($value === false) {
		echo "NOTFOUND";
	} elseif ( is_array($value) || is_object($value) ) {
		echo "MULTILINE\n"; print_r($value);
	} else {
		echo "OK\n"; echo $value."\n";
	}

}

function wordshell_wpc_param_getfile($what) {
	if (is_file(ABSPATH.$what)) {
		if (is_readable(ABSPATH.$what)) {
			readfile(ABSPATH.$what);
			# We exit to prevent other plugins from adding their stuff to the footer
			exit;
		} else {
			echo "CANTREAD:$what\n";
		}
	} else { echo "NOTAFILE:$what\n";}
}

function wordshell_wpc_param_diskusage($what) {
	if (is_readable(ABSPATH.$what)) {
		echo wordshell_disk_usage(ABSPATH.$what);
	} else {
		echo "NOTFOUND";
	}
}

function wordshell_disk_usage($d, $depth = NULL) {
	if(is_file($d)) {return filesize($d); }
	if(isset($depth) && $depth < 0) { return 0; }
	if($d[strlen($d)-1] != '/') { $d .= '/'; }
	$dh=@opendir($d);
	if(!$dh) {return 0;}
	while($e = readdir($dh)) {
		if ($e != '.' && $e != '..') { $usage += wordshell_disk_usage($d.$e, isset($depth) ? $depth - 1 : NULL); }
	}
	closedir($dh);
	return $usage;
 }

function wordshell_wpc_param_findfiles0($what2) {
	return wordshell_wpc_param_findfiles5($what2, false);
}

function wordshell_wpc_param_findfiles5($what2, $checksums = true) {
	if (preg_match("/^(-?[0-9]+):(.*)$/",$what2,$matches)) {
		# -5 is chosen just to be less than any special meaning; it causes all files to be found
		$depth= $matches[1]; if ($depth == 0) { $depth =- 5; }
		$what = $matches[2];
		if (is_dir(ABSPATH.$what)) {
			echo "OK\n";
			wordshell_search($what,$depth);
			echo "x:END\n";
		} elseif (is_file(ABSPATH.$what)) {
			echo "OK\n";
			if (is_readable(ABSPATH.$what)) {
				echo "f:";
				if ($checksums) {
					echo md5_file(ABSPATH.$what);
				} else {
					echo '00000000000000000000000000000000';
				}
				echo ":$what\n";
			} else {
				echo "f:CANNOTREAD:$what\n";
			}
			echo "x:END\n";
		} else {
			echo "NOTFOUND";
		}
	} else {
		echo "INVALIDDATA";
	}
}

function wordshell_search($folder,$howmanymore) {
	$howmanymore--;
	echo "d:$folder\n";
	if($handle = opendir(ABSPATH.$folder)) {
		while(($file = readdir($handle)) !== false) {
			$file_relative = $folder.'/'.$file;
			if(is_file(ABSPATH.$file_relative) && ($howmanymore != -3 || $file != "wp-wordshell-controller.php" ) ) {
				$file_relative_key = (substr($file_relative,0,2) == "./") ? substr($file_relative,2) : $file_relative;
				if (is_readable(ABSPATH.$file_relative)) {
					echo "f:".md5_file(ABSPATH.$file_relative).":$file_relative\n";
				} else {
					echo "f:CANNOTREAD:$file_relative\n";
				}
			} elseif(is_link(ABSPATH.$file_relative) && $file != '.' && $file != '..') {
				// Ran into a folder, we have to dig deeper now
				echo "l:$file_relative:".readlink(ABSPATH.$file_relative)."\n";
			} elseif(is_dir(ABSPATH.$file_relative) && $file != '.' && $file != '..') {
				// Ran into a folder, we have to dig deeper now
				if ($howmanymore != 0 && ($howmanymore != -3 || $file != "wp-content") ) {wordshell_search($file_relative,$howmanymore);}
			}
		}
		closedir($handle);
	}
}

function wordshell_unzip_file_pclzip($file, $to, $needed_dirs) {
	# Adapted from WordPress code

	// See #15789 - PclZip uses string functions on binary data, If it's overloaded with Multibyte safe functions the results are incorrect.
	if ( ini_get('mbstring.func_overload') && function_exists('mb_internal_encoding') ) {
		$previous_encoding = mb_internal_encoding();
		mb_internal_encoding('ISO-8859-1');
	}
	if (!class_exists('PclZip')) require_once(ABSPATH . 'wp-admin/includes/class-pclzip.php');
	$archive = new PclZip($file);
	$archive_files = $archive->extract(PCLZIP_OPT_EXTRACT_AS_STRING);

	if ( isset($previous_encoding) ) {mb_internal_encoding($previous_encoding);}

	// Is the archive valid?
	if ( !is_array($archive_files) ) { return "incompatible archive"; }
	if ( 0 == count($archive_files) ) { return true;}

	// Determine any children directories needed (From within the archive)
	foreach ( $archive_files as $file ) {
		if ( '__MACOSX/' === substr($file['filename'], 0, 9) ) // Skip the OS X-created __MACOSX directory
			continue;
		$needed_dirs[] = $to . untrailingslashit( $file['folder'] ? $file['filename'] : dirname($file['filename']) );
	}

	$needed_dirs = array_unique($needed_dirs);
	foreach ( $needed_dirs as $dir ) {
		// Check the parent folders of the folders all exist within the creation array.
		if ( untrailingslashit($to) == $dir ) // Skip over the working directory, We know this exists (or will exist)
			continue;
		if ( strpos($dir, $to) === false ) // If the directory is not within the working directory, Skip it
			continue;

		$parent_folder = dirname($dir);
		while ( !empty($parent_folder) && untrailingslashit($to) != $parent_folder && !in_array($parent_folder, $needed_dirs) ) {
			$needed_dirs[] = $parent_folder;
			$parent_folder = dirname($parent_folder);
		}
	}
	asort($needed_dirs);

	// Create those directories if need be:
	foreach ( $needed_dirs as $_dir ) {
		if ( ! is_dir($_dir) && ! mkdir($_dir) )
			return "mkdir failed";
	}
	unset($needed_dirs);

	// Extract the files from the zip
	foreach ( $archive_files as $file ) {
		if ( $file['folder'] )
			continue;
		if ( '__MACOSX/' === substr($file['filename'], 0, 9) ) // Don't extract the OS X-created __MACOSX directory files
			continue;
		if (($fp = @fopen($file['filename'], 'w')) ) {
			@fwrite($fp, $contents);
			@fclose($fp);
		}
	}
	return true;
}

function wordshell_wpc_param_unzipd($what) {
	$root=ABSPATH;
	if (!preg_match("/([^:]+):(.*)/",$what,$matches)) { echo "INVALIDDATA"; return; }
	$zipfile=$root.'/'.$matches[1];
	if (!is_readable($zipfile)) { echo "NOSUCHFILE"; return; }
	$wheretounzip=trailingslashit($root.'/'.$matches[2]);
	if (!function_exists('unzip_file')) { require_once("wp-admin/includes/file.php"); }

	$needed_dirs = array();

	// Determine any parent dir's needed (of the upgrade directory)
	if ( ! is_dir($wheretounzip) ) { //Only do parents if no children exist
		$path = preg_split('![/\\\]!', untrailingslashit($wheretounzip));
		for ( $i = count($path); $i >= 0; $i-- ) {
			if ( empty($path[$i]) )
				continue;
			$dir = implode('/', array_slice($path, 0, $i+1) );
			if ( preg_match('!^[a-z]:$!i', $dir) ) // Skip it if it looks like a Windows Drive letter.
				continue;
			if ( ! is_dir($dir) )
				$needed_dirs[] = $dir;
			else
				break; // A folder exists, therefor, we dont need the check the levels below this
		}
	}

	if ( class_exists('ZipArchive') ) {
		$result = wordshell_unzip_file_ziparchive($zipfile, $wheretounzip, $needed_dirs);
		if ( true === $result ) { @unlink($zipfile); wordshell_exit("UNZIPPED"); }
		echo "UNZIPERROR";
		print_r($result);
	} else {
		wordshell_unzip_file_pclzip($zipfile, $wheretounzip, $needed_dirs);
	}

	@unlink($zipfile);

	# TODO: Check that worked. Is the global alive?
	# Unzip into the upgrade dir. Then move it.
	#unzip_file($full_path_from,$full_path_to);
	# TODO
	#Returns true on scucess; wp_error on failure
}

function wordshell_unzip_file_ziparchive($file, $to, $needed_dirs = array() ) {

	$z = new ZipArchive();

	// PHP4-compat - php4 classes can't contain constants
	$zopen = $z->open($file, /* ZIPARCHIVE::CHECKCONS */ 4);
	if ( true !== $zopen )
		return "Incompatible Archive";

	for ( $i = 0; $i < $z->numFiles; $i++ ) {
		if ( ! $info = $z->statIndex($i) )
			return "Could not retrieve file from archive";

		if ( '__MACOSX/' === substr($info['name'], 0, 9) ) // Skip the OS X-created __MACOSX directory
			continue;

		if ( '/' == substr($info['name'], -1) ) // directory
			$needed_dirs[] = $to . untrailingslashit($info['name']);
		else
			$needed_dirs[] = $to . untrailingslashit(dirname($info['name']));
	}

	$needed_dirs = array_unique($needed_dirs);
	foreach ( $needed_dirs as $dir ) {
		// Check the parent folders of the folders all exist within the creation array.
		if ( untrailingslashit($to) == $dir ) // Skip over the working directory, We know this exists (or will exist)
			continue;
		if ( strpos($dir, $to) === false ) // If the directory is not within the working directory, Skip it
			continue;

		$parent_folder = dirname($dir);
		while ( !empty($parent_folder) && untrailingslashit($to) != $parent_folder && !in_array($parent_folder, $needed_dirs) ) {
			$needed_dirs[] = $parent_folder;
			$parent_folder = dirname($parent_folder);
		}
	}
	asort($needed_dirs);

	// Create those directories if need be:
	foreach ( $needed_dirs as $_dir ) {
		if ( ! is_dir($_dir) && ! mkdir($_dir) )
			return "mkdir failed";
	}
	unset($needed_dirs);

	for ( $i = 0; $i < $z->numFiles; $i++ ) {
		if ( ! $info = $z->statIndex($i) )
			return "Could not retrieve file from archive";

		if ( '/' == substr($info['name'], -1) ) // directory
			continue;

		if ( '__MACOSX/' === substr($info['name'], 0, 9) ) // Don't extract the OS X-created __MACOSX directory files
			continue;

		$contents = $z->getFromIndex($i);
		if ( false === $contents )
			return "Could not extract file from archive";

		if (($fp = @fopen($to . $info['name'], 'w')) ) {
			@fwrite($fp, $contents);
			@fclose($fp);
		}
	}

	$z->close();

	return true;
}


function wordshell_rmdir($path) {
	# http://www.php.net/manual/en/function.unlink.php
	return is_file($path) ? @unlink($path) : array_map('wordshell_rmdir',glob($path.'/*'))==@rmdir($path);
}

function wordshell_wpc_param_delplugdir($what) {
	$deldir = WP_PLUGIN_DIR.'/'.$what;
	if (!is_dir($deldir)) {
		echo "NOSUCHDIR";
	} else {
		wordshell_rmdir($deldir);
		echo "DELETED:".$deldir;
	}
}

function wordshell_wpc_param_delthemedir($what) {
	$deldir = WP_CONTENT_DIR.'/themes/'.$what;
	if (!is_dir($deldir)) {
		echo "NOSUCHDIR";
	} else {
		wordshell_rmdir($deldir);
		echo "DELETED:".$deldir;
	}
}

function wordshell_wpc_param_deldir($what) {
	$deldir = ABSPATH.$what;
	if (!is_dir($deldir)) {
		echo "NOSUCHDIR";
	} else {
		wordshell_rmdir($deldir);
		echo "DELETED:".$deldir;
	}
}

function wordshell_wpc_param_delfile($what) {
	$delfile = ABSPATH.$what;
	if (!is_file($delfile)) {
		echo "NOSUCHFILE";
	} else {
		echo (unlink($delfile)) ? "DELETED:" : "ERROR:";
		echo $delfile;
	}
}

function wordshell_wpc_param_emptydir($what) {
	$deldir = ABSPATH.$what;
	if (!is_dir($deldir)) {
		#echo "NOSUCHDIR";
		@mkdir($deldir);
		if (!is_dir($deldir)) { echo "NOSUCHDIR"; } else { echo "DELETED:".$deldir; }
	} else {
		wordshell_rmdir($deldir);
		@mkdir($deldir);
		echo "DELETED:".$deldir;
	}
}

function wordshell_wpc_wpversion() {
	global $wp_version;
	# Don't use require_once, as some plugins over-write wp-version, supposedly for security.
	require(ABSPATH.'wp-includes/version.php');
	echo "WPVERSION:".$wp_version;
}

function wordshell_wpc_phpinfo() {
	echo "PHPINFO:\n";
	phpinfo();
}

function wordshell_wpc_phpversion() {
	echo "PHPVERSION:".phpversion();
}

function wordshell_wpc_mysqlversion() {
	global $wpdb;
	$db_version = @$wpdb->db_version();
	if ($db_version) {
		echo "MYSQLVERSION:".$db_version;
		return;
	}
	echo "MYSQLVERSION:".@mysql_get_server_info();
}

function wordshell_wpc_listthemes() {
	// List themes mode
	echo "OK:ListThemes\n";
	$current_theme = get_current_theme();
	global $wp_version;
	require(ABSPATH.'wp-includes/version.php');
	if (version_compare($wp_version, "3.4.0", ">=")) {
		// get_themes was deprecated with 3.4.0. The following converts from wp_get_themes to the old format
		$wp_themes = wp_get_themes();
		$themes = array();
		foreach ( $wp_themes as $theme ) {
				$name = $theme->get('Name');
				if ( isset( $themes[ $name ] ) )
					$themes[ $name . '/' . $theme->get_stylesheet() ] = $theme;
				else
					$themes[ $name ] = $theme;
		}
	} else {
		$themes = get_themes();
	}
	$list_output = "";
	$themes_name_to_basedir = array();
	foreach ($themes as $themetitle => $themearray) {
		$theme_name=$themearray['Name'];
		$theme_basedir = basename($themearray['Stylesheet Dir']);
		if ($theme_basedir == "") { $theme_basedir = $themearray['Stylesheet']; }
		$themes_name_to_basedir[$theme_name]=$theme_basedir;
		$active = "no";
		# Seen all three possibilities here
		if ($current_theme == $theme_name || $current_theme == $theme_basedir || $current_theme == $theme_name."/".$theme_basedir) {
			$active="yes";
			$current_parent = $themearray['Parent Theme'];
		}
		$list_output .= $theme_basedir.":$active:".$themearray['Version'].":".$themearray['Title'].":".$themearray['Parent Theme']."\n";
	}
	if ('' != $current_parent) {
		$parent_basedir = $themes_name_to_basedir[$current_parent];
		# Parent should be marked as active also
		$pattern = "/(^|\n)".$parent_basedir.":no:/";
		$list_output = preg_replace($pattern, "\\1".$parent_basedir.":yes:",$list_output);
	}
	# Do not close down; single run of WordShell may need further access
	echo $list_output;
}

# Check as to whether we have the proper database version
function wordshell_wpc_dbcheck() {
	global $wp_db_version;
	if (is_multisite()) { echo "UNSUPPORTED"; return; }
	if (get_option( 'db_version' ) == $wp_db_version ) {
		echo "OK:$wp_db_version";
	} elseif (get_option( 'db_version' ) > $wp_db_version ) {
		echo "MORERECENT:".get_option('db_version').":$wp_db_version";
	} else {
		echo "UPGRADE:".get_option('db_version').":$wp_db_version";
	}
}

function wordshell_wpc_dbupgrade() {
	if (file_exists(ABSPATH."wp-admin/includes/upgrade.php")) {
		require_once(ABSPATH."wp-admin/includes/upgrade.php");
		if (function_exists('wp_upgrade')) {
			wp_upgrade();
			echo "OK";
		} else { echo "NoSuchFunction"; }
	} else {
		echo "NoSuchIncludeFile";
	}
}

function wordshell_wpc_coreupgrade() {
	# Upgrade core

	wp_version_check();
	$from_api = get_site_transient( 'update_core' );
	if ( empty( $from_api->updates ) ) { $update = false; } else { list( $update ) = $from_api->updates; }
	require_once(ABSPATH.'wp-admin/includes/upgrade.php');
	require_once(ABSPATH.'wp-admin/includes/class-wp-upgrader.php');

	global $wordshell_update_output;
	$wordshell_update_output = "";

	/**
	 * A Upgrader Skin for WordPress that only generates plain-text
	 *
	 * Adapted with thanks from the wp-cli project under the GPL
	 */
	if (version_compare($from_api->version_checked, '5.3', '<')) {
	
		class WordShell_Upgrader_Skin extends WP_Upgrader_Skin {

			function header() {}
			function footer() {}
			function bulk_header() {}
			function bulk_footer() {}

			function error( $error ) {
				if ( !$error )
					return;
				print_r($error);
			}

			function feedback( $string ) {

				global $wordshell_update_output;

				if ( isset( $this->upgrader->strings[$string] ) ) { $string = $this->upgrader->strings[$string]; }

				if ( strpos($string, '%') !== false ) {
					$args = func_get_args();
					$args = array_splice($args, 1);
					if ( !empty($args) ) { $string = vsprintf($string, $args); }
				}

				if ( empty($string) ) { return; }

				$string = str_replace( '&#8230;', '...', strip_tags( $string ) );

				$wordshell_update_output .= $string."\n";
			}
		}

	} else {
		class WordShell_Upgrader_Skin extends WP_Upgrader_Skin {
			
			function header() {}
			function footer() {}
			function bulk_header() {}
			function bulk_footer() {}
			
			function error( $error ) {
				if ( !$error )
					return;
				print_r($error);
			}
			
			function feedback( $string, ...$args ) {
				
				global $wordshell_update_output;
				
				$string = strip_tags( strip_tags );
				if ( isset( $this->upgrader->strings[$string] ) ) { $string = $this->upgrader->strings[$string]; }
				
				if ( strpos($string, '%') !== false ) {
					$args   = array_map( 'strip_tags', $args );
					//$args   = array_map( 'esc_html', $args );
					$string = vsprintf( $string, $args );
				}
				
				if ( empty($string) ) { return; }
				
				$string = str_replace( '&#8230;', '...', $string );
				
				$wordshell_update_output .= $string."\n";
			}
		}
	}

	$updater = new Core_Upgrader(new WordShell_Upgrader_Skin);
	$result = $updater->upgrade( $update );

	// Process the error properly; may just be that we are already up to date.

	if ( is_wp_error($result) ) {
		$msg = wordshell_errorToString( $result );
		if ( 'up_to_date' != $result->get_error_code() ) {
			echo "ERROR\n$msg\n";
		} else {
			echo "UPTODATE\n";
		}
		print $wordshell_update_output;
	} else {
		echo "UPDATED\n";
		print $wordshell_update_output;
		# Usually this prints the version number
		print_r($result);
	}

}

function wordshell_errorToString( $errors ) {
	if( is_string( $errors ) ) {
		return $errors;
	} elseif( is_wp_error( $errors ) && $errors->get_error_code() ) {
		foreach( $errors->get_error_messages() as $message ) {
			if( $errors->get_error_data() )
				return $message . ' ' . $errors->get_error_data();
			else
				return $message;
		}
	}
}

function wordshell_wpc_param_actitheme($themeslug) {

	global $wp_version;
	require(ABSPATH.'wp-includes/version.php');
	if (version_compare($wp_version, "3.4.0", ">=")) {
		// get_themes was deprecated with 3.4.0. The following converts from wp_get_themes to the old format
		$wp_themes = wp_get_themes();
		$themes = array();
		foreach ( $wp_themes as $theme ) {
				$name = $theme->get('Name');
				if ( isset( $themes[ $name ] ) )
					$themes[ $name . '/' . $wp_theme->get_stylesheet() ] = $theme;
				else
					$themes[ $name ] = $theme;
		}
	} else {
		$themes = get_themes();
	}

	$found_theme = 0;
	foreach ($themes as $theme => $themearray) {
		if ($themearray['Template'] == $themeslug) {
			$stylesheet = $themearray['Stylesheet'];
			$name = $themearray['Name'];
			$found_theme = 1;
		}
	}
	if ($found_theme == 1) {
		switch_theme($themeslug, $stylesheet);

		if (version_compare($wp_version, "3.4.0", ">=")) {
			$now_theme = wp_get_theme()->get('Name');
		} else {
			$now_theme = get_current_theme();
		}

		if ($now_theme == $name) {
			echo "OK:Activated:$name";
		} else {
			echo "ERROR: Theme did not successfully activate";
		}
	} else {
		echo "ERROR:Theme not found";
	}
	/*
	if (isset($themes[$theme]['Template'];
	$template = $themes[$theme]['Template'];
	$stylesheet = $themes[$theme]['Stylesheet'];
	*/
}

function wordshell_wpc_param_deactislug($slug) {
	_wordshell_wpc_param_deactislug($slug,false);
}

function wordshell_wpc_param_netdeactislug($slug) {
	_wordshell_wpc_param_deactislug($slug,true);
}

function wordshell_wpc_param_deactivate($slug) {
	_wordshell_wpc_param_deactivate($slug,false);
}

function wordshell_wpc_param_netdeactivate($slug) {
	_wordshell_wpc_param_deactivate($slug,true);
}

function _wordshell_wpc_param_deactivate($slug,$network_wide) {
	if ($network_wide == false && preg_match("/^(.*):(.*)$/",$slug,$matches)) {
		$slug = $matches[1];
		$siteid = $matches[2];
		if (!is_numeric($siteid)) $siteid = get_id_from_blogname($siteid);
		if (!is_numeric($siteid)) { echo "ERROR:NoSuchBlog"; return; }
		if (!function_exists('switch_to_blog')) require_once(ABSPATH.'wp-includes/ms-blogs.php');
		if ( switch_to_blog($siteid, true) === false ) { echo "ERROR:NoSuchBlog"; return; }
	}
	$stat_result = (is_plugin_active($slug)) ? "yes" : "no";
	if ($stat_result == "yes") {
		deactivate_plugins($slug,null,$network_wide,false);
		echo "OK:Deactivated:".$slug;
	} else {
		echo "OK:WasNotActive:".$slug;
	}
}

function _wordshell_wpc_param_deactislug($slug, $network_wide) {
	if ($network_wide == false && preg_match("/^(.*):(.*)$/",$slug,$matches)) {
		$slug = $matches[1];
		$siteid = $matches[2];
		if (!is_numeric($siteid)) $siteid = get_id_from_blogname($siteid);
		if (!is_numeric($siteid)) { echo "ERROR:NoSuchBlog"; return; }
		if (!function_exists('switch_to_blog')) require_once(ABSPATH.'wp-includes/ms-blogs.php');
		if ( switch_to_blog($siteid, true) === false ) { echo "ERROR:NoSuchBlog"; return; }
	}
	if ($plugins_canonical = get_plugins_canonical($slug)) {
		foreach ($plugins_canonical as $plug_can) {
			$stat_result = (is_plugin_active($plug_can)) ? "yes" : "no";
			if ($stat_result == "yes") {
				deactivate_plugins($plug_can,null,$network_wide,false);
				echo "OK:Deactivated:".$plug_can."\n";
			} else {
				echo "OK:WasNotActive:".$plug_can."\n";
			}
		}
		
	} else {
		echo "ERROR:CouldNotFindPlugins";
	}
}

function wordshell_wpc_param_activate($activate) {
	_wordshell_wpc_param_activate($activate,false);
}

function wordshell_wpc_param_netactivate($activate) {
	if (!is_multisite()) { echo "ERROR:NotMulti"; return; }
	_wordshell_wpc_param_activate($activate,true);
}

function wordshell_wpc_param_actislug($slug) {
	_wordshell_wpc_param_actislug($slug,false);
}

function wordshell_wpc_param_netactislug($slug) {
	if (!is_multisite()) { echo "ERROR:NotMulti"; return; }
	_wordshell_wpc_param_actislug($slug,true);
}

function _wordshell_wpc_param_activate($activate,$network_wide) {
	if ($network_wide == false && preg_match("/^(.*):(.*)$/",$activate,$matches)) {
		$activate = $matches[1];
		$siteid = $matches[2];
		if (!is_numeric($siteid)) $siteid = get_id_from_blogname($siteid);
		if (!is_numeric($siteid)) { echo "ERROR:NoSuchBlog"; return; }
		if (!function_exists('switch_to_blog')) require_once(ABSPATH.'wp-includes/ms-blogs.php');
		if ( switch_to_blog($siteid, true) === false ) { echo "ERROR:NoSuchBlog"; return; }
	}
	$wp_manager_die_handler_triggered = 0;
	$wp_manager_die_message = "";
	$try_active = activate_plugins($activate,null,$network_wide,false);
	if ($try_active == true && $wp_manager_die_handler_triggered == 0) {
		echo "OK:Activated:".$activate;
	} elseif (is_wp_error($try_active)) {
		$output = "ERROR:Failed:";
		foreach ($try_active->get_error_messages() as $msg) {
			$output .= $msg.":";
		}
		echo $output;
	} else {
		$output = "ERROR:Failed:".$activate;
		if ( $wp_manager_die_message != "" ) { $output .= ":${wp_manager_die_message}"; }
		echo $output;
	}
}

function _wordshell_wpc_param_actislug($slug, $network_wide) {
	if ($network_wide == false && preg_match("/^(.*):(.*)$/",$slug,$matches)) {
		$slug = $matches[1];
		$siteid = $matches[2];
		if (!is_numeric($siteid)) $siteid = get_id_from_blogname($siteid);
		if (!is_numeric($siteid)) { echo "ERROR:NoSuchBlog"; return; }
		if (!function_exists('switch_to_blog')) require_once(ABSPATH.'wp-includes/ms-blogs.php');
		if ( switch_to_blog($siteid, true) === false ) { echo "ERROR:NoSuchBlog"; return; }
	}
	if ($plugins_canonical = get_plugins_canonical($slug)) {
		foreach ($plugins_canonical as $plug_can) {
			$wp_manager_die_handler_triggered = 0;
			$wp_manager_die_message = "";
			$try_active = activate_plugins($plug_can,null,$network_wide,false);
			if ($try_active == 1 && $wp_manager_die_handler_triggered == 0) {
				echo "OK:Activated:$plug_can\n";
			} else {
				$output = "ERROR:Failed:".$plug_can;
				if ( $wp_manager_die_message != "" ) { $output .= ":${wp_manager_die_message}"; }
				echo $output."\n";
			}
		}
	}
}

function wordshell_wpc_listnetslugs() {
	if (!is_multisite()) { echo "ERROR:NotMulti"; return; }
	_wordshell_wpc_listplugins("listslugs",true);
}

function wordshell_wpc_listnetplugins() {
	if (!is_multisite()) { echo "ERROR:NotMulti"; return; }
	_wordshell_wpc_listplugins("listplugins",true);
}

function wordshell_wpc_listslugs() {
	_wordshell_wpc_listplugins("listslugs",false);
}

function wordshell_wpc_listplugins() {
	_wordshell_wpc_listplugins("listplugins",false);
}

function wordshell_wpc_param_listsiteslugs($siteid) {
	if (!is_multisite()) { echo "ERROR:NotMulti"; return; }
	_wordshell_wpc_listplugins("listslugs",false,$siteid);
}

function wordshell_wpc_param_listsiteplugins($siteid) {
	if (!is_multisite()) { echo "ERROR:NotMulti"; return; }
	_wordshell_wpc_listplugins("listplugins",false,$siteid);
}

function _wordshell_wpc_listplugins($wordshell_command, $network_wide, $siteid = false) {
	if ($network_wide && !is_multisite()) { echo "ERROR:NotMulti"; return; }
	if ($siteid != false) {
		if (!is_numeric($siteid)) $siteid = get_id_from_blogname($siteid);
		if (!is_numeric($siteid)) { echo "ERROR:NoSuchBlog"; return; }
		if (!function_exists('switch_to_blog')) require_once(ABSPATH.'wp-includes/ms-blogs.php');
		if ( switch_to_blog($siteid, true) === false ) { echo "ERROR:NoSuchBlog"; return; }
	}
	// List mode. 
	echo "OK:List\n";
	$plugs = get_plugins();
	$list_output = "";
/*
Example array:
Array
(
    [Name] => wpsc Support Tickets
    [PluginURI] => http://wpstorecart.com/wpsc-support-tickets/
    [Version] => 0.9.5
    [Description] => An open source help desk and support ticket system for Wordpress using jQuery. Easy to use for both users & admins.
    [Author] => wpStoreCart, LLC
    [AuthorURI] => URI: http://wpstorecart.com/
    [TextDomain] => 
    [DomainPath] => 
    [Network] => 
    [Title] => wpsc Support Tickets
    [AuthorName] => wpStoreCart, LLC
)
*/
	$already_listed = array();
	foreach ($plugs as $file => $plugarr) {
		$plug_display_key = "";
		if ( $wordshell_command == "listplugins" ) {
			$plug_display_key = $file;
		} elseif (preg_match("/^([^\/]+)\//",$file,$matches)) {
			$plug_display_key = $matches[1];
		}
		# At this time, isset($already_listed[$plug_display_key]); here we output the true situation, and let the receiving end deal with the fact that there may be multiple plugins in the same directory
		if ($plug_display_key != "") {
			$already_listed[$plug_display_key] = 1;
			$list_output .= $plug_display_key.":";
			if ($network_wide == true) {
				$is_active = is_plugin_active_for_network($file);
			} else {
				# Plugin is active if it is network active or active on this particular site
				$is_active = (is_multisite() && is_plugin_active_for_network($file) == true) ? true : is_plugin_active($file);
			}
			$list_output .= ($is_active) ? "yes" : "no"; $list_output .= ":";
			$plug_ver = $plugarr['Version'];
			$plug_name = $plugarr['Name'];
			$list_output .= $plug_ver.":";
			$list_output .= $plug_name."\n";
		}
	}
	echo $list_output;
}

function wordshell_wpc_die() {
	global $our_auth;
	# 200 seconds takes us past the default 3 minutes that the script can run in any case
	set_transient('wordshell-usedauth-'.$our_auth,-1,200);
	echo "DIED";
}

function wordshell_wpc_param_netstatus($plug) {
	_wordshell_wpc_param_status($plug,true);
}

function wordshell_wpc_param_status($plug) {
	_wordshell_wpc_param_status($plug,false);
}

function _wordshell_wpc_param_status($plug,$network_wide) {

	if ($network_wide) {
		$is_active = is_plugin_active_for_network($plug);
	} else {
		if ($network_wide == false && preg_match("/^(.*):(.*)$/",$plug,$matches)) {
			$plug = $matches[1];
			$siteid = $matches[2];
			if (!is_numeric($siteid)) $siteid = get_id_from_blogname($siteid);
			if (!is_numeric($siteid)) { echo "ERROR:NoSuchBlog"; return; }
			if (!function_exists('switch_to_blog')) require_once(ABSPATH.'wp-includes/ms-blogs.php');
			if ( switch_to_blog($siteid, true) === false ) { echo "ERROR:NoSuchBlog"; return; }
		}

		# Plugin is active if it is network active or active on this particular site
		$is_active = (is_multisite() && is_plugin_active_for_network($plug) == true) ? true : is_plugin_active($plug);
	}

	$stat_result = ($is_active) ? "yes" : "no";
	echo "STATUS:".$stat_result;
}

function wordshell_wpc_param_execphp($php) {
	eval($php);
}

function wordshell_wpc_dbdump() {
	wordshell_backup_db();
}

// Backup database routines
// This next chunk of code came from UpdraftPlus (http://wordpress.org/extend/plugins/updraftplus)
function wordshell_backup_db() {

	$total_tables = 0;

	global $table_prefix, $wpdb, $wordshell_version;

	$tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);
	$tables = array_map(create_function('$a', 'return $a[0];'), $tables);
	
	//Begin new backup of MySql
	wordshell_stow("# " . __('WordPress MySQL database backup','wp-db-backup') . "\n");
	wordshell_stow("# Produced by WordShell (controller version $wordshell_version) - http://wordshell.net\n");
	wordshell_stow("#\n");
	wordshell_stow("# " . sprintf(__('Generated: %s','wp-db-backup'),date("l j F Y H:i T")) . "\n");
	wordshell_stow("# " . sprintf(__('Hostname: %s','wp-db-backup'),DB_HOST) . "\n");
	wordshell_stow("# " . sprintf(__('Database: %s','wp-db-backup'),wordshell_backquote(DB_NAME)) . "\n");
	wordshell_stow("# --------------------------------------------------------\n");

	if (defined("DB_CHARSET")) {
		wordshell_stow("/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n");
		wordshell_stow("/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n");
		wordshell_stow("/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n");
		wordshell_stow("/*!40101 SET NAMES " . DB_CHARSET . " */;\n");
	}
	wordshell_stow("/*!40101 SET foreign_key_checks = 0 */;\n");

	foreach ($tables as $table) {
		$total_tables++;
		// Increase script execution time-limit to 15 min for every table.
		if ( !ini_get('safe_mode')) @set_time_limit(15*60);
		# Note: === is important here, otherwise 'false' is also matched (i.e. string not present)
		if ( strpos($table, $table_prefix) === 0 ) {
			// Create the SQL statements
			wordshell_stow("# --------------------------------------------------------\n");
			wordshell_stow("# Table: ".wordshell_backquote($table) . "\n");
			wordshell_stow("# --------------------------------------------------------\n");
			wordshell_backup_table($table);
		} else {
			wordshell_stow("# --------------------------------------------------------\n");
			wordshell_stow("# Skipping non-WP table: ".wordshell_backquote($table) . "\n");
			wordshell_stow("# --------------------------------------------------------\n");				
		}
	}

		if (defined("DB_CHARSET")) {
			wordshell_stow("/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n");
			wordshell_stow("/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n");
			wordshell_stow("/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n");
		}


} //wp_db_backup

/**
	* Taken partially from phpMyAdmin and partially from
	* Alain Wolf, Zurich - Switzerland
	* Website: http://restkultur.ch/personal/wolf/scripts/db_backup/
	* Modified by Scott Merrill (http://www.skippy.net/) 
	* to use the WordPress $wpdb object
	* @param string $table
	* @param string $segment
	* @return void
	*/

function wordshell_backup_table($table, $segment = 'none') {
	global $wpdb;

	$total_rows = 0;

	$table_structure = $wpdb->get_results("DESCRIBE $table");
	if (! $table_structure) {
		//$this->error(__('Error getting table details','wp-db-backup') . ": $table");
		return false;
	}

	if(($segment == 'none') || ($segment == 0)) {
		// Add SQL statement to drop existing table
		wordshell_stow("\n\n");
		wordshell_stow("# " . sprintf(__('Delete any existing table %s','wp-db-backup'),wordshell_backquote($table)) . "\n");
		wordshell_stow("#\n");
		wordshell_stow("\n");
		wordshell_stow("DROP TABLE IF EXISTS " . wordshell_backquote($table) . ";\n");
		
		// Table structure
		// Comment in SQL-file
		wordshell_stow("\n\n");
		wordshell_stow("# " . sprintf(__('Table structure of table %s','wp-db-backup'),wordshell_backquote($table)) . "\n");
		wordshell_stow("#\n");
		wordshell_stow("\n");
		
		$create_table = $wpdb->get_results("SHOW CREATE TABLE $table", ARRAY_N);
		if (false === $create_table) {
			$err_msg = sprintf(__('Error with SHOW CREATE TABLE for %s.','wp-db-backup'), $table);
			//$this->error($err_msg);
			wordshell_stow("#\n# $err_msg\n#\n");
		}
		wordshell_stow($create_table[0][1] . ' ;');
		
		if (false === $table_structure) {
			$err_msg = sprintf(__('Error getting table structure of %s','wp-db-backup'), $table);
			//$this->error($err_msg);
			wordshell_stow("#\n# $err_msg\n#\n");
		}
	
		// Comment in SQL-file
		wordshell_stow("\n\n");
		wordshell_stow('# ' . sprintf(__('Data contents of table %s','wp-db-backup'),wordshell_backquote($table)) . "\n");
		wordshell_stow("#\n");
	}
	
	if(($segment == 'none') || ($segment >= 0)) {
		$defs = array();
		$ints = array();
		foreach ($table_structure as $struct) {
			if ( (0 === strpos($struct->Type, 'tinyint')) ||
				(0 === strpos(strtolower($struct->Type), 'smallint')) ||
				(0 === strpos(strtolower($struct->Type), 'mediumint')) ||
				(0 === strpos(strtolower($struct->Type), 'int')) ||
				(0 === strpos(strtolower($struct->Type), 'bigint')) ) {
					$defs[strtolower($struct->Field)] = ( null === $struct->Default ) ? 'NULL' : $struct->Default;
					$ints[strtolower($struct->Field)] = "1";
			}
		}
		
		
		// Batch by $row_inc
		if ( ! defined('ROWS_PER_SEGMENT') ) {
			define('ROWS_PER_SEGMENT', 1000);
		}
		
		if($segment == 'none') {
			$row_start = 0;
			$row_inc = ROWS_PER_SEGMENT;
		} else {
			$row_start = $segment * ROWS_PER_SEGMENT;
			$row_inc = ROWS_PER_SEGMENT;
		}
		do {	
			// don't include extra stuff, if so requested
			$excs = array('revisions' => 0, 'spam' => 1); //TODO, FIX THIS
			$where = '';
			if ( is_array($excs['spam'] ) && in_array($table, $excs['spam']) ) {
				$where = ' WHERE comment_approved != "spam"';
			} elseif ( is_array($excs['revisions'] ) && in_array($table, $excs['revisions']) ) {
				$where = ' WHERE post_type != "revision"';
			}
			
			if ( !ini_get('safe_mode')) @set_time_limit(15*60);
			$table_data = $wpdb->get_results("SELECT * FROM $table $where LIMIT {$row_start}, {$row_inc}", ARRAY_A);
			$entries = 'INSERT INTO ' . wordshell_backquote($table) . ' VALUES (';	
			//    \x08\\x09, not required
			$search = array("\x00", "\x0a", "\x0d", "\x1a");
			$replace = array('\0', '\n', '\r', '\Z');
			if($table_data) {
				foreach ($table_data as $row) {
					$total_rows++;
					$values = array();
					foreach ($row as $key => $value) {
						if (!empty($ints[strtolower($key)])) {
							// make sure there are no blank spots in the insert syntax,
							// yet try to avoid quotation marks around integers
							$value = ( null === $value || '' === $value) ? $defs[strtolower($key)] : $value;
							$values[] = ( '' === $value ) ? "''" : $value;
						} else {
							$values[] = "'" . str_replace($search, $replace, wordshell_sql_addslashes($value)) . "'";
						}
					}
					wordshell_stow(" \n" . $entries . implode(', ', $values) . ');');
				}
				$row_start += $row_inc;
			}
		} while((count($table_data) > 0) and ($segment=='none'));
	}
	
	if(($segment == 'none') || ($segment < 0)) {
		// Create footer/closing comment in SQL-file
		wordshell_stow("\n");
		wordshell_stow("#\n");
		wordshell_stow("# " . sprintf(__('End of data contents of table %s','wp-db-backup'),wordshell_backquote($table)) . "\n");
		wordshell_stow("# --------------------------------------------------------\n");
		wordshell_stow("\n");
	}

} // end backup_table()


function wordshell_stow($query_line) {
	print $query_line;
}


/**
	* Add backquotes to tables and db-names in
	* SQL queries. Taken from phpMyAdmin.
	*/
function wordshell_backquote($a_name) {
	if (!empty($a_name) && $a_name != '*') {
		if (is_array($a_name)) {
			$result = array();
			reset($a_name);
			while(list($key, $val) = each($a_name)) 
				$result[$key] = '`' . $val . '`';
			return $result;
		} else {
			return '`' . $a_name . '`';
		}
	} else {
		return $a_name;
	}
}

/**
	* Better addslashes for SQL queries.
	* Taken from phpMyAdmin.
	*/
function wordshell_sql_addslashes($a_string = '', $is_like = false) {
	if ($is_like) $a_string = str_replace('\\', '\\\\\\\\', $a_string);
	else $a_string = str_replace('\\', '\\\\', $a_string);
	return str_replace('\'', '\\\'', $a_string);
} 
