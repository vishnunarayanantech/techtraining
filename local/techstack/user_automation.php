<?php
require('../../config.php');
require_once($CFG->libdir . '/dml/moodle_database.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/enrol/manual/lib.php');
 
global $DB;
 
//API CALL FUNCTION
function call_api($url, $method = 'GET', $body = null, $token = null) {
    $curl = curl_init($url);
 
    $headers = ['Content-Type: application/json'];
    if ($token) {
        $headers[] = "Authorization: Bearer $token";
    }
 
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER    => $headers
    ]);
 
    if ($body) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
    }
 
    $response = curl_exec($curl);
    curl_close($curl);
 
    return json_decode($response, true);
}
 
//1) GET ACCESS TOKEN 
$tokenResponse = call_api(
    "https://inventoryqa.techversantinfotech.com/api/v1/allUsers/generateAccessToken",
    "POST",
    [
        "clientId" => "techv-internal-inventory-sandbox",
        "apiKey"   => "70241e75-9967-4703-a34d-6e7a4bbcb884"
    ]
);
 
$token = $tokenResponse['FILECONTENT']['JWTTOKEN'] ?? null;
if (!$token) {
    die('Token generation failed');
}
 
//2) FETCH USERS FROM API 
$userResponse = call_api(
    "https://inventoryqa.techversantinfotech.com/api/v1/allUsers/usersDetails",
    "GET",
    null,
    $token
);
 
$apiUsers = $userResponse['FILECONTENT']['USERS'] ?? [];
if (empty($apiUsers)) {
    die('No users received from API');
}
 
//3) PROCESS USERS 
$manualenrol = enrol_get_plugin('manual');

foreach ($apiUsers as $user) {
 
    //Basic API Fields  
    $empid      = $user['Employee ID'];
    $fullname   = trim($user['Employee Name']);
    $email      = trim($user['Work Email']);
    $department = trim($user['Department']);
    $location   = trim($user['Location']);
 
    if (empty($email) || empty($empid)) {
        continue;
    }
 
    //Split name
    $nameparts = explode(' ', $fullname, 2);
    $firstname = $nameparts[0];
    $lastname  = $nameparts[1] ?? '';
 
    //Check user by email
    $existinguser = $DB->get_record('user', ['email' => $email, 'deleted' => 0]);
 
    if (!$existinguser) {
    
        //Create user       
        $newuser = (object)[
            'username'   => $empid,
            'password'   => hash_internal_user_password($empid . '@123'),
            'firstname'  => $firstname,
            'lastname'   => $lastname,
            'email'      => $email,
            'auth'       => 'manual',
            'city'       => $location,
            'country'    => 'IN',
            'confirmed'  => 1,
            'mnethostid' => $CFG->mnet_localhost_id
        ];
 
        $userid = user_create_user($newuser);
        echo "User created: {$email}<br>";

        //4) TECH CATEGORY MATCH
        $tech = $DB->get_record('techstack', ['tech_category' => $department], '*', IGNORE_MULTIPLE);
    
        if (!$tech) {
            echo "No tech category found for: {$department}<br><hr>";
            continue;
        }
    
        //5) GET MAPPED COURSES 
        $mappedcourses = $DB->get_records('mapcourses', ['cat_id' => $tech->id]);
    
        if (empty($mappedcourses)) {
            echo "No mapped courses for tech category: {$department}<br><hr>";
            continue;
        }
    
        //6) ENROL USER 
        foreach ($mappedcourses as $map) {
    
            $courseid = $map->mapped_courses;
    
            $course = $DB->get_record('course', ['id' => $courseid], '*', IGNORE_MISSING);
            if (!$course) {
                continue;
            }
    
            $instances = enrol_get_instances($course->id, true);
    
            foreach ($instances as $instance) {
                if ($instance->enrol === 'manual') {
                    $manualenrol->enrol_user($instance, $userid, 5);
                    echo "Enrolled {$email} → {$course->fullname}<br>";
                    break;
                }
            }
        }
    
        echo "<hr>";
 
    } else {
    
        //Update user 
        $existinguser->firstname = $firstname;
        $existinguser->lastname  = $lastname;
        $existinguser->email     = $email;
 
        user_update_user($existinguser);
        $userid = $existinguser->id;
 
        echo "User updated: {$email}<br>";
    }
}
 
echo "Process completed successfully.";