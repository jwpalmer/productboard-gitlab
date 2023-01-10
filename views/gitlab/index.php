<?


/**
 This service is designed to receive events from Gitlab, and then take the appropriate action in Productboard.

 When creating a webhook, Gitlab will pass any value you like in an 'X-Gitlab-Token' header, and also provides an 'X-Gitlab-Event' header, which indicates the kind of event being broadcast to the hook. The value is set in config.php.

 For easy debugging, this webhook is called concurrently with one at:

 https://webhook.site/#!/e264ff0a-a2bb-42d5-85f3-6336f3ca9726

 It receives all the same events and data as this one.
**/


@ini_set('post_max_size' , '5M'); # lengthy JSON seems to be throwing php://input off ¯\_(ツ)_/¯


# in config.php, toggles are available to disable the entire sync service or just calls to productboard
if (!ENABLE_SERVICE || !ENABLE_GITLAB){
	if (!ENABLE_SERVICE){
		$LOGGER->write('Service was called, but is disabled.', __LINE__, __FILE__, FALSE, FALSE);
		echo "Service is disabled.";
	}else{
		$LOGGER->write('Service was called, but Gitlab is disabled.', __LINE__, __FILE__, FALSE, FALSE);
		echo "Gitlab broadcasting is disabled.";
	}
	exit();
}


$key = @$_SERVER['X-Gitlab-Token'];
$event = @$_SERVER['X-Gitlab-Event'];

if (!empty($key) && $key !== GITLAB['auth']){
	http_response_code(401);
	$LOGGER->write('Invalid auth key provided in header. Exiting....', __LINE__, __FILE__);
	echo "Please provide a recognized value in the authentication header.";
	exit();
}


/**
 When triggering this webhook, Gitlab will act on the returned HTTP response code, and for anything other than a 2XX, will retry the event.

 Timeouts can also trigger retries, so be sure to respond with valid codes– and quickly– so Gitlab knows what to do.
*/
if (isset($_SERVER['REQUEST_METHOD']) && strtolower($_SERVER['REQUEST_METHOD']) !== 'post'){
	http_response_code(405);
	$LOGGER->write('Request method not allowed on this resource. Try POST. Exiting....', __LINE__, __FILE__);
	echo "Request method not allowed on this resource. Try POST.";
	exit();
}
$json = file_get_contents('php://input');
$obj = json_decode($json);
$httpCode = 200;


/**
 1. Extract key elements from the issue that's been updated.

 $obj contains a bunch of information passed from Gitlab about an issue– the project it's associated with, links to associated comment data, author attributes, and more.

 This code only should tell Productboard about issue changes in state (opened/closed), status (status:: label), title, or description.
*/

# get the state (not status) of the issue in Gitlab, one of 'opened' or 'closed'
$state = $obj->object_attributes->state;
$LOGGER->write('Got state from existing Gitlab issue: '.$state, __LINE__, __FILE__, FALSE, TRUE);

# get the Productboard object ID from the gitlab issue description
$rawDescription = $obj->object_attributes->description;
preg_match('/\[\[.+\]\]/',$obj->object_attributes->description,$matches); # ID is stored in stored in $matches[0]


/*
 2. Calculate the status to set in Productboard.
 This is tricky, since Gitlab determines board status/progress two ways: with a issue labels, but also with an open/closed state. Productboard thinks of all of this with a single list of customizable statuses, which need to be mapped here.
*/

# determine valid status, if any, based on whether it's in the statuses in STATUS_MAPPING
$status = array_filter($obj->object_attributes->labels, function($item){
	return in_array($item->title, STATUS_MAPPING['statuses']);
});
$LOGGER->write('Looked for status matches based on those in the statuses config array, found: '.serialize($status), __LINE__, __FILE__, FALSE, TRUE);

# get first object from filtered array, just in case multiple statuses have been assigned
$status = reset($status);
$LOGGER->write('Got first object from status array just to be safe, status to set in Productboard is now: '.serialize($status), __LINE__, __FILE__, FALSE, TRUE);


