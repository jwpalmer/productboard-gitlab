<?


/**
 This code is set to receive Productboard events as a webhook, and to take corresponding action in Gitlab with the sent data. Initial JSON payloads from Productboard come with an eventType and a target URL to retrieve data about the affected entity.

 The code below parses data->eventType to drive the action it will take with the data from the corresponding data->links->target.

 For easy debugging, this webhook is called concurrently with one at:
    https://webhook.site/#!/6a79e516-9cbe-46fe-a92e-6b1abdcf57fe
 It receives all the same events and data as this one.
*/


# in config.php, toggles are available to disable the entire sync service or just calls to productboard
if (!ENABLE_SERVICE || !ENABLE_PRODUCTBOARD){

    if (!ENABLE_SERVICE){
        $LOGGER->write('Service was called, but is disabled.', __LINE__, __FILE__);
        echo "Service is disabled.";
    }else{
        $LOGGER->write('Service was called, but Productboard is disabled.', __LINE__, __FILE__);
        echo "Productboard broadcasting is disabled.";
    }
    exit();

}


/**
 When registering a subscription URL to a webhook in Productboard,the registered URL will be pinged to validate that it's expecting pings.

 To validate, we need to return a validationToken that is passed as a query parameter to the registered URL when the subscription is registered.
**/
if (isset($_REQUEST['validationToken'])){

    $LOGGER->write('Webhook was registered with validation token '.$_REQUEST['validationToken'], __LINE__, __FILE__);
	$token = $_REQUEST['validationToken'];
	echo $token;
    exit();

}


/**
 When creating a webhook, Productboard will pass any value you like in an 'authorization' header, and also lets you specify a version number for your webhook, so you can differentiate between requests.
**/
$key = @$_SERVER['authorization'];
$version = @$_SERVER['version']; # not using this right now -JP 09/2022

if (!empty($key) && $key !== PRODUCTBOARD['auth']){

    http_response_code(401);
    $LOGGER->write('Exiting early; authorization header had an invalid value.', __LINE__, __FILE__);
    echo "Please provide a recognized value in the authentication header.";
    exit();

}


/**
 When triggering this webhook, Productboard will act on the returned HTTP response code, and for anything other than a 2XX, will retry the event as many as 8 times with a psuedo-exponentially-scaled sequence of wait times in minutes (1m, 3m, 9m, 27m, 95m... see https://developer.productboard.com/#section/Notification-delivery for full scale)

 Be sure to respond with valid codes so Productboard knows what to do.
*/
if (isset($_SERVER['REQUEST_METHOD']) && strtolower($_SERVER['REQUEST_METHOD']) !== 'post'){
    http_response_code(405);
    $LOGGER->write('Exiting early; request method not allowed. Try POST.', __LINE__, __FILE__);
    echo "Request method not allowed on this resource. Try POST.";
    exit();
}

$LOGGER->write('Parsing body payload...', __LINE__, __FILE__, FALSE, TRUE);
$json = file_get_contents('php://input');
$obj = json_decode($json);
$LOGGER->write('JSON body from payload decoded: '.serialize($obj), __LINE__, __FILE__, FALSE, TRUE);


$LOGGER->write('Grabbing detailed Productboard object attributes...', __LINE__, __FILE__, FALSE, TRUE);
$PB = new productboard();
$PB->get_target($obj->data);
$LOGGER->write('Got target data back from Productboard: '.serialize($PB), __LINE__, __FILE__, FALSE, TRUE);


$httpCode = 200;

$LOGGER->write('Determining which Productboard event to be handled...', __LINE__, __FILE__);

