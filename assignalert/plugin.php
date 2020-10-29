<?php
 
/**
 * Description of plugin
 * @author Joseph Philbert <joe@philbertphotos.com>
 * @license http://opensource.org/licenses/MIT
 * @version 0.1
 */
 
set_include_path(get_include_path().PATH_SEPARATOR.dirname(__file__).'/include');

return array(
    'id' => 'pps:assign_alert',
    'version' => '1.3 beta',
    'name' => 'PPS Assign Alert',
    'author' => 'Joseph Philbert',
    'description' => 'Send an alert when a ticket is assigned to managers in a department.',
    'url' => 'https://github.com/philbertphotos',
    'plugin' => 'assignalert.php:AssignAlertPlugin'
);
