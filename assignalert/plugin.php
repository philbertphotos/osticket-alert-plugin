<?php
 
/**
 * Description of plugin
 * @author Joseph Philbert <joe@philbertphotos.com>
 * @license http://opensource.org/licenses/MIT
 * @version 1.4
 */
 
set_include_path(get_include_path().PATH_SEPARATOR.dirname(__file__).'/include');

return array(
    'id' => 'pps:assign_alert',
    'version' => '1.5',
	'ost_version' =>    '1.15', # Require osTicket v1.17+
    'name' => 'PPS Assign Alert',
    'author' => 'Joseph Philbert',
    'description' => 'Send an alert when a ticket is assigned to managers in a department.',
    'url' => 'https://github.com/philbertphotos',
    'plugin' => 'assignalert.php:AssignAlertPlugin'
);
