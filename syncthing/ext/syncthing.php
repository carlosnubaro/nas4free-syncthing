<?php
/* 
    syncthing.php

    Copyright (c) 2013, 2014, Andreas Schmidhuber
    All rights reserved.

    Redistribution and use in source and binary forms, with or without
    modification, are permitted provided that the following conditions are met:

    1. Redistributions of source code must retain the above copyright notice, this
       list of conditions and the following disclaimer.
    2. Redistributions in binary form must reproduce the above copyright notice,
       this list of conditions and the following disclaimer in the documentation
       and/or other materials provided with the distribution.

    THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
    ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
    WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
    DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR
    ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
    (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
    LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
    ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
    (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
    SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.

    The views and conclusions contained in the software and documentation are those
    of the authors and should not be interpreted as representing official policies,
    either expressed or implied, of the FreeBSD Project.
 */
require("auth.inc");
require("guiconfig.inc");
require_once("{$config['syncthing']['rootfolder']}files/xml_converter.php");

// Dummy standard message gettext calls for xgettext retrieval!!!
$dummy = gettext("The changes have been applied successfully.");
$dummy = gettext("The configuration has been changed.<br />You must apply the changes in order for them to take effect.");
$dummy = gettext("The following input errors were detected");

bindtextdomain("nas4free", "/usr/local/share/locale-stg");
$pgtitle = array(gettext("Extensions"), $config['syncthing']['appname']." ".$config['syncthing']['version']);

if ( !isset( $config['syncthing']['rootfolder']) && !is_dir( $config['syncthing']['rootfolder'] )) {
	$input_errors[] = gettext("Extension installed with fault!");
} 
if (!isset($config['syncthing']) || !is_array($config['syncthing'])) $config['syncthing'] = array();

require_once("{$config['syncthing']['rootfolder']}files/functions.inc");

/* Check if the directory exists, the mountpoint has at least o=rx permissions and
 * set the permission to 775 for the last directory in the path
 */
function change_perms($dir) {
    global $input_errors;

    $path = rtrim($dir,'/');                                            // remove trailing slash
    if (strlen($path) > 1) {
        if (!is_dir($path)) {                                           // check if directory exists
            $input_errors[] = sprintf(gettext("Directory %s doesn't exist!"), $path);
        }
        else {
            $path_check = explode("/", $path);                          // split path to get directory names
            $path_elements = count($path_check);                        // get path depth
            $fp = substr(sprintf('%o', fileperms("/$path_check[1]/$path_check[2]")), -1);   // get mountpoint permissions for others
            if ($fp >= 5) {                                             // transmission needs at least read & search permission at the mountpoint
                $directory = "/$path_check[1]/$path_check[2]";          // set to the mountpoint
                for ($i = 3; $i < $path_elements - 1; $i++) {           // traverse the path and set permissions to rx
                    $directory = $directory."/$path_check[$i]";         // add next level
                    exec("chmod o=+r+x \"$directory\"");                // set permissions to o=+r+x
                }
                $path_elements = $path_elements - 1;
                $directory = $directory."/$path_check[$path_elements]"; // add last level
                exec("chmod 775 {$directory}");                         // set permissions to 775
                exec("chown {$_POST['who']} {$directory}*");
            }
            else
            {
                $input_errors[] = sprintf(gettext("Syncthing needs at least read & execute permissions at the mount point for directory %s! Set the Read and Execute bits for Others (Access Restrictions | Mode) for the mount point %s (in <a href='disks_mount.php'>Disks | Mount Point | Management</a> or <a href='disks_zfs_dataset.php'>Disks | ZFS | Datasets</a>) and hit Save in order to take them effect."), $path, "/{$path_check[1]}/{$path_check[2]}");
            }
        }
    }
}

