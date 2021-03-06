<?php

/**
 * This file is part of playSMS.
 *
 * playSMS is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * playSMS is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with playSMS. If not, see <http://www.gnu.org/licenses/>.
 */
defined('_SECURE_') or die('Forbidden');

/**
 * Validate username and password
 *
 * @param string $username
 *        Username
 * @param string $password
 *        Password
 * @return boolean TRUE when validated or boolean FALSE when validation failed
 */
function auth_validate_login($username, $password) {

	// fixme anton - sanitize username
	if (!($username && $username == core_sanitize_username($username))) {
		_log('invalid username u:' . $username . ' ip:' . $_SERVER['REMOTE_ADDR'], 2, 'auth_validate_login');

		return FALSE;
	}

	$uid = user_username2uid($username);
	_log('login attempt u:' . $username . ' uid:' . $uid . ' ip:' . $_SERVER['REMOTE_ADDR'], 3, 'auth_validate_login');
	
	// check blacklist
	if (blacklist_ifipexists($username, $_SERVER['REMOTE_ADDR'])) {
		_log('IP blacklisted u:' . $username . ' uid:' . $uid . ' ip:' . $_SERVER['REMOTE_ADDR'], 2, 'auth_validate_login');
		return FALSE;
	}
	
	if (user_banned_get($uid)) {
		_log('user banned u:' . $username . ' uid:' . $uid . ' ip:' . $_SERVER['REMOTE_ADDR'], 2, 'auth_validate_login');
		return FALSE;
	}
	$db_query = "SELECT password,salt FROM " . _DB_PREF_ . "_tblUser WHERE flag_deleted='0' AND username='$username'";
	$db_result = dba_query($db_query);
	$db_row = dba_fetch_array($db_result);
	$res_password = trim($db_row['password']);
	$res_salt = trim($db_row['salt']);
	if ($password && $res_password && password_verify($password, $res_password)) {
		_log('valid login u:' . $username . ' uid:' . $uid . ' ip:' . $_SERVER['REMOTE_ADDR'], 2, 'auth_validate_login');
		
		// remove IP on successful login
		blacklist_clearip($username, $_SERVER['REMOTE_ADDR']);
		
		return true;
	} else {
		$ret = registry_search(1, 'auth', 'tmp_password', $username);
		$tmp_password = $ret['auth']['tmp_password'][$username];
		if ($password && $tmp_password && password_verify($password, $tmp_password)) {
			_log('valid login u:' . $username . ' uid:' . $uid . ' ip:' . $_SERVER['REMOTE_ADDR'] . ' using temporary password', 2, 'auth_validate_login');
			if (!registry_remove(1, 'auth', 'tmp_password', $username)) {
				_log('WARNING: unable to remove temporary password after successful login', 2, 'auth_validate_login');
			}
			
			// remove IP on successful login
			blacklist_clearip($username, $_SERVER['REMOTE_ADDR']);
			
			return true;
		} else {

			// fixme anton
			// this part is temporary until all users use the new password hash
			// in this part playSMS will convert md5 password to bcrypt hash if password matched

			if ($password && $res_password && (($res_password == md5($password)) || ($res_password == md5($password.$res_salt)))) {

				// password matched with old md5 password, convert it to bcrypt hash
				$new_password = password_hash($password, PASSWORD_BCRYPT);
				$db_query = "UPDATE " . _DB_PREF_ . "_tblUser SET password='$new_password',salt='' WHERE flag_deleted='0' AND username='$username'";
				if (dba_affected_rows($db_query)) {
					_log('WARNING: md5 password converted u:' . $username, 2, 'auth_validate_login');
					
					// remove IP on successful login
					blacklist_clearip($username, $_SERVER['REMOTE_ADDR']);
			
					return true;
				} else {
					// check blacklist
					blacklist_checkip($username, $_SERVER['REMOTE_ADDR']);
					
					_log('WARNING: fail to convert md5 password u:' . $username, 2, 'auth_validate_login');

					return false;
				}
			}
		}
	}
	
	// check blacklist
	blacklist_checkip($username, $_SERVER['REMOTE_ADDR']);
	
	_log('invalid login u:' . $username . ' uid:' . $uid . ' ip:' . $_SERVER['REMOTE_ADDR'], 2, 'auth_validate_login');
	return false;
}

