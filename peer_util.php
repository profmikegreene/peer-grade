<?php

use \Tsugi\Core\Cache;
use \Tsugi\UI\Output;
use \Tsugi\Util\LTI;
use \Tsugi\Core\LTIX;
use \Tsugi\Core\User;
use \Tsugi\Core\Mail;
use \Tsugi\Blob\BlobUtil;
use \Tsugi\UI\Lessons;

// Loads the assignment associated with this link
function loadAssignment()
{
    global $CFG, $PDOX, $LINK;
    $cacheloc = 'peer_assn';
    $row = Cache::check($cacheloc, $LINK->id);
    if ( $row != false && $row['json'] != 'null' ) return $row;
    $stmt = $PDOX->queryDie(
        "SELECT assn_id, json FROM {$CFG->dbprefix}peer_assn WHERE link_id = :ID",
        array(":ID" => $LINK->id)
    );
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $custom = LTIX::ltiCustomGet('config');
    // Check custom for errors and make it pretty
    if ( strlen($custom) > 1 ) {
        $decode = json_decode($custom);
        if ( $decode === null ) {
            error_log('Bad custom_config\n'.$custom);
            $custom = null;
        } else {
            $pretty = json_encode($decode,JSON_PRETTY_PRINT);
            $custom = $pretty;
        }
    }

    if ( ( ! $custom || strlen($custom) < 1 ) && isset($_GET["inherit"]) && isset($CFG->lessons) ) {
        $l = new Lessons($CFG->lessons);
        if ( $l ) {
            $lti = $l->getLtiByRlid($_GET['inherit']);
            if ( isset($lti->custom) ) foreach($lti->custom as $c ) {
                if (isset($c->key) && isset($c->json) && $c->key == 'config' ) {
                    $custom = json_encode($c->json, JSON_PRETTY_PRINT);
                }
            }
        }
    }
    if ( $row === false && strlen($custom) > 1 ) {
        $stmt = $PDOX->queryReturnError(
            "INSERT INTO {$CFG->dbprefix}peer_assn
                (link_id, json, created_at, updated_at)
                VALUES ( :ID, :JSON, NOW(), NOW())
                ON DUPLICATE KEY UPDATE json = :JSON, updated_at = NOW()",
            array(
                ':JSON' => $custom,
                ':ID' => $LINK->id)
            );
        Cache::clear("peer_assn");
        $stmt = $PDOX->queryDie(
            "SELECT assn_id, json FROM {$CFG->dbprefix}peer_assn WHERE link_id = :ID",
            array(":ID" => $LINK->id)
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    $row['json'] = upgradeSubmission($row['json'] );
    Cache::set($cacheloc, $LINK->id, $row);
    return $row;
}

function loadSubmission($assn_id, $user_id)
{
    global $CFG, $PDOX;
    $cacheloc = 'peer_submit';
    $cachekey = $assn_id . "::" . $user_id;
    $submit_row = Cache::check($cacheloc, $cachekey);
    if ( $submit_row != false ) return $submit_row;
    $submit_row = false;

    $stmt = $PDOX->queryDie(
        "SELECT submit_id, json, note, reflect, inst_points, inst_note, inst_id, updated_at
            FROM {$CFG->dbprefix}peer_submit AS S
            WHERE assn_id = :AID AND S.user_id = :UID",
        array(":AID" => $assn_id, ":UID" => $user_id)
    );
    $submit_row = $stmt->fetch(PDO::FETCH_ASSOC);
    Cache::set($cacheloc, $cachekey, $submit_row);
    return $submit_row;
}

// Upgrade a submission to cope with name changes
function upgradeSubmission($json_str)
{
    if ( strlen(trim($json_str)) < 1 ) return $json_str;
    $json = json_decode($json_str);
    if ( $json === null ) return $json_str;

    // Add instructorpoints if they are not there
    if ( ! isset($json->instructorpoints) ) $json->instructorpoints = 0;

    // Convert maxpoints to peerpoints
    if ( ( ! isset($json->peerpoints) ) && isset($json->maxpoints) ) $json->peerpoints = $json->maxpoints;
    unset($json->maxpoints);

    // Allow for things to be optional
    if ( ! isset($json->totalpoints) ) $json->totalpoints = 0; // Probably an error
    if ( ! isset($json->assesspoints) ) $json->assesspoints = 0;
    if ( ! isset($json->maxassess) ) $json->maxassess = 0;
    if ( ! isset($json->minassess) ) $json->minassess = 0;
    if ( ! isset($json->peerpoints) ) $json->peerpoints = 0;
    if ( ! isset($json->flag) ) $json->flag = true;
    if ( ! isset($json->rating) ) $json->rating = 0;
    if ( ! isset($json->gallery) ) $json->gallery = "off";
    if ( ! isset($json->galleryformat) ) $json->galleryformat = "card";
    if ( ! isset($json->resubmit) ) $json->resubmit = "off";
    if ( ! isset($json->autopeer) ) $json->autopeer = 0;
    if ( $json->autopeer === false ) $json->autopeer = 0;
    if ( ! isset($json->notepublic) ) $json->notepublic = "false";
    return json_encode($json);
}

// Check for ungraded submissions
function loadUngraded($assn_id)
{
    global $CFG, $PDOX, $USER;
    $stmt = $PDOX->queryDie(
        "SELECT S.submit_id, S.user_id, S.created_at, count(G.user_id) AS submit_count
            FROM {$CFG->dbprefix}peer_submit AS S LEFT JOIN {$CFG->dbprefix}peer_grade AS G
            ON S.submit_id = G.submit_id
            WHERE S.assn_id = :AID AND S.user_id != :UID AND
            S.submit_id NOT IN
                ( SELECT DISTINCT submit_id from {$CFG->dbprefix}peer_grade WHERE user_id = :UID)
            GROUP BY S.submit_id, S.created_at
            ORDER BY submit_count ASC, S.created_at ASC
            LIMIT 10",
        array(":AID" => $assn_id, ":UID" => $USER->id)
    );
    return $stmt->fetchAll();
}

function showSubmission($assn_json, $submit_json, $assn_id, $user_id)
{
    global $CFG, $PDOX, $USER, $LINK, $CONTEXT, $OUTPUT;
    echo('<div style="padding:5px">');
    $blob_ids = $submit_json->blob_ids;
    $urls = isset($submit_json->urls) ? $submit_json->urls : array();
    $codes = isset($submit_json->codes) ? $submit_json->codes : array();
    $content_items = isset($submit_json->content_items) ? $submit_json->content_items : array();
    $blobno = 0;
    $urlno = 0;
    $codeno = 0;
    $content_item_no = 0;
    foreach ( $assn_json->parts as $part ) {
        if ( $part->type == "image" ) {
            // This test triggers when an assignment is reconfigured
            // and old submissions have too few blobs
            if ( $blobno >= count($blob_ids) ) continue;
            $blob_id = $blob_ids[$blobno++];
            if ( is_array($blob_id) ) $blob_id = $blob_id[0];
            $url = BlobUtil::getAccessUrlForBlob($blob_id);
            $title = 'Student image';
            if( isset($part->title) && strlen($part->title) > 0 ) $title = $part->title;
            echo (' <a href="#" onclick="$(\'#myModal_'.$blob_id.'\').modal();"');
            echo ('alt="'.htmlent_utf8($title).'" title="'.htmlent_utf8($title).'">');
            echo ('<img src="'.addSession($url).'" width="240" style="max-width: 100%"></a>'."\n");
?>
<div class="modal fade" id="myModal_<?php echo($blob_id); ?>">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
        <h4 class="modal-title"><?php echo(htmlent_utf8($title)); ?></h4>
      </div>
      <div class="modal-body">
        <img src="<?php echo(addSession($url)); ?>" style="width:100%">
      </div>
    </div><!-- /.modal-content -->
  </div><!-- /.modal-dialog -->
</div><!-- /.modal -->
<?php
        } else if ( $part->type == "url" && $urlno < count($urls) ) {
            $url = $urls[$urlno++];
            echo ('<p><a href="'.safe_href($url).'" target="_blank">');
            echo (htmlentities(safe_href($url)).'</a> (Will launch in new window)</p>'."\n");
        } else if ( $part->type == "content_item" && $content_item_no < count($content_items) ) {
            $content_item = $content_items[$content_item_no++];

            $endpoint = $content_item->url;
            $info = LTIX::getKeySecretForLaunch($endpoint);
            if ( $info === false ) {
                echo('<p style="color:red">Unable to load key/secret for '.htmlentities($endpoint)."</p>\n");
                $content_item_no++;
                continue;
            }

            $lu1 = LTIX::getLaunchUrl($endpoint, true);
            $lu1 = addSession($lu1);

             echo('<br/><button type="button" onclick="
                $(\'#content_item_frame_'.$content_item_no.'\').attr(\'src\', \''.$lu1.'\');
                showModalIframe(\''.$part->title.'\', 
                \'content_item_dialog_'.$content_item_no.'\',\'content_item_frame_'.$content_item_no.'\', 
                \''.$OUTPUT->getSpinnerUrl().'\'); 
                return false;">View Media</button>'."\n");
?>
<div id="content_item_dialog_<?= $content_item_no ?>" title="Content Item Dialog" style="display:none;">
<iframe src="about:blank" id="content_item_frame_<?= $content_item_no ?>" 
    style="width:95%; height:500px;"
    scrolling="auto" frameborder="1" transparency></iframe>
</div>
<?php
            $content_item_no++;
        } else if ( $part->type == "code" && $codeno < count($codes) ) {
            $code_id = $codes[$codeno++];
            $row = $PDOX->rowDie("
                SELECT data FROM {$CFG->dbprefix}peer_text 
                WHERE text_id = :TID AND user_id = :UID AND assn_id = :AID",
                array( ":TID" => $code_id,
                    ":AID" => $assn_id,
                    ":UID" => $user_id)
            );
            if ( $row === FALSE || strlen($row['data']) < 1 ) {
                echo("<p>No Code Found</p>\n");
            } else {
                echo ('<p>Code: <a href="#" onclick="$(\'#myModal_code_'.$codeno.'\').modal();">');
                echo(htmlent_utf8($part->title)."</a> (click to view)</p>\n");
?>
<div class="modal fade" id="myModal_code_<?php echo($codeno); ?>">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
        <h4 class="modal-title"><?php echo(htmlent_utf8($part->title)); ?></h4>
      </div>
      <div class="modal-body">
<!-- Don't indent or inadvertently add a newline once the pre starts -->
<pre class="line-numbers"><code 
<?php if ( isset($part->language) ) { ?>
class="language-<?php echo($part->language); ?>"
<?php } ?>
><?php echo (htmlentities($row['data'])); ?>
</code>
</pre>
      </div>
    </div><!-- /.modal-content -->
  </div><!-- /.modal-dialog -->
</div><!-- /.modal -->
<?php
            }
        }

    }
    echo("<br/>&nbsp;<br/>\n");

    if ( $blobno > 0 ) {
        echo("<p>Click on each image to see a larger view of the image.</p>\n");
    }

    if ( strlen($submit_json->notes) > 1 ) {
        echo("<p>Notes: ".htmlent_utf8($submit_json->notes)."</p>\n");
    }
    echo('<div style="padding:3px">');
}

function computeGrade($assn_id, $assn_json, $user_id)
{
    global $CFG, $PDOX;

    if ( $assn_json->totalpoints == 0 ) return 0;
        $sql = "SELECT S.assn_id, S.user_id AS user_id, inst_points, email, displayname,
             S.submit_id as submit_id, S.created_at AS created_at,
            MAX(points) as max_points, COUNT(points) as count_points
        FROM {$CFG->dbprefix}peer_submit as S
        JOIN {$CFG->dbprefix}peer_grade AS G
            ON S.submit_id = G.submit_id
        JOIN {$CFG->dbprefix}lti_user AS U
            ON S.user_id = U.user_id
        WHERE S.assn_id = :AID AND S.user_id = :UID";

    $stmt = $PDOX->queryDie($sql,
        array(":AID" => $assn_id, ":UID" => $user_id)
    );
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ( $row === false || $row['user_id']+0 == 0 ) return -1;

    // Compute the overall points
    $inst_points = $row['inst_points'] + 0;
    $assnpoints = $row['max_points']+0;

    // Handle when the student has waited "long enough" for a peer-grade
    $created_at = strtotime($row['created_at']." UTC");
    $diff = time() - $created_at;
    if ( isset($assn_json->autopeer) && $assn_json->autopeer > 0 &&
        $diff > $assn_json->autopeer && $assnpoints < $assn_json->peerpoints) {
	// TODO: Turn this into an event
        error_log('Auto-peer '.time().' '.$diff.' '.$row['displayname']);
        $assnpoints = $assn_json->peerpoints;
    }

    if ( $assnpoints < 0 ) $assnpoints = 0;
    if ( $assnpoints > $assn_json->peerpoints ) $assnpoints = $assn_json->peerpoints;

    $sql = "SELECT count(G.user_id) as grade_count
        FROM {$CFG->dbprefix}peer_submit as S
        JOIN {$CFG->dbprefix}peer_grade AS G
            ON S.submit_id = G.submit_id
        WHERE S.assn_id = :AID AND G.user_id = :UID";

    $stmt = $PDOX->queryDie($sql,
        array(":AID" => $assn_id, ":UID" => $user_id)
    );
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $gradecount = 0;
    if ( $row ) $gradecount = $row['grade_count']+0;
    if ( $gradecount < 0 ) $gradecount = 0;
    if ( $gradecount > $assn_json->minassess ) $gradecount = $assn_json->minassess;
    $gradepoints = $gradecount * $assn_json->assesspoints;
    $retval = ($inst_points + $assnpoints + $gradepoints) / $assn_json->totalpoints;
    if ( $retval > 1.0 ) $retval = 1.0;
    return $retval;
}

// Load the count of grades for this user for an assignment
function loadMyGradeCount($assn_id) {
    global $CFG, $PDOX, $USER;
    $cacheloc = 'peer_grade';
    $cachekey = $assn_id . "::" . $USER->id;
    $grade_count = Cache::check($cacheloc, $cachekey);
    if ( $grade_count != false ) return $grade_count;
    $stmt = $PDOX->queryDie(
        "SELECT COUNT(grade_id) AS grade_count
        FROM {$CFG->dbprefix}peer_submit AS S
        JOIN {$CFG->dbprefix}peer_grade AS G
        ON S.submit_id = G.submit_id
            WHERE S.assn_id = :AID AND G.user_id = :UID",
        array( ':AID' => $assn_id, ':UID' => $USER->id)
    );
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ( $row !== false ) {
        $grade_count = $row['grade_count']+0;
    }
    Cache::set($cacheloc, $cachekey, $grade_count);
    return $grade_count;
}

// Retrieve grades for a submission
// Not cached because another user may have added a grade
// a moment ago
function retrieveSubmissionGrades($submit_id)
{
    global $CFG, $PDOX;
    if ( $submit_id === false ) return false;
    $grades_received = $PDOX->allRowsDie(
        "SELECT grade_id, points, note, displayname, email, rating
        FROM {$CFG->dbprefix}peer_grade AS G
        JOIN {$CFG->dbprefix}lti_user as U
            ON G.user_id = U.user_id
        WHERE G.submit_id = :SID
        ORDER BY points DESC",
        array( ':SID' => $submit_id)
    );
    return $grades_received;
}

function retrieveGradesGiven($assn_id, $user_id)
{
    global $CFG, $PDOX;
    $grades_given = $PDOX->allRowsDie(
        "SELECT grade_id, points, G.note AS note, displayname, email, G.rating AS rating
        FROM {$CFG->dbprefix}peer_grade AS G
        JOIN {$CFG->dbprefix}peer_submit AS S
            ON G.submit_id = S.submit_id
        JOIN {$CFG->dbprefix}lti_user as U
            ON S.user_id = U.user_id
        WHERE G.user_id = :UID AND S.assn_id = :AID",
        array( ':AID' => $assn_id, ':UID' => $user_id)
    );
    return $grades_given;
}

function mailDeleteSubmit($user_id, $assn_json, $note)
{
    global $CFG, $PDOX, $CONTEXT, $LINK, $USER;
    if ( (!isset($CFG->maildomain)) || $CFG->maildomain === false ) return false;

    $LAUNCH = LTIX::requireData();

    $user_row = User::loadUserInfoBypass($user_id);
    if ( $user_row === false ) return false;
    $to = $user_row['email'];
    if ( strlen($to) < 1 || strpos($to,'@') === false ) return false;

    $name = $user_row['displayname'];
    $token = Mail::computeCheck($user_id);
    $subject = 'From '.$CFG->servicename.', Your Peer Graded Entry Has Been Reset';
    $E = "\n";
    if ( isset($CFG->maileol) ) $E = $CFG->maileol;

    $message = "This is an automated message.  Your peer-graded entry has been reset.$E$E";
    if ( isset($CONTEXT->title) ) $message .= 'Course Title: '.$CONTEXT->title.$E;
    if ( isset($LINK->title) ) $message .= 'Assignment: '.$LINK->title.$E;
    if ( isset($USER->displayname) ) $message .= 'Staff member doing reset: '.$USER->displayname.$E;

    $fixnote = trim($note);
    if ( strlen($fixnote) > 0 ) {
        if ( $E != "\n" ) $fixnote = str_replace("\n",$E,$fixnote);
        $message .= "Notes regarding this action:".$E.$fixnote.$E;
    }
    $message .= "{$E}You may now re-submit your peer-graded assignment.$E";

    $stmt = $PDOX->queryDie(
        "INSERT INTO {$CFG->dbprefix}mail_sent
            (context_id, link_id, user_to, user_from, subject, body, created_at)
            VALUES ( :CID, :LID, :UTO, :UFR, :SUB, :BOD, NOW() )",
        array( ":CID" => $CONTEXT->id, ":LID" => $LINK->id,
            ":UTO" => $user_id, ":UFR" => $USER->id,
            ":SUB" => $subject, ":BOD" => $message)
    );

    // echo $to, $subject, $message, $user_id, $token;
    $retval = Mail::send($to, $subject, $message, $user_id, $token);
    return $retval;
}

function getDefaultJson() 
{
    $json = '{ "title" : "Assignment title",
        "description" : "This is a sample assignment configuration showing the various kinds of items you can ask for in the assignment.",
        "grading" : "This assignment is worth 10 points. 6 points come from your peers and 4 points come from you grading other student\'s submissions. Don\'t take off points for little mistakes.  If they seem to have done the assignment give them full credit.   Feel free to make suggestions if there are small mistakes.  Please keep your comments positive and useful.  If you do not take grading seriously, the instructors may delete your response and you will lose points.",
        "parts" : [
            { "title" : "URL of your home page",
              "type" : "url"
            },
            { "title" : "Source code of index.php with your name",
              "type" : "code",
              "language" : "php"
            },
            { "title" : "Some HTML using the bold tag",
              "type" : "code",
              "language" : "markup"
            },
            { "title" : "Image (JPG or PNG) of your home page (Maximum 1MB per file)",
              "type" : "image"
            }
        ],
        "gallery" : "off",
        "galleryformat" : "card",
        "totalpoints" : 10,
        "instructorpoints" : 0,
        "peerpoints" : 6,
        "rating" : 0,
        "assesspoints" : 2,
        "minassess" : 2,
        "maxassess" : 5,
        "flag" : true
    }';
    $json = json_decode($json);
    if ( $json === null ) die("Bad JSON constant");
    $json = json_encode($json);
    // $json = \Tsugi\Util\LTI::jsonIndent($json);
    return $json;
}

function pointsDetail($assn_json) {
    $r = "The total number of points for this assignment is $assn_json->totalpoints.\n";
    if ( isset($assn_json->instructorpoints) && $assn_json->instructorpoints > 0 ) {
        $r .= "You will get up to $assn_json->instructorpoints points from your instructor.\n";
    }
    if ( isset($assn_json->peerpoints) && $assn_json->peerpoints > 0 ) {
        $r .= "You will get up to $assn_json->peerpoints points from your peers.\n";
    }
    if ( isset($assn_json->assesspoints) && $assn_json->assesspoints > 0 ) {
        $r .= "You will get $assn_json->assesspoints for each peer assignment you assess.\n";
    }
    if ( isset($assn_json->minassess) && $assn_json->minassess > 0 ) {
        $r .= "You need to grade a minimum of $assn_json->minassess peer assignments.\n";
    }
    if ( isset($assn_json->maxassess) && $assn_json->maxassess >  $assn_json->minassess) {
        $r .= "You can grade up to $assn_json->maxassess peer assignments if you like.\n";
    }
    return $r;
}