if (isset($_POST['save']) && $_POST['save']) {
    unset($input_errors);
    $pconfig = $_POST;
    if (!empty($_POST['storage_path'])) { change_perms($_POST['storage_path']); }
	if (empty($input_errors)) {
		if (isset($_POST['enable'])) {
            $config['syncthing']['enable'] = isset($_POST['enable']) ? true : false;
            $config['syncthing']['who'] = $_POST['who'];
            $config['syncthing']['listen_to_all'] = isset($_POST['listen_to_all']) ? true : false;
            $config['syncthing']['if'] = $_POST['if'];
            $config['syncthing']['ipaddr'] = get_ipaddr($_POST['if']);
            $config['syncthing']['port'] = $_POST['port'];
            if ($config['syncthing']['storage_path'] !== $_POST['storage_path']) {
                if (is_file("{$config['syncthing']['storage_path']}config.xml") && !is_file("{$_POST['storage_path']}config.xml")) {
                    mwexec("cp -v {$config['syncthing']['storage_path']}config.xml {$_POST['storage_path']}config.xml", true);
                }                
            }
            $config['syncthing']['storage_path'] = !empty($_POST['storage_path']) ? $_POST['storage_path'] : $config['syncthing']['rootfolder']."config/";
            $config['syncthing']['storage_path'] = rtrim($config['syncthing']['storage_path'],'/')."/";     // ensure to have a trailing slash
            exec("chown -R {$_POST['who']} {$config['syncthing']['storage_path']}");                        // syncthing user
//    		$savemsg = get_std_save_message(write_config());
    
            if (is_file($config['syncthing']['storage_path']."config.xml")) { $sync_conf = XML2Array::createArray(file_get_contents($config['syncthing']['storage_path']."config.xml")); }
            else { 
                $sync_conf = array();
                $sync_conf['configuration']['@attributes']['version'] = "6";
            }
            $sync_conf['configuration']['gui']['@attributes']['enabled'] = isset($_POST['gui_enabled']) ? true : false;
            $sync_conf['configuration']['gui']['@attributes']['tls'] = isset($_POST['gui_tls']) ? true : false;
            $sync_conf['configuration']['gui']['address'] = isset($_POST['listen_to_all']) ? '0.0.0.0:'.$config['syncthing']['port'] : $config['syncthing']['ipaddr'].':'.$config['syncthing']['port'];
/* 
            $sync_conf['configuration']['gui']['user'] = !empty($_POST['username']) ? $_POST['username'] : "";
            if ($sync_conf['configuration']['gui']['password'] !== $_POST['password']) {
                $sync_conf['configuration']['gui']['password'] = !empty($_POST['password']) ? (password_hash($_POST['password'], PASSWORD_BCRYPT)) : "";
            }
 */
            if (isset($_POST['resetuser'])) {
                unset($sync_conf['configuration']['gui']['user']);
                unset($sync_conf['configuration']['gui']['password']);
            }
            if ($_POST['autoUpgradeIntervalH'] == "0") { unset($sync_conf['configuration']['options']['autoUpgradeIntervalH']); }
            else { $sync_conf['configuration']['options']['autoUpgradeIntervalH'] = !empty($_POST['autoUpgradeIntervalH']) ? $_POST['autoUpgradeIntervalH'] : "12"; }
            $sync_conf['configuration']['options']['startBrowser'] = isset($_POST['startBrowser']) ? true : false;
            $sync_conf['configuration']['options']['listenAddress'] = !empty($_POST['listenAddress']) ? $_POST['listenAddress'] : "0.0.0.0:22000";
            $sync_conf['configuration']['options']['globalAnnounceServer'] = !empty($_POST['globalAnnounceServer']) ? $_POST['globalAnnounceServer'] : "announce.syncthing.net:22025";
            $sync_conf['configuration']['options']['globalAnnounceEnabled'] = isset($_POST['globalAnnounceEnabled']) ? true : false;
            $sync_conf['configuration']['options']['localAnnounceEnabled'] = isset($_POST['localAnnounceEnabled']) ? true : false;
            $sync_conf['configuration']['options']['localAnnouncePort'] = !empty($_POST['localAnnouncePort']) ? $_POST['localAnnouncePort'] : "21025";
            $sync_conf['configuration']['options']['localAnnounceMCAddr'] = !empty($_POST['localAnnounceMCAddr']) ? $_POST['localAnnounceMCAddr'] : "[ff32::5222]:21026";
            $sync_conf['configuration']['options']['maxSendKbps'] = !empty($_POST['maxSendKbps']) ? $_POST['maxSendKbps'] : "0";
            $sync_conf['configuration']['options']['maxRecvKbps'] = !empty($_POST['maxRecvKbps']) ? $_POST['maxRecvKbps'] : "0";
            $sync_conf['configuration']['options']['maxChangeKbps'] = !empty($_POST['maxChangeKbps']) ? $_POST['maxChangeKbps'] : "10000";
            $sync_conf['configuration']['options']['upnpEnabled'] = isset($_POST['upnpEnabled']) ? true : false;
            $sync_conf['configuration']['options']['upnpLeaseMinutes'] = !empty($_POST['upnpLeaseMinutes']) ? $_POST['upnpLeaseMinutes'] : "0";
            $sync_conf['configuration']['options']['upnpRenewalMinutes'] = !empty($_POST['upnpRenewalMinutes']) ? $_POST['upnpRenewalMinutes'] : "30";
            $sync_conf['configuration']['options']['urAccepted'] = !empty($_POST['urAccepted']) ? $_POST['urAccepted'] : "0";
            $sync_conf['configuration']['options']['restartOnWakeup'] = isset($_POST['restartOnWakeup']) ? true : false;
            $sync_conf['configuration']['options']['keepTemporariesH'] = !empty($_POST['keepTemporariesH']) ? $_POST['keepTemporariesH'] : "24";
            $sync_conf['configuration']['options']['cacheIgnoredFiles'] = isset($_POST['cacheIgnoredFiles']) ? true : false;
            $sync_conf['configuration']['options']['parallelRequests'] = !empty($_POST['parallelRequests']) ? $_POST['parallelRequests'] : "16";
            $sync_conf['configuration']['options']['rescanIntervalS'] = !empty($_POST['rescanIntervalS']) ? $_POST['rescanIntervalS'] : "60";
            $sync_conf['configuration']['options']['reconnectionIntervalS'] = !empty($_POST['reconnectionIntervalS']) ? $_POST['reconnectionIntervalS'] : "60";
    
    		$config['syncthing']['command'] = "su {$config['syncthing']['who']} -c '{$config['syncthing']['rootfolder']}syncthing -home {$config['syncthing']['storage_path']} -logflags=3 \&>> {$config['syncthing']['storage_path']}syncthing.log \& echo $! & '";
            $savemsg = get_std_save_message(write_config());
    
            $xmlout = Array2XML::createXML('configuration', $sync_conf['configuration']);
            $xmlout = $xmlout->saveXML();
            file_put_contents($config['syncthing']['storage_path']."config.xml", $xmlout);
    
            exec("killall -15 syncthing");
            $return_val = 0;
            while( $return_val == 0 ) { sleep(1); exec('ps acx | grep syncthing', $output, $return_val); }
            unset ($output);
            exec($config['syncthing']['command'], $output, $return_val);
            if ($return_val != 0) { $input_errors = $output; }
            if (isset($config['syncthing']['enable_schedule'])) {  // if cronjobs exists -> activate
                $cronjob = array();
                $a_cronjob = &$config['cron']['job'];
                $uuid = isset($config['syncthing']['schedule_uuid_startup']) ? $config['syncthing']['schedule_uuid_startup'] : false;
                if (isset($uuid) && (FALSE !== ($cnid = array_search_ex($uuid, $a_cronjob, "uuid")))) {
                	$cronjob['enable'] = true;
                	$cronjob['uuid'] = $a_cronjob[$cnid]['uuid'];
                	$cronjob['desc'] = $a_cronjob[$cnid]['desc'];
                	$cronjob['minute'] = $a_cronjob[$cnid]['minute'];
                	$cronjob['hour'] = $config['syncthing']['schedule_startup'];
                	$cronjob['day'] = $a_cronjob[$cnid]['day'];
                	$cronjob['month'] = $a_cronjob[$cnid]['month'];
                	$cronjob['weekday'] = $a_cronjob[$cnid]['weekday'];
                	$cronjob['all_mins'] = $a_cronjob[$cnid]['all_mins'];
                	$cronjob['all_hours'] = $a_cronjob[$cnid]['all_hours'];
                	$cronjob['all_days'] = $a_cronjob[$cnid]['all_days'];
                	$cronjob['all_months'] = $a_cronjob[$cnid]['all_months'];
                	$cronjob['all_weekdays'] = $a_cronjob[$cnid]['all_weekdays'];
                	$cronjob['who'] = $a_cronjob[$cnid]['who'];
                	$cronjob['command'] = $a_cronjob[$cnid]['command'];
                }
                if (isset($uuid) && (FALSE !== $cnid)) {
                		$a_cronjob[$cnid] = $cronjob;
                		$mode = UPDATENOTIFY_MODE_MODIFIED;
                	} else {
                		$a_cronjob[] = $cronjob;
                		$mode = UPDATENOTIFY_MODE_NEW;
                	}
                updatenotify_set("cronjob", $mode, $cronjob['uuid']);
                write_config();

                unset ($cronjob);
                $cronjob = array();
                $a_cronjob = &$config['cron']['job'];
                $uuid = isset($config['syncthing']['schedule_uuid_closedown']) ? $config['syncthing']['schedule_uuid_closedown'] : false;
                if (isset($uuid) && (FALSE !== ($cnid = array_search_ex($uuid, $a_cronjob, "uuid")))) {
                	$cronjob['enable'] = true;
                	$cronjob['uuid'] = $a_cronjob[$cnid]['uuid'];
                	$cronjob['desc'] = $a_cronjob[$cnid]['desc'];
                	$cronjob['minute'] = $a_cronjob[$cnid]['minute'];
                	$cronjob['hour'] = $config['syncthing']['schedule_closedown'];
                	$cronjob['day'] = $a_cronjob[$cnid]['day'];
                	$cronjob['month'] = $a_cronjob[$cnid]['month'];
                	$cronjob['weekday'] = $a_cronjob[$cnid]['weekday'];
                	$cronjob['all_mins'] = $a_cronjob[$cnid]['all_mins'];
                	$cronjob['all_hours'] = $a_cronjob[$cnid]['all_hours'];
                	$cronjob['all_days'] = $a_cronjob[$cnid]['all_days'];
                	$cronjob['all_months'] = $a_cronjob[$cnid]['all_months'];
                	$cronjob['all_weekdays'] = $a_cronjob[$cnid]['all_weekdays'];
                	$cronjob['who'] = $a_cronjob[$cnid]['who'];
                	$cronjob['command'] = $a_cronjob[$cnid]['command'];
                }
                if (isset($uuid) && (FALSE !== $cnid)) {
                		$a_cronjob[$cnid] = $cronjob;
                		$mode = UPDATENOTIFY_MODE_MODIFIED;
                	} else {
                		$a_cronjob[] = $cronjob;
                		$mode = UPDATENOTIFY_MODE_NEW;
                	}
                updatenotify_set("cronjob", $mode, $cronjob['uuid']);
                write_config();
                header("Location: syncthing.php");

        		$retval = 0;
        		if (!file_exists($d_sysrebootreqd_path)) {
        			$retval |= updatenotify_process("cronjob", "cronjob_process_updatenotification");
        			config_lock();
        			$retval |= rc_update_service("cron");
        			config_unlock();
        		}
        		$savemsg = get_std_save_message($retval);
        		if ($retval == 0) {
        			updatenotify_delete("cronjob");
        		}
            }   // end of activate cronjobs
        }   // end of enable extension
		else { 
            exec("killall -15 syncthing"); $savemsg = $savemsg." ".$config['syncthing']['appname'].gettext(" is now disabled!"); 
            $config['syncthing']['enable'] = isset($_POST['enable']) ? true : false;
            write_config();
            if (isset($config['syncthing']['enable_schedule'])) {  // if cronjobs exists -> deactivate
                $cronjob = array();
                $a_cronjob = &$config['cron']['job'];
                $uuid = isset($config['syncthing']['schedule_uuid_startup']) ? $config['syncthing']['schedule_uuid_startup'] : false;
                if (isset($uuid) && (FALSE !== ($cnid = array_search_ex($uuid, $a_cronjob, "uuid")))) {
                	$cronjob['enable'] = false;
                	$cronjob['uuid'] = $a_cronjob[$cnid]['uuid'];
                	$cronjob['desc'] = $a_cronjob[$cnid]['desc'];
                	$cronjob['minute'] = $a_cronjob[$cnid]['minute'];
                	$cronjob['hour'] = $config['syncthing']['schedule_startup'];
                	$cronjob['day'] = $a_cronjob[$cnid]['day'];
                	$cronjob['month'] = $a_cronjob[$cnid]['month'];
                	$cronjob['weekday'] = $a_cronjob[$cnid]['weekday'];
                	$cronjob['all_mins'] = $a_cronjob[$cnid]['all_mins'];
                	$cronjob['all_hours'] = $a_cronjob[$cnid]['all_hours'];
                	$cronjob['all_days'] = $a_cronjob[$cnid]['all_days'];
                	$cronjob['all_months'] = $a_cronjob[$cnid]['all_months'];
                	$cronjob['all_weekdays'] = $a_cronjob[$cnid]['all_weekdays'];
                	$cronjob['who'] = $a_cronjob[$cnid]['who'];
                	$cronjob['command'] = $a_cronjob[$cnid]['command'];
                } 
                if (isset($uuid) && (FALSE !== $cnid)) {
                		$a_cronjob[$cnid] = $cronjob;
                		$mode = UPDATENOTIFY_MODE_MODIFIED;
                	} else {
                		$a_cronjob[] = $cronjob;
                		$mode = UPDATENOTIFY_MODE_NEW;
                	}
                updatenotify_set("cronjob", $mode, $cronjob['uuid']);
                write_config();
    
                unset ($cronjob);
                $cronjob = array();
                $a_cronjob = &$config['cron']['job'];
                $uuid = isset($config['syncthing']['schedule_uuid_closedown']) ? $config['syncthing']['schedule_uuid_closedown'] : false;
                if (isset($uuid) && (FALSE !== ($cnid = array_search_ex($uuid, $a_cronjob, "uuid")))) {
                	$cronjob['enable'] = false;
                	$cronjob['uuid'] = $a_cronjob[$cnid]['uuid'];
                	$cronjob['desc'] = $a_cronjob[$cnid]['desc'];
                	$cronjob['minute'] = $a_cronjob[$cnid]['minute'];
                	$cronjob['hour'] = $config['syncthing']['schedule_closedown'];
                	$cronjob['day'] = $a_cronjob[$cnid]['day'];
                	$cronjob['month'] = $a_cronjob[$cnid]['month'];
                	$cronjob['weekday'] = $a_cronjob[$cnid]['weekday'];
                	$cronjob['all_mins'] = $a_cronjob[$cnid]['all_mins'];
                	$cronjob['all_hours'] = $a_cronjob[$cnid]['all_hours'];
                	$cronjob['all_days'] = $a_cronjob[$cnid]['all_days'];
                	$cronjob['all_months'] = $a_cronjob[$cnid]['all_months'];
                	$cronjob['all_weekdays'] = $a_cronjob[$cnid]['all_weekdays'];
                	$cronjob['who'] = $a_cronjob[$cnid]['who'];
                	$cronjob['command'] = $a_cronjob[$cnid]['command'];
                } 
                if (isset($uuid) && (FALSE !== $cnid)) {
                		$a_cronjob[$cnid] = $cronjob;
                		$mode = UPDATENOTIFY_MODE_MODIFIED;
                	} else {
                		$a_cronjob[] = $cronjob;
                		$mode = UPDATENOTIFY_MODE_NEW;
                	}
                updatenotify_set("cronjob", $mode, $cronjob['uuid']);
                write_config();
                header("Location: syncthing.php");
    
        		$retval = 0;
        		if (!file_exists($d_sysrebootreqd_path)) {
        			$retval |= updatenotify_process("cronjob", "cronjob_process_updatenotification");
        			config_lock();
        			$retval |= rc_update_service("cron");
        			config_unlock();
        		}
        		$savemsg = get_std_save_message($retval);
        		if ($retval == 0) {
        			updatenotify_delete("cronjob");
        		}
            }   // end of deactivate cronjobs
        }   // end of disable extension
    }   // end of empty input_errors
}

