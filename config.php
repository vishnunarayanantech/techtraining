<?php
unset($CFG);
global $CFG, $USER, $DB;
$CFG = new stdClass();

$CFG->dbtype    = 'mysqli';
$CFG->dblibrary = 'native';
$CFG->dbhost    = 'localhost';
$CFG->dbname    = 'techversant';
$CFG->dbuser    = 'vtmodl_user';
$CFG->dbpass    = '3O99pkUY8@oaB';
$CFG->prefix    = 'mdl_';
$CFG->dboptions = array(
    'dbpersist' => 0,
    'dbport' => '',
    'dbsocket' => '',
    'dbcollation' => 'utf8mb4_unicode_ci',
);

$CFG->wwwroot   = 'https://tech-training.demoserver.work';
$CFG->dataroot  = '/home/superuser/development_hosting/php/moodle/techdata';
$CFG->admin     = 'admin';
$CFG->sslproxy  = true;

$CFG->directorypermissions = 0777;
$CFG->disablelogintoken = true;

$CFG->session_handler_class = '\core\session\file';
$CFG->session_file_save_path = $CFG->dataroot . '/sessions';

require_once(__DIR__ . '/lib/setup.php');