# it's possible we didn't find a status in the STATUS_MAPPING array, so look to open/closed state in this case, or if closed, close the issue
if (!isset($status) || empty($status) || $state=="closed"){

	# if there's no status, we'll defer to setting this as open/new or closed
	switch ($state){
		case "opened":
			$updateStatus = STATUS_MAPPING['open'];
			$LOGGER->write('No status set, deferred to state, which was opened, so setting to mapped Productboard status for open.', __LINE__, __FILE__, FALSE, TRUE);
		break;

		case "closed":
		default:
			$updateStatus = STATUS_MAPPING['closed'];
			$LOGGER->write('No status set, deferred to state, which was closed, so setting to mapped Productboard status for closed.', __LINE__, __FILE__, FALSE, TRUE);
		break;
	}

}else{

	# if there is a status, let's try to look it up in the mapping array
	if (isset(array_flip(STATUS_MAPPING['statuses'])[$status->title])){
		$updateStatus = array_flip(STATUS_MAPPING['statuses'])[$status->title];
		$LOGGER->write('Found matching status in array: '.$updateStatus, __LINE__, __FILE__, FALSE, TRUE);
	}else{
		echo "Error: matching status not found, defaulting to open.";
		$updateStatus = STATUS_MAPPING['open'];
		$LOGGER->write('Error: matching status not found, defaulting to open / '.$updateStatus, __LINE__, __FILE__, FALSE, TRUE);
	}

}


$event = (object)[
	'type'			=> $obj->event_type,
	'productboardId' => str_replace('[[pb:','',str_replace(']]','',$matches[0])),
	'title'			=> $obj->object_attributes->title,
	'rawDescription'=> $rawDescription,
	'description' 	=> str_replace($matches[0],'',$rawDescription),
	'status'		=> $updateStatus,
	'url'			=> $obj->object_attributes->url,
	'updated'		=> $obj->object_attributes->updated_at
];
$LOGGER->write('Event object created and ready to be loaded into Productboard. Data is:'.serialize($event), __LINE__, __FILE__, FALSE, TRUE);


# Confirm we're only updating if the event type is for an issue. We do receive other event types from Gitlab.
if ($event->type=='issue'){

	$LOGGER->write('Event type is an issue, getting existing feature attributes from Productboard before updating...', __LINE__, __FILE__, FALSE, TRUE);

	$PB = new productboard();
	$feature = $PB->get_feature($event->productboardId);

	$LOGGER->write('Got existing attributes from Productboard:'.serialize($feature), __LINE__, __FILE__, FALSE, TRUE);

	$diffSeconds = (new DateTimeImmutable("now"))->format('U')-(new DateTimeImmutable($feature->updatedAt))->format('U');

	# Prevent circular calls from when an update from one webhook triggers the other by checking the destination feature's last update timestamp.
	if ($diffSeconds > UPDATE_WAIT){

		$LOGGER->write('Wait threshold passed with '.$diffSeconds.' seconds, updating this feature in Productboard. Timing was:'."\nnow: ".(new DateTimeImmutable("now"))->format('c').' / '.(new DateTimeImmutable("now"))->format('U')."\nupdatedAt: ".(new DateTimeImmutable($feature->updatedAt))->format('c').' / '.(new DateTimeImmutable($feature->updatedAt))->format('U'), __LINE__, __FILE__, FALSE, TRUE);

		if (!$PB->update_feature($event)){
			$LOGGER->write('Feature updated in Productboard.', __LINE__, __FILE__);
			$httpCode = 400;
		}

	}else{

		echo "We're under the wait time threshold. Didn't do anything in order to prevent circular callbacks.";
		$LOGGER->write('No need to update in Productboard, we\'re under the wait time threshold and prevented a circular callback.'."\n".'Difference in seconds:'.$diffSeconds.' based on:'."\n".'now:'.(new DateTimeImmutable("now"))->format('c').' / '.(new DateTimeImmutable("now"))->format('U')."\n".'updatedAt:'.$feature->updatedAt.' / '.(new DateTimeImmutable($feature->updatedAt))->format('U'), __LINE__, __FILE__);

	}

}


http_response_code($httpCode);


/* ---------------------------------------*/
// End of file index.php
// Location: /gitlab-productboard/views/gitlab/index.php