$pconfig['enable'] = isset($config['syncthing']['enable']);
$pconfig['who'] = !empty($config['syncthing']['who']) ? $config['syncthing']['who'] : "";
$pconfig['if'] = !empty($config['syncthing']['if']) ? $config['syncthing']['if'] : "";
$pconfig['ipaddr'] = !empty($config['syncthing']['ipaddr']) ? $config['syncthing']['ipaddr'] : "";
$pconfig['port'] = !empty($config['syncthing']['port']) ? $config['syncthing']['port'] : "9999";
$pconfig['listen_to_all'] = isset($config['syncthing']['listen_to_all']);
$pconfig['storage_path'] = !empty($config['syncthing']['storage_path']) ? $config['syncthing']['storage_path'] : $config['syncthing']['rootfolder']."config/";
if (is_file($config['syncthing']['storage_path']."config.xml")) { $sync_conf = XML2Array::createArray(file_get_contents($config['syncthing']['storage_path']."config.xml")); } 
$pconfig['gui_enabled'] = isset($sync_conf['configuration']['gui']['@attributes']['enabled']) ? $sync_conf['configuration']['gui']['@attributes']['enabled'] : "true";
$pconfig['gui_tls'] = isset($sync_conf['configuration']['gui']['@attributes']['tls']) ? $sync_conf['configuration']['gui']['@attributes']['tls'] : "true";
$pconfig['address'] = !empty($sync_conf['configuration']['gui']['address']) ? $sync_conf['configuration']['gui']['address'] : "{$pconfig['ipaddr']}:{$pconfig['port']}";
/* 
$pconfig['username'] = !empty($sync_conf['configuration']['gui']['user']) ? $sync_conf['configuration']['gui']['user'] : "";
$pconfig['password'] = !empty($sync_conf['configuration']['gui']['password']) ? $sync_conf['configuration']['gui']['password'] : "";
 */