switch($PB->event){

    /***
    Issue in Productboard has been set with a project ID in the "push to Gitlab" field, so:
    A. check URL field of pushed issue to see if Gitlab URL exists for Productboard issue
     - If empty, create a new issue in Gitlab, and populate the URL field when done.
     - If already populated and valid, update issue attributes from Productboard to Gitlab
     - If not empty and not valid, return a friendly error
    **/
    case "hierarchy-entity.custom-field-value.updated":

        // var_dump($PB); #this has all of the data from the originally-sent event, plus some data from the target entity updated
        // exit();

        $LOGGER->write('Custom field value updated, determining type...', __LINE__, __FILE__, FALSE, TRUE);

        $event = (object)[
          'id' => $PB->targetData->hierarchyEntity->id,    # feature ID, a GUID
          'fieldId'=> $PB->targetData->customField->id,    # the ID of the custom field that was changed in Productboard
          'type' => $PB->targetData->hierarchyEntity->type # "feature"
        ];

        /***
        We only want to know when a feature that has this field is updated, *not* if the field configuration itself changes (say, the field is renamed), and we only want to react to changes in the Push to Gitlab URL (otherwise we'll have circular events coming in)
        **/
        if ($event->type=='feature' && $event->fieldId==PRODUCTBOARD['push-to-gitlab']){

            $LOGGER->write('Event type is feature-related, and matches a request to push to gitlab.', __LINE__, __FILE__, FALSE, TRUE);

            # get the feature's attributes & values
            $feature = $PB->get_feature($event->id);

            $LOGGER->write('Prepping to push to Gitlab, got full feature data back from Productboard: '.serialize($feature), __LINE__, __FILE__, FALSE, TRUE);


            # 1. Use the value updated from the dropdown to push or move the issue to Gitlab.
            $pushToGitlabValue = $PB->targetData->value; # when populated, this is an object with a "label" child node, but when no value is set, this is just an empty object (or string?)
            if (!empty($pushToGitlabValue)){

                preg_match("/\([0-9]+\)/", $pushToGitlabValue->label, $matches);
                $gitlabProjectId = str_replace('(','',str_replace(')','',$matches[0]));

                # step back a second, check if this feature has a Gitlab URL already
                $gitlabUrl = $PB->get_field_value_attributes(
                    PRODUCTBOARD['gitlab-url'],    # the custom field ID for the 'Gitlab URL' text field in Productboard
                    $feature->id                   # the hierarchyEntity ID to return the value attributes for
                );

                # if the URL is already set and the dropdown changed (most likely to a new project), we want to move the issue to that corresponding project in Gitlab
                if (!empty($gitlabUrl->value)){

                    $LOGGER->write('Will update in Gitlab, since Gitlab URL has a value: '.$gitlabUrl->value, __LINE__, __FILE__, FALSE, FALSE);

                    # move gitlab issue to the project with $gitlabProjectId specified in update event
                    $matches = [];
                    preg_match('/[^\/]+(\/$|$)/', $gitlabUrl->value, $matches);
                    $gitlabIssueId = $matches[0];
                    $arr = explode('/', str_replace('https://gitlab.com/','',$gitlabUrl->value));
                    $gitlabProjectPath = urlencode($arr[0].'/'.$arr[1]);

                    $GL = new gitlab($feature->owner->email);
                    if ($GL->move_issue((object)[
                        'id'         => $feature->id,       # productboard feature ID
                        'iid'        => $gitlabIssueId,     # gitlab internal issue ID, the last segment of a gitlab web_url
                        'fromProject'=> $gitlabProjectPath, # the urlencoded path or ID/integer for the project the issue is currently in
                        'toProject'  => $gitlabProjectId    # the project ID/int to move the issue to
                    ])){

                        # update returned Gitlab URL in Productboard
                        $PB->update_field_value((object)[
                            'feature'=> $feature->id,
                            'field' => PRODUCTBOARD['gitlab-url'],
                            'value' => $GL->generatedUrl
                        ]);

                    }

                # URL was empty, we're creating a new issue in Gitlab in the specified project, and updating the URL field on the given feature in Productboard
                }else{

                    $LOGGER->write('No Gitlab URL set for Productboard issue, will create a new one in Gitlab.', __LINE__, __FILE__);

                    # convert Productboard status to Gitlab status
                    if ($feature->status->id == STATUS_MAPPING['open']){
                        $updateState    = 'opened';
                        $updateStatus   = '';
                    }elseif ($feature->status->id == STATUS_MAPPING['closed']){
                        $updateState    = 'closed';
                        $updateStatus   = '';
                    }else{
                        # the issue is open, but is somewhere between start and finished, so needs some other status label
                        $updateState    = 'opened';
                        $updateStatus   = STATUS_MAPPING['statuses'][$feature->status->id];
                    }

                    # create new issue in Gitlab
                    $GL = new gitlab($feature->owner->email);
                    if ($GL->create_issue((object)[
                        'project'           => $gitlabProjectId,
                        'title'             => $feature->name,
                        'description'       => $feature->description,
                        'link'              => $feature->links->html,
                        'state'             => $updateState,
                        'status'            => $updateStatus, # hey, if you're tired: no need to consider other labels here, this is a new issue
                        'productboardId'    => $feature->id
                    ])){

                        $LOGGER->write('Issue created in Gitlab, got URL back: '.$GL->generatedUrl, __LINE__, __FILE__, FALSE, TRUE);

                        # update returned Gitlab URL in Productboard
                        $PB->update_field_value((object)[
                            'feature'   => $feature->id,
                            'field'     => PRODUCTBOARD['gitlab-url'],
                            'value'     => $GL->generatedUrl
                        ]);

                        $LOGGER->write('Gitlab URL updated in Productboard for feature ID '.$feature->id, __LINE__, __FILE__);

                    }

                }

            # 2. If the dropdown value has been updated to "not set" (null) in Productboard *and* the Gitlab URL field has a value, then we want to delete it from Gitlab (but not Productboard) based on the Gitlab URL
            }else{

                $LOGGER->write('Dropdown value for Gitlab project has been unset in Productboard, preparing to delete from Gitlab...', __LINE__, __FILE__);

                # step back a second, check if this feature has a Gitlab URL already
                $gitlabUrl = $PB->get_field_value_attributes(
                    PRODUCTBOARD['gitlab-url'],    # the custom field ID for Gitlab URL
                    $PB->targetData->hierarchyEntity->id            # the hierarchyEntity ID to return the value attributes for
                );

                $matches = [];
                preg_match('/[^\/]+(\/$|$)/', $gitlabUrl->value, $matches);
                $gitlabIssueId = $matches[0];
                $arr = explode('/', str_replace('https://gitlab.com/','',$gitlabUrl->value));
                $gitlabProjectPath = urlencode($arr[0].'/'.$arr[1]);


                $LOGGER->write('Set Gitlab URL field in Productboard to empty', __LINE__, __FILE__, FALSE, TRUE);

                # for this feature, set the Gitlab URL field in Productboard to empty
                $PB->update_field_value((object)[
                    'feature'=> $PB->targetData->hierarchyEntity->id,
                    'field' => PRODUCTBOARD['gitlab-url'],
                    'value' => ''
                ]);

                # delete issue from Gitlab
                $GL = new gitlab();
                if (!$GL->delete_issue((object)[
                    'path' => $gitlabProjectPath,
                    'iid'   => $gitlabIssueId
                ])){
                    $LOGGER->write('Issue deleted from Gitlab at '.$gitlabProjectPath.'/issues/'.$gitlabIssueId, __LINE__, __FILE__);
                    $httpCode = 400;
                }

            }

        }
    break;


    /***
     B. A feature was updated in Productboard, and if there is a valid Gitlab URL attached to it, we need to update a corresponding feature in Gitlab.
    **/
    case "feature.updated":

        // var_dump($PB); #this has all of the data from the originally-sent event, plus some data from the target entity updated
        // exit();

        $LOGGER->write('Feature updated in Productboard, updating in Gitlab...', __LINE__, __FILE__);

        # 1. Check if Gitlab URL is set
        $gitlabUrl = $PB->get_field_value_attributes(
            PRODUCTBOARD['gitlab-url'],    # the custom field ID for Gitlab URL
            $PB->targetData->id            # the hierarchyEntity ID to return the value attributes for
        );

        # 2. Get the Gitlab project ID assigned to the feature from the Productboard custom field dropdown value
        $gitlabProjectId = $PB->get_field_value_attributes(
            PRODUCTBOARD['push-to-gitlab'],    # the custom field ID for push to gitlab
            $PB->targetData->id                # the hierarchyEntity ID to return the value attributes for
        );

        # 3. If the Gitlab URL is set, update the title, description, and status in Gitlab
        if (!empty($gitlabUrl->value)){

            $LOGGER->write('Gitlab URL value is set, proceeding with update.', __LINE__, __FILE__, FALSE, TRUE);

            preg_match('/\([0-9]+\)/', $gitlabProjectId->value->label, $projectId);
            preg_match('/\/[0-9]+/', $gitlabUrl->value, $urlParts);

            $pid = str_replace('(', '', str_replace(')','',$projectId[0]));
            $iid = str_replace('(', '', str_replace(')','', str_replace('/','',$urlParts[0])));

            $GL = new gitlab($PB->targetData->owner->email);
            $issue = $GL->get_issue((object)[
               'project' => $pid,
               'iid'    =>  $iid
            ]);

            $LOGGER->write('Got existing issue to be updated from Gitlab, extracting updated_at and labels...'.serialize($issue), __LINE__, __FILE__, FALSE, TRUE);

            # filter out any status labels from the existing labels, to avoid conflicts
            $filteredLabels = array_filter($issue->labels, function($item) {
                return !in_array($item, array_values(STATUS_MAPPING['statuses']));
            });
            $existingLabels = (empty($issue->labels)) ? '' : implode(',', $filteredLabels);

            $LOGGER->write('Got existing labels, if any:'.$existingLabels, __LINE__, __FILE__, FALSE, TRUE);


            # To prevent circular updates, check for timestamp of last edit of Gitlab issue. If it was updated in the last 30 seconds, don't update it.
            $diffSeconds = (new DateTimeImmutable("now"))->format('U')-(new DateTimeImmutable($issue->updated_at))->format('U');

            if ($diffSeconds > UPDATE_WAIT){

                $LOGGER->write('Wait threshold passed with '.$diffSeconds.' seconds, updating this feature in Gitlab. Timing was:'."\nnow: ".(new DateTimeImmutable("now"))->format('c').' / '.(new DateTimeImmutable("now"))->format('U')."\nupdatedAt: ".(new DateTimeImmutable($issue->updated_at))->format('c').' / '.(new DateTimeImmutable($issue->updated_at))->format('U'), __LINE__, __FILE__, FALSE, TRUE);


                # convert Productboard status to Gitlab status
                if ($PB->targetData->status->id == STATUS_MAPPING['open']){

                    # the Productboard status ID matches the 'open' state mapping to Gitlab
                    $updateState    = 'opened';
                    $updateStatus   = $existingLabels;

                }elseif ($PB->targetData->status->id == STATUS_MAPPING['closed']){

                    # the Productboard status ID matches the 'closed' state mapping to Gitlab
                    $updateState    = 'closed';
                    $updateStatus   = $existingLabels;

                }else{

                    # the issue is open, but is somewhere between start and finished, so needs some other status label
                    $updateState    = 'opened';
                    $updateStatus   = STATUS_MAPPING['statuses'][$PB->targetData->status->id].','.$existingLabels;

                }

                $feature = (object)[
                  'project'         => $pid,
                  'productboardId'  => $PB->targetData->id,
                  'iid'             => $iid,
                  'title'           => $PB->targetData->name,
                  'description'     => $PB->targetData->description, # productboard delivers HTML, gitlab wants markdown
                  'link'            => $PB->targetData->links->html,
                  'state'           => $updateState,
                  'status'          => $updateStatus
                ];

                $LOGGER->write('Data ready to update in Gitlab: '.serialize($feature), __LINE__, __FILE__, FALSE, TRUE);

                if (!$GL->update_issue($feature)){
                    $LOGGER->write('Issue failed to update in Gitlab.', __LINE__, __FILE__);
                    $httpCode = '400';
                }

            }else{

                echo "No need to update, we're under the wait time threshold and prevented a circular callback.";

                $LOGGER->write('No need to update in Gitlab, we\'re under the wait time threshold and prevented a circular callback.'."\n".'Difference in seconds:'.$diffSeconds.' based on:'."\n".'now:'.(new DateTimeImmutable("now"))->format('c').' / '.(new DateTimeImmutable("now"))->format('U')."\n".'updatedAt:'.$issue->updated_at.' / '.(new DateTimeImmutable($issue->updated_at))->format('U'), __LINE__, __FILE__);

            }

        }else{

            # 3. If the Gitlab URL is not set, do nothing
            echo "No Gitlab URL is set, no target issue in Gitlab to update.";

            $LOGGER->write('No Gitlab URL is set, no target issue in Gitlab to update.', __LINE__, __FILE__);

        }

    break;



    default:
        # No recognized event was sent, do nothing
        $LOGGER->write('No recognized event type was sent from Productboard (this is fine, this webhook is registered for many events), ignoring.', __LINE__, __FILE__, FALSE, TRUE);
    break;

}

http_response_code($httpCode);


/* ---------------------------------------*/
// End of file index.php
// Location: /gitlab-productboard/views/productboard/index.php