/**
 * Validate email and password
 *
 * @param string $email
 *        Username
 * @param string $password
 *        Password
 * @return boolean TRUE when validated or boolean FALSE when validation failed
 */
function auth_validate_email($email, $password) {
	$username = user_email2username($email);
	_log('login attempt email:' . $email . ' u:' . $username . ' ip:' . $_SERVER['REMOTE_ADDR'], 3, 'auth_validate_email');
	return auth_validate_login($username, $password);
}

/**
 * Validate token
 *
 * @param string $token
 *        Token
 * @return string User ID when validated or boolean FALSE when validation failed
 */
function auth_validate_token($token) {
	$token = trim($token);
	if (_APP_ == 'main' || _APP_ == 'menu') {
		_log('login attempt token:' . $token . ' ip:' . $_SERVER['REMOTE_ADDR'], 3, 'auth_validate_token');
	}
	
	if ($token) {
		$db_query = "SELECT uid,username,enable_webservices,webservices_ip FROM " . _DB_PREF_ . "_tblUser WHERE flag_deleted='0' AND token='$token'";
		$db_result = dba_query($db_query);
		$db_row = dba_fetch_array($db_result);
		$uid = (int) $db_row['uid'];
		$username = trim($db_row['username']);
		$enable_webservices = (bool) $db_row['enable_webservices'];
		$webservices_ip = trim($db_row['webservices_ip']);
		
		// check blacklist
		if (blacklist_ifipexists($username, $_SERVER['REMOTE_ADDR'])) {
			_log('IP blacklisted u:' . $username . ' uid:' . $uid . ' ip:' . $_SERVER['REMOTE_ADDR'], 2, 'auth_validate_token');
			
			return FALSE;
		}
		
		if ($uid && $username && $enable_webservices && $webservices_ip) {
			$nets = explode(',', $webservices_ip);
			if (is_array($nets)) {
				foreach ($nets as $net) {
					if (core_net_match($net, $_SERVER['REMOTE_ADDR'])) {
						if (user_banned_get($uid)) {
							_log('user banned u:' . $username . ' uid:' . $uid . ' ip:' . $_SERVER['REMOTE_ADDR'] . ' net:' . $net, 2, 'auth_validate_token');
							
							return FALSE;
						}
						
						if (_APP_ == 'main' || _APP_ == 'menu') {
							_log('valid login u:' . $username . ' uid:' . $uid . ' ip:' . $_SERVER['REMOTE_ADDR'] . ' net:' . $net, 2, 'auth_validate_token');
						}
						
						// remove IP on successful login
						blacklist_clearip($username, $_SERVER['REMOTE_ADDR']);
						
						return $uid;
					}
				}
			}
		}
	}
	
	// check blacklist
	blacklist_checkip($username, $_SERVER['REMOTE_ADDR']);
	
	_log('invalid login t:' . $token . ' ip:' . $_SERVER['REMOTE_ADDR'], 2, 'auth_validate_token');
	
	return FALSE;
}

/**
 * Check if visitor has been validated
 *
 * @return boolean TRUE if valid
 */
function auth_isvalid() {
	if (session_id() && $_SESSION['uid']) {
		$hash = user_session_get('', session_id());
		if (session_id() == $hash[key($hash)]['sid'] && $_SESSION['uid'] == $hash[key($hash)]['uid']) {
			if ($hash[key($hash)]['http_user_agent'] && ($hash[key($hash)]['http_user_agent'] == core_sanitize_string($_SERVER['HTTP_USER_AGENT']))) {
				return acl_checkurl($_REQUEST, $_SESSION['uid']);
			}
		}
	}
	
	return FALSE;
}