$pconfig['autoUpgradeIntervalH'] = !empty($sync_conf['configuration']['options']['autoUpgradeIntervalH']) ? $sync_conf['configuration']['options']['autoUpgradeIntervalH'] : "12";
$pconfig['startBrowser'] = isset($sync_conf['configuration']['options']['startBrowser']) ? $sync_conf['configuration']['options']['startBrowser'] : "false";
$pconfig['listenAddress'] = !empty($sync_conf['configuration']['options']['listenAddress']) ? $sync_conf['configuration']['options']['listenAddress'] : "0.0.0.0:22000";
$pconfig['globalAnnounceServer'] = !empty($sync_conf['configuration']['options']['globalAnnounceServer']) ? $sync_conf['configuration']['options']['globalAnnounceServer'] : "announce.syncthing.net:22025";
$pconfig['globalAnnounceEnabled'] = isset($sync_conf['configuration']['options']['globalAnnounceEnabled']) ? $sync_conf['configuration']['options']['globalAnnounceEnabled'] : "true";
$pconfig['localAnnounceEnabled'] = isset($sync_conf['configuration']['options']['localAnnounceEnabled']) ? $sync_conf['configuration']['options']['localAnnounceEnabled'] : "true";
$pconfig['localAnnouncePort'] = !empty($sync_conf['configuration']['options']['localAnnouncePort']) ? $sync_conf['configuration']['options']['localAnnouncePort'] : "21025";
$pconfig['localAnnounceMCAddr'] = !empty($sync_conf['configuration']['options']['localAnnounceMCAddr']) ? $sync_conf['configuration']['options']['localAnnounceMCAddr'] : "[ff32::5222]:21026";
$pconfig['maxSendKbps'] = !empty($sync_conf['configuration']['options']['maxSendKbps']) ? $sync_conf['configuration']['options']['maxSendKbps'] : "0";
$pconfig['maxRecvKbps'] = !empty($sync_conf['configuration']['options']['maxRecvKbps']) ? $sync_conf['configuration']['options']['maxRecvKbps'] : "0";
$pconfig['maxChangeKbps'] = !empty($sync_conf['configuration']['options']['maxChangeKbps']) ? $sync_conf['configuration']['options']['maxChangeKbps'] : "10000";
$pconfig['upnpEnabled'] = isset($sync_conf['configuration']['options']['upnpEnabled']) ? $sync_conf['configuration']['options']['upnpEnabled'] : "true";
$pconfig['upnpLeaseMinutes'] = !empty($sync_conf['configuration']['options']['upnpLeaseMinutes']) ? $sync_conf['configuration']['options']['upnpLeaseMinutes'] : "0";
$pconfig['upnpRenewalMinutes'] = !empty($sync_conf['configuration']['options']['upnpRenewalMinutes']) ? $sync_conf['configuration']['options']['upnpRenewalMinutes'] : "30";
$pconfig['urAccepted'] = !empty($sync_conf['configuration']['options']['urAccepted']) ? $sync_conf['configuration']['options']['urAccepted'] : "0";
$pconfig['restartOnWakeup'] = isset($sync_conf['configuration']['options']['restartOnWakeup']) ? $sync_conf['configuration']['options']['restartOnWakeup'] : "false";
$pconfig['keepTemporariesH'] = !empty($sync_conf['configuration']['options']['keepTemporariesH']) ? $sync_conf['configuration']['options']['keepTemporariesH'] : "24";
$pconfig['cacheIgnoredFiles'] = isset($sync_conf['configuration']['options']['cacheIgnoredFiles']) ? $sync_conf['configuration']['options']['cacheIgnoredFiles'] : "true";
$pconfig['parallelRequests'] = !empty($sync_conf['configuration']['options']['parallelRequests']) ? $sync_conf['configuration']['options']['parallelRequests'] : "16";
$pconfig['rescanIntervalS'] = !empty($sync_conf['configuration']['options']['rescanIntervalS']) ? $sync_conf['configuration']['options']['rescanIntervalS'] : "60";
$pconfig['reconnectionIntervalS'] = !empty($sync_conf['configuration']['options']['reconnectionIntervalS']) ? $sync_conf['configuration']['options']['reconnectionIntervalS'] : "60";

