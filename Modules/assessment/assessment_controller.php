<?php

// no direct access
defined('EMONCMS_EXEC') or die('Restricted access');

function assessment_controller() {
    global $session, $route, $mysqli;

    /* --------------------------------------------------------------------------
      // Backwards compatibility:
      // During development, i am finding many situations when we need to do something
      // make what is implemented compatible with the previous version. This section
      // is here fot his pourpose and will be deleted when the final realease is made
      //---------------------------------------------------------------------------- */

    // Rename standard libraries to include users name (important to identify libraries after sharing
    $libresult = $mysqli->query("SELECT id,name,userid FROM element_library");
    foreach ($libresult as $row) {
        if ($row['name'] === "StandardLibrary") {
            $user_name = $mysqli->query("SELECT username FROM users WHERE id = " . $row['userid']);
            $user_name = $user_name->fetch_object();
            $user_name = $user_name->username;
            $req = $mysqli->prepare('UPDATE element_library SET `name` = ? WHERE `id` = ?  ');
            $new_name = "StandardLibrary - " . $user_name;
            $req->bind_param("si", $new_name, $row['id']);
            $req->execute();
        }
    }

    // Add "type" to element library table in database (if it doesn't exist), and for all the libraries set the type to elements (this were the original type so we can be sure that at the moment of adding the column all the libraries are type "element"
    $columns_in_table = $mysqli->query("DESCRIBE element_library");
    $field_found = false;
    foreach ($columns_in_table as $column) {
        if ($column['Field'] === 'type')
            $field_found = true;
    }
    if ($field_found === false)
        $mysqli->query("ALTER TABLE  `element_library` ADD  `type` VARCHAR( 255 ) NOT NULL DEFAULT  'elements' AFTER  `name`");

    // Grant all the users write permissions for their own library
    $libresult = $mysqli->query("SELECT * FROM `element_library`");
    foreach($libresult as $row){
        $req=$mysqli->prepare("UPDATE `element_library_access` SET `write`='1' WHERE `userid`=? AND `id`=?");
        $req->bind_param('ii',$row['userid'],$row['id']);
        $req->execute();
    }
    
    
    /* End backwards compatibility section */


    // -------------------------------------------------------------------------
    // Check if session has been authenticated, if not redirect to login page (html) 
    // or send back "false" (json)
    // -------------------------------------------------------------------------
    if (!$session['read']) {
        if ($route->format == 'html')
            $result = view("Modules/user/login_block.php", array());
        else
            $result = "Not logged";
        return array('content' => $result);
    }

    // -------------------------------------------------------------------------    
    // Session is authenticated so we run the action
    // -------------------------------------------------------------------------
    $result = false;
    if ($route->format == 'html') {
        if ($route->action == "view" && $session['write'])
            $result = view("Modules/assessment/view.php", array());
        if ($route->action == "print" && $session['write'])
            $result = view("Modules/assessment/print.php", array());

        if ($route->action == "list" && $session['write'])
            $result = view("Modules/assessment/projects.php", array());
    }

    if ($route->format == 'json') {

        require "Modules/assessment/assessment_model.php";
        $assessment = new Assessment($mysqli);

        require "Modules/assessment/organisation_model.php";
        $organisation = new Organisation($mysqli);

        // -------------------------------------------------------------------------------------------------------------
        // Create assessment
        // -------------------------------------------------------------------------------------------------------------
        if ($route->action == 'create' && $session['write']) {
            $result = $assessment->create($session['userid'], get('name'), get('description'));

            if (isset($_GET['org'])) {
                $orgid = (int) $_GET['org'];
                $assessment->org_access($result['id'], $orgid, 1);
            }
        }

        // -------------------------------------------------------------------------------------------------------------
        // List assessments
        // -------------------------------------------------------------------------------------------------------------
        if ($route->action == 'list' && $session['write']) {
            if (isset($_GET['orgid'])) {
                $orgid = $_GET['orgid'];
                $result = $assessment->get_org_list($orgid);
            } else {
                $result = $assessment->get_list($session['userid']);
            }
        }

        if ($route->action == 'delete' && $session['write'])
            $result = $assessment->delete($session['userid'], get('id'));
        if ($route->action == 'share' && $session['write'])
            $result = $assessment->share($session['userid'], get('id'), get('username'));
        if ($route->action == 'getshared' && $session['write'])
            $result = $assessment->getshared($session['userid'], get('id'));
        if ($route->action == 'get' && $session['write'])
            $result = $assessment->get($session['userid'], get('id'));

        if ($route->action == 'setstatus' && $session['write']) {
            if (isset($_GET['status'])) {
                $status = $_GET['status'];
                $result = $assessment->set_status($session['userid'], get('id'), $status);
            }
        }

        if ($route->action == 'setdata' && $session['write']) {
            $data = null;
            if (isset($_POST['data']))
                $data = $_POST['data'];
            if (!isset($_POST['data']) && isset($_GET['data']))
                $data = $_GET['data'];
            if ($data && $data != null)
                $result = $assessment->set_data($session['userid'], post('id'), $data);
        }

        if ($route->action == 'setnameanddescription' && $session['write']) {
            $name = null;
            if (isset($_POST['name']))
                $name = $_POST['name'];
            if (!isset($_POST['name']) && isset($_GET['name']))
                $name = $_GET['name'];

            $description = null;
            if (isset($_POST['description']))
                $description = $_POST['description'];
            if (!isset($_POST['description']) && isset($_GET['description']))
                $description = $_GET['description'];

            if ($name && $name != null && $description && $description != null)
                $result = $assessment->set_name_and_description($session['userid'], post('id'), $name, $description);
        }

        // -------------------------------------------------------------------------------------------------------------
        // Organisation
        // -------------------------------------------------------------------------------------------------------------
        if ($route->action == 'neworganisation' && $session['write'] && isset($_GET['orgname'])) {
            $orgname = $_GET['orgname'];
            $orgid = $organisation->create($orgname, $session['userid']);
            if ($orgid) {
                $result = array("success" => true, "myorganisations" => $organisation->get_organisations($session['userid']));
            } else {
                $result = array("success" => false, "message" => 'Organisation "' . $orgname . '" already exists!');
            }
        }

        if ($route->action == 'getorganisations' && $session['write']) {
            $result = $organisation->get_organisations($session['userid']);
        }

        if ($route->action == "organisationaddmember" && $session['write']) {
            $orgid = (int) $_GET['orgid'];
            $username = $_GET['membername'];
            global $user;
            if ($userid = $user->get_id($username)) {
                if ($organisation->add_member($orgid, $userid)) {
                    $result = array("success" => true, 'userid' => $userid, 'name' => $username, 'lastactive' => "?");
                } else {
                    $result = array("success" => false, "message" => 'Sorry, user "' . $username . '" is already a member');
                }
            } else {
                $result = array("success" => false, "message" => 'Sorry, user "' . $username . '" does not exist!?');
            }
        }

        if ($route->action == 'listlibrary' && $session['write'])
            $result = $assessment->listlibrary($session['userid']);
        if ($route->action == 'newlibrary' && $session['write'])
            $result = $assessment->newlibrary($session['userid'], get('name'));

        // Save library
        if ($route->action == 'savelibrary' && $session['write'] && isset($_POST['data'])) {
            $result = $assessment->savelibrary($session['userid'], post('id'), $_POST['data']);
        }

        if ($route->action == 'loadlibrary' && $session['write'])
            $result = $assessment->loadlibrary($session['userid'], get('id'));

        if ($route->action == 'sharelibrary' && $session['write'])
            $result = $assessment->sharelibrary($session['userid'], get('id'), get('name'), get('write_permissions'));

        if ($route->action == 'getsharedlibrary' && $session['write'])
            $result = $assessment->getsharedlibrary($session['userid'], get('id'));

        // -------------------------------------------------------------------------------------------------------------
        // Image gallery
        // -------------------------------------------------------------------------------------------------------------
        if ($route->action == 'uploadimages' && $session['write'])
            $result = $assessment->saveimages($session['userid'], post('id'), $_FILES);

        if ($route->action == 'deleteimage' && $session['write'])
            $result = $assessment->deleteimage($session['userid'], post('id'), post('filename'));


        // Upgrade (temporary)    
        /*
          if ($route->action == "upgrade" && $session['admin'])
          {
          $result = $mysqli->query("SELECT id, userid, author FROM assessment");
          $out = array();
          while ($row = $result->fetch_object()) {
          $out[] = $row;
          $assessment->access($row->id,$row->userid,1);
          }
          $result = $out;
          }
         */
    }

    return array('content' => $result, 'fullwidth' => true);
}