/**
 * Check if visitor has admin access level
 *
 * @return boolean TRUE if valid and visitor has admin access level
 */
function auth_isadmin() {
	if ($_SESSION['status'] == 2) {
		if (auth_isvalid()) {
			return TRUE;
		}
	}
	return FALSE;
}

/**
 * Check if visitor has user access level
 *
 * @return boolean TRUE if valid and visitor has user access level
 */
function auth_isuser() {
	if ($_SESSION['status'] == 3) {
		if (auth_isvalid()) {
			return TRUE;
		}
	}
	return FALSE;
}

/**
 * Check if visitor has subuser access level
 *
 * @return boolean TRUE if valid and visitor has subuser access level
 */
function auth_issubuser() {
	if ($_SESSION['status'] == 4) {
		if (auth_isvalid()) {
			return TRUE;
		}
	}
	return FALSE;
}

/**
 * Check if visitor has certain user status
 *
 * @param string $status
 *        Account status
 * @return boolean TRUE if valid and visitor has certain user status
 */
function auth_isstatus($status) {
	if ($_SESSION['status'] == (int) $status) {
		if (auth_isvalid()) {
			return TRUE;
		}
	}
	return FALSE;
}

/**
 * Check if visitor has certain ACL
 *
 * @param string $acl
 *        Access Control List
 * @return boolean TRUE if valid and visitor has certain ACL
 */
function auth_isacl($acl) {
	if (auth_isvalid()) {
		if (auth_isadmin()) {
			return TRUE;
		} else {
			$user_acl_id = user_getfieldbyuid($_SESSION['uid'], 'acl_id');
			$user_acl_name = acl_getname($user_acl_id);
			if ($acl && $user_acl_name && strtoupper($acl) == strtoupper($user_acl_name)) {
				return TRUE;
			}
		}
	}
	return FALSE;
}

/**
 * Display page for blocked access
 */
function auth_block() {
	header("Location: " . _u('index.php?app=main&inc=core_auth&route=block&op=block'));
	exit();
}

/**
 * Setup and renew user session
 *
 * @param string $uid
 *        User ID
 */
function auth_session_setup($uid) {
	global $core_config;

	@session_regenerate_id(TRUE);
	
	$c_user = user_getdatabyuid($uid);
	if ($c_user['username']) {
		// set session
		$_SESSION['username'] = $c_user['username'];
		$_SESSION['uid'] = $c_user['uid'];
		$_SESSION['status'] = $c_user['status'];
		if (!is_array($_SESSION['tmp']['login_as'])) {
			$_SESSION['tmp']['login_as'] = array();
		}
		
		// save session in registry
		if (!$core_config['daemon_process']) {
			user_session_set($c_user['uid']);
		}
	}
}

/**
 * Destroy user session
 */
function auth_session_destroy() {
	$_SESSION = array();

	if (ini_get('session.use_cookies')) {
		$params = session_get_cookie_params();
		setcookie(session_name(), '', time() - 42000,
			$params['path'], $params['domain'],
			$params['secure'], $params['httponly']
		);
	}

	session_destroy();
}

function auth_login_as($uid) {
	
	// save current login
	array_unshift($_SESSION['tmp']['login_as'], $_SESSION['uid']);
	
	// setup new session
	auth_session_setup($uid);
}

function auth_login_return() {
	
	// get previous login
	$previous_login = $_SESSION['tmp']['login_as'][0];
	array_shift($_SESSION['tmp']['login_as']);
	
	// return to previous session
	auth_session_setup($previous_login);
	
}

function auth_login_as_check() {
	if (count($_SESSION['tmp']['login_as']) > 0) {
		return TRUE;
	} else {
		return FALSE;
	}
}