$a_interface = get_interface_list();
// Add VLAN interfaces (from user Vasily1)
if (isset($config['vinterfaces']['vlan']) && is_array($config['vinterfaces']['vlan']) && count($config['vinterfaces']['vlan'])) {
   foreach ($config['vinterfaces']['vlan'] as $vlanv) {
      $a_interface[$vlanv['if']] = $vlanv;
      $a_interface[$vlanv['if']]['isvirtual'] = true;
   }
}
// Add LAGG interfaces (from user Vasily1)
if (isset($config['vinterfaces']['lagg']) && is_array($config['vinterfaces']['lagg']) && count($config['vinterfaces']['lagg'])) {
   foreach ($config['vinterfaces']['lagg'] as $laggv) {
      $a_interface[$laggv['if']] = $laggv;
      $a_interface[$laggv['if']]['isvirtual'] = true;
   }
}

// Use first interface as default if it is not set.
if (empty($pconfig['if']) && is_array($a_interface)) $pconfig['if'] = key($a_interface);

function get_process_info() {
    if (exec('ps acx | grep syncthing')) { $state = '<a style=" background-color: #00ff00; ">&nbsp;&nbsp;<b>'.gettext("running").'</b>&nbsp;&nbsp;</a>'; }
    else { $state = '<a style=" background-color: #ff0000; ">&nbsp;&nbsp;<b>'.gettext("stopped").'</b>&nbsp;&nbsp;</a>'; }
	return ($state);
}

if (is_ajax()) {
	$procinfo = get_process_info();
	render_ajax($procinfo);
}

bindtextdomain("nas4free", "/usr/local/share/locale");
include("fbegin.inc");?>  
<script type="text/javascript">//<![CDATA[
$(document).ready(function(){
	var gui = new GUI;
	gui.recall(0, 2000, 'syncthing.php', null, function(data) {
		$('#procinfo').html(data.data);
	});
});
//]]>
</script>
<script type="text/javascript">
<!--
function enable_change(enable_change) {
	var endis = !(document.iform.enable.checked || enable_change);
	document.iform.xif.disabled = endis;
	document.iform.port.disabled = endis;
	document.iform.listen_to_all.disabled = endis;
	document.iform.gui_tls.disabled = endis;
	document.iform.autoUpgradeIntervalH.disabled = endis;
	document.iform.resetuser.disabled = endis;
//	document.iform.username.disabled = endis;
//	document.iform.password.disabled = endis;
	document.iform.who.disabled = endis;
	document.iform.storage_path.disabled = endis;
	document.iform.storage_pathbrowsebtn.disabled = endis;
	document.iform.gui_enabled.disabled = endis;
	document.iform.listenAddress.disabled = endis;
	document.iform.globalAnnounceServer.disabled = endis;
	document.iform.globalAnnounceEnabled.disabled = endis;
	document.iform.localAnnounceEnabled.disabled = endis;
	document.iform.localAnnouncePort.disabled = endis;
	document.iform.localAnnounceMCAddr.disabled = endis;
	document.iform.maxSendKbps.disabled = endis;
	document.iform.maxRecvKbps.disabled = endis;
	document.iform.maxChangeKbps.disabled = endis;
	document.iform.upnpEnabled.disabled = endis;
	document.iform.upnpLeaseMinutes.disabled = endis;
	document.iform.upnpRenewalMinutes.disabled = endis;
	document.iform.urAccepted.disabled = endis;
	document.iform.restartOnWakeup.disabled = endis;
	document.iform.keepTemporariesH.disabled = endis;
	document.iform.cacheIgnoredFiles.disabled = endis;
	document.iform.parallelRequests.disabled = endis;
	document.iform.rescanIntervalS.disabled = endis;
	document.iform.reconnectionIntervalS.disabled = endis;
}

function as_change() {
	switch(document.iform.as_enable.checked) {
		case false:
			showElementById('who_tr','hide');
			showElementById('xif_tr','hide');
			showElementById('storage_path_tr','hide');
			showElementById('gui_enabled_tr','hide');
			showElementById('listenAddress_tr','hide');
			showElementById('globalAnnounceServer_tr','hide');
			showElementById('globalAnnounceEnabled_tr','hide');
			showElementById('localAnnounceEnabled_tr','hide');
			showElementById('localAnnouncePort_tr','hide');
			showElementById('localAnnounceMCAddr_tr','hide');
			showElementById('maxSendKbps_tr','hide');
			showElementById('maxRecvKbps_tr','hide');
			showElementById('maxChangeKbps_tr','hide');
			showElementById('upnpEnabled_tr','hide');
			showElementById('upnpLeaseMinutes_tr','hide');
			showElementById('upnpRenewalMinutes_tr','hide');
			showElementById('urAccepted_tr','hide');
			showElementById('restartOnWakeup_tr','hide');
			showElementById('keepTemporariesH_tr','hide');
			showElementById('cacheIgnoredFiles_tr','hide');
			showElementById('parallelRequests_tr','hide');
			showElementById('rescanIntervalS_tr','hide');
			showElementById('reconnectionIntervalS_tr','hide');
			break;

		case true:
			showElementById('who_tr','show');
			showElementById('xif_tr','show');
			showElementById('storage_path_tr','show');
			showElementById('gui_enabled_tr','show');
			showElementById('listenAddress_tr','show');
			showElementById('globalAnnounceServer_tr','show');
			showElementById('globalAnnounceEnabled_tr','show');
			showElementById('localAnnounceEnabled_tr','show');
			showElementById('localAnnouncePort_tr','show');
			showElementById('localAnnounceMCAddr_tr','show');
			showElementById('maxSendKbps_tr','show');
			showElementById('maxRecvKbps_tr','show');
			showElementById('maxChangeKbps_tr','show');
			showElementById('upnpEnabled_tr','show');
			showElementById('upnpLeaseMinutes_tr','show');
			showElementById('upnpRenewalMinutes_tr','show');
			showElementById('urAccepted_tr','show');
			showElementById('restartOnWakeup_tr','show');
			showElementById('keepTemporariesH_tr','show');
			showElementById('cacheIgnoredFiles_tr','show');
			showElementById('parallelRequests_tr','show');
			showElementById('rescanIntervalS_tr','show');
			showElementById('reconnectionIntervalS_tr','show');
			break;
	}
}
//-->
</script>
<form action="syncthing.php" method="post" name="iform" id="iform">
<?php bindtextdomain("nas4free", "/usr/local/share/locale-stg"); ?>
    <table width="100%" border="0" cellpadding="0" cellspacing="0">
	<tr><td class="tabnavtbl">
		<ul id="tabnav">
			<li class="tabact"><a href="syncthing.php"><span><?=gettext("Configuration");?></span></a></li>
			<li class="tabinact"><a href="syncthing_update.php"><span><?=gettext("Maintenance");?></span></a></li>
			<li class="tabinact"><a href="syncthing_update_extension.php"><span><?=gettext("Extension Maintenance");?></span></a></li>
			<li class="tabinact"><a href="syncthing_log.php"><span><?=gettext("Log");?></span></a></li>
		</ul>
	</td></tr>
    <tr><td class="tabcont">
        <?php if (!empty($input_errors)) print_input_errors($input_errors);?>
        <?php if (!empty($savemsg)) print_info_box($savemsg);?>
        <table width="100%" border="0" cellpadding="6" cellspacing="0">
			<?php html_titleline($config['syncthing']['appname']." ".gettext("Information"));?>
			<?php html_text("version", gettext("Version"), $config['syncthing']['product_version']);?>
			<?php html_text("architecture", gettext("Architecture"), $config['syncthing']['architecture']);?>		
            <tr>
                <td class="vncell"><?=gettext("Status");?></td>
                <td class="vtable"><span name="procinfo" id="procinfo"></span></td>
            </tr>
            <?php
                $if = get_ifname($pconfig['if']);
                $ipaddr = get_ipaddr($if);
                $url = htmlspecialchars("http://{$ipaddr}:{$pconfig['port']}");
                $text = "<a href='{$url}' target='_blank'>{$url}</a>";
                html_text("url", gettext("WebUI")." ".gettext("URL"), $text);
            ?>
			<?php html_separator();?>
        	<?php html_titleline_checkbox("enable", $config['syncthing']['appname'], !empty($pconfig['enable']) ? true : false, gettext("Enable"), "enable_change(false)");?>
			<tr>
				<td valign="top" class="vncellreq"><?=gettext("Interface selection");?></td>
				<td class="vtable">
				<select name="if" class="formfld" id="xif">
					<?php foreach($a_interface as $if => $ifinfo):?>
						<?php $ifinfo = get_interface_info($if); if (("up" == $ifinfo['status']) || ("associated" == $ifinfo['status'])):?>
						<option value="<?=$if;?>"<?php if ($if == $pconfig['if']) echo "selected=\"selected\"";?>><?=$if?></option>
						<?php endif;?>
					<?php endforeach;?>
				</select>
				<br /><?=gettext("Select which interface to use (only selectable if your server has more than one).");?>
				</td>
			</tr>
			<?php html_inputbox("port", gettext("WebUI")." ".gettext("Port"), $pconfig['port'], sprintf(gettext("Port to listen on. Only dynamic or private ports can be used (from %d through %d). Default port is %d."), 1025, 65535, 9999), true, 5);?>
            <?php html_checkbox("listen_to_all", gettext("External access"), !empty($pconfig['listen_to_all']) ? true : false, gettext("Enable / disable external (Internet) access. If enabled the WebUI listens to all IP addresses (0.0.0.0) instead of the chosen interface IP address."), gettext("Default is disabled."), true);?>
            <?php html_checkbox("gui_tls", gettext("Secure connection"), ($pconfig['gui_tls'] == "true" ? true : false), gettext("If enabled, Hypertext Transfer Protocol Secure (HTTPS) will be used for the Syncthing WebUI."), gettext("Default is enabled."), true);?>
<!-- 
            <?php html_inputbox("username", gettext("WebUI")." ".gettext("Username"), $pconfig['username'], gettext("Username for the Syncthing WebUI."), false, 20);?>
            <?php html_passwordbox("password", gettext("WebUI")." ".gettext("Password"), $pconfig['password'], gettext("Password for the Syncthing WebUI."), false, 20);?>
 -->
            <?php html_inputbox("autoUpgradeIntervalH", gettext("Automatic upgrades"), $pconfig['autoUpgradeIntervalH'], sprintf(gettext("The number of hours to wait between each check for application upgrades (-1 = disabled, no automatic upgrades). Default is %d hours."), 12), false, 5);?>
            <?php html_checkbox("resetuser", gettext("Reset WebUI user"), false, "<b><font color='#FF0000'>".gettext("Reset (delete) username and password. Use the Syncthing WebUI to define a new username and password.")."</font></b>", "", false);?>
			<?php html_separator();?>
        	<?php html_titleline_checkbox("as_enable", gettext("Advanced settings"), isset($_POST['as_enable']) ? true : false, gettext("Show"), "as_change()");?>
    		<?php $a_user = array(); foreach (system_get_user_list() as $userk => $userv) { $a_user[$userk] = htmlspecialchars($userk); }?>
            <?php html_combobox("who", gettext("Username"), $pconfig['who'], $a_user, gettext("Specifies the username which the service will run as."), false);?>
			<?php html_filechooser("storage_path", gettext("Storage path"), $pconfig['storage_path'], gettext("Where to save auxilliary app files."), $g['media_path'], false, 60);?>
            <?php html_checkbox("gui_enabled", "gui_enabled", ($pconfig['gui_enabled'] == "true" ? true : false), gettext("Defines if the Syncthing WebUI can be used."), gettext("Default is enabled."), false);?>
            <?php html_inputbox("listenAddress", "listenAddress", $pconfig['listenAddress'], sprintf(gettext("host:port or :port string denoting an address to listen for BEP (sync protocol) connections. Default is %s."), "0.0.0.0:22000"), false, 25);?>
            <?php html_inputbox("globalAnnounceServer", "globalAnnounceServer", $pconfig['globalAnnounceServer'], sprintf(gettext("host:port where a global announce server may be reached. Default is %s."), "announce.syncthing.net:22025"), false, 60);?>
            <?php html_checkbox("globalAnnounceEnabled", "globalAnnounceEnabled", ($pconfig['globalAnnounceEnabled'] == "true" ? true : false), gettext("globalAnnounceEnabled."), gettext("Default is enabled."), false);?>
            <?php html_checkbox("localAnnounceEnabled", "localAnnounceEnabled", ($pconfig['localAnnounceEnabled'] == "true" ? true : false), gettext("localAnnounceEnabled."), gettext("Default is enabled."), false);?>
            <?php html_inputbox("localAnnouncePort", "localAnnouncePort", $pconfig['localAnnouncePort'], sprintf(gettext("localAnnouncePort. Default is %d."), 21025), false, 5);?>
            <?php html_inputbox("localAnnounceMCAddr", "localAnnounceMCAddr", $pconfig['localAnnounceMCAddr'], sprintf(gettext("localAnnounceMCAddr. Default is %s."), "[ff32::5222]:21026"), false, 25);?>
            <?php html_inputbox("maxRecvKbps", "maxRecvKbps", $pconfig['maxRecvKbps'], sprintf(gettext("Incoming rate limit. Default is %d kbps (unlimited)."), 0), false, 5);?>
            <?php html_inputbox("maxSendKbps", "maxSendKbps", $pconfig['maxSendKbps'], sprintf(gettext("Outgoing rate limit. Default is %d kbps (unlimited)."), 0), false, 5);?>
            <?php html_inputbox("maxChangeKbps", "maxChangeKbps", $pconfig['maxChangeKbps'], sprintf(gettext("The maximum rate of change allowed for a single file. When this rate is exceeded, further changes to the file are not announced, until the rate is reduced below the limit. Default is %d kbps."), 10000), false, 5);?>
            <?php html_checkbox("upnpEnabled", "upnpEnabled", ($pconfig['upnpEnabled'] == "true" ? true : false), gettext("upnpEnabled."), gettext("Default is enabled."), false);?>
            <?php html_inputbox("upnpLeaseMinutes", "upnpLeaseMinutes", $pconfig['upnpLeaseMinutes'], sprintf(gettext("upnpLeaseMinutes. Default is %d minutes."), 0), false, 5);?>
            <?php html_inputbox("upnpRenewalMinutes", "upnpRenewalMinutes", $pconfig['upnpRenewalMinutes'], sprintf(gettext("upnpRenewalMinutes. Default is %d minutes."), 30), false, 5);?>
            <?php html_inputbox("urAccepted", "urAccepted", $pconfig['urAccepted'], sprintf(gettext("Whether the user has accepted to submit anonymous usage data. The default, 0, mean the user has not made a choice, and syncthing will ask at some point in the future. -1 means no, 1 means yes. Default is %d."), 0), false, 5);?>
            <?php html_checkbox("restartOnWakeup", "restartOnWakeup", ($pconfig['restartOnWakeup'] == "true" ? true : false), gettext("restartOnWakeup."), gettext("Default is disabled."), false);?>
            <?php html_inputbox("keepTemporariesH", "keepTemporariesH", $pconfig['keepTemporariesH'], sprintf(gettext("keepTemporariesH. Default is %d hours."), 24), false, 5);?>
            <?php html_checkbox("cacheIgnoredFiles", "cacheIgnoredFiles", ($pconfig['cacheIgnoredFiles'] == "true" ? true : false), gettext("cacheIgnoredFiles."), gettext("Default is enabled."), false);?>
            <?php html_inputbox("parallelRequests", "parallelRequests", $pconfig['parallelRequests'], sprintf(gettext("The maximum number of outstanding block requests to have against any given peer. Default is %d."), 16), false, 5);?>
            <?php html_inputbox("rescanIntervalS", "rescanIntervalS", $pconfig['rescanIntervalS'], sprintf(gettext("The number of seconds to wait between each scan for modification of the local repositories. Default is %d seconds."), 60), false, 5);?>
            <?php html_inputbox("reconnectionIntervalS", "reconnectionIntervalS", $pconfig['reconnectionIntervalS'], sprintf(gettext("The number of seconds to wait between each attempt to connect to currently unconnected nodes. Default is %d seconds."), 60), false, 5);?>
        </table>
        <div id="remarks">
            <?php html_remark("note", gettext("Note"), sprintf(gettext("These parameters will be added to %s."), "{$config['syncthing']['storage_path']}config.xml")." ".sprintf(gettext("Please check the <a href='%s' target='_blank'>documentation</a>."), "https://discourse.syncthing.net/c/documentation"));?>
        </div>
        <div id="submit">
			<input id="save" name="save" type="submit" class="formbtn" value="<?=gettext("Save and Restart");?>"/>
        </div>
	</td></tr>
	</table>
	<?php include("formend.inc");?>
</form>
<script type="text/javascript">
<!--
enable_change(false);
as_change();
//-->
</script>
<?php include("fend.inc");?>
