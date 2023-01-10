<?

/***
Productboard will send via the POST http request method, and provide a JSON payload that looks something like this:

	{
		 "data":
		 {
			 "eventType": "hierarchy-entity.custom-field-value.updated",
			 "links":
			 {
				 "target": "https://api.productboard.com/hierarchy-entities/custom-fields-values/value?customField.id=898a6004-8aca-490b-aa83-10a4c4c573ac&hierarchyEntity.id=1be161c2-1253-4836-9df1-8c2ec8dddc6e"
			 }
		 }
	 }

	... or this ...

	{
		"data":
		{
			"id": "4639e43a-3851-4566-a506-41ee907af799",
			"eventType": "feature.updated",
			"links":
			{
				"target": "https://api.productboard.com/features/4639e43a-3851-4566-a506-41ee907af799"
			}
		}
	}

**/

require_once __DIR__.'/../../plugins/parsedown/Parsedown.php'; # markdown -> html


class productboard {

	# we'll use the "push to gitlab" and "gitlab URL" custom field IDs from Productboard as the triggers to perform needed actions, which need to be passed into this class on setup.
	public string $event;
	public string $target;
	public object $targetData;

	public function __construct(){

		$this->key = PRODUCTBOARD['key'];
		$this->baseUrl = PRODUCTBOARD['url'];

	}

	private function pb_request( $METHOD, $URL, $CONTENT='' ){

		# To access the Productboard API, we need two header key/value pairs: X-version:1, and authentication:{$this-key}
		$context = stream_context_create([
	  		'http'=>[
				'method'=>$METHOD,
				'header'=>"Accept-language: en\r\n" .
				  		"Authorization: Bearer ".$this->key."\r\n".
						"Content-type: application/json\r\n".
				  		"X-Version: 1\r\n",
				'content'=>$CONTENT
			]
		]);
		// echo "\n\n\ncontent:\n"; # for debugging
		// var_dump($CONTENT);

		$response = file_get_contents($URL, false, $context);
		return $response;

	}

	# Any payload we receive, we probably care about the corresponding links->target's data, so this function pre-emptively retrieves it and loads it into $this->targetData
	public function get_target( $PAYLOAD ){

		$this->event = $PAYLOAD->eventType;			# a string from Productboard telling us the type of event, like "feature.updated"
		$this->target = $PAYLOAD->links->target; 	# this is a direct URL to the Productboard API, corresponding to this object's attributes and values, to be used with get_target()

		$response = $this->pb_request("GET", $this->target);

		if (!empty($response)){
			$obj = json_decode($response);
			$this->targetData = $obj->data;
			return $this->targetData;
		}

		return FALSE;

	}


	# UTILITIES

	# get a custom field value's attributes for a custom field ID on the given entity ID (e.g., a feature ID)
	public function get_field_value_attributes( $FIELDID, $ENTITYID ){

		global $CFG;

		$response = $this->pb_request("GET", $this->baseUrl.'/hierarchy-entities/custom-fields-values/value?customField.id='.$FIELDID.'&hierarchyEntity.id='.$ENTITYID);

		if (!empty($response)){
			$obj = json_decode($response);
			return $obj->data;
		}

		return FALSE;

	}

	# update a custom field value
	public function update_field_value( $PARAMS ){

		global $CFG;

		$response = $this->pb_request(
			"PUT",
			$this->baseUrl.'/hierarchy-entities/custom-fields-values/value?customField.id='.$PARAMS->field.'&hierarchyEntity.id='.$PARAMS->feature,
			json_encode([
				'data' => [
					'type' => 'text',
					'value' => $PARAMS->value
				]
			])
		);

		if (!empty($response)){
			$obj = json_decode($response);
			return $obj->data;
		}

		return FALSE;

	}


	# get a feature's attributes from Productboard
	public function get_feature( $ID ){

		$response = $this->pb_request(
			"GET",
			$this->baseUrl.'/features/'.$ID
		);

		if (!empty($response)){
			$obj = json_decode($response);
			return $obj->data;
		}

		return FALSE;

	}

	# update a feature's attributes in Productboard
	public function update_feature( $PARAMS ){

		# PATCH /features/{id}

		// $PD = new Parsedown();

		$response = $this->pb_request(
			"PATCH",
			$this->baseUrl.'/features/'.$PARAMS->productboardId,
			json_encode([
				'data' => [
					'name'	 		=> $PARAMS->title,

					# replace the [[pb:123-abc]] id stored in the description before updating in Productboard
					# Productboard wants HTML, Gitlab supplies markdown - convert md to html with Parsedown
					# description updating disabled for now, it's screwing with status
					// 'description' 	=> $PD->text(preg_replace('/\[\[.+\]\]/', '', strip_tags($PARAMS->description))),
					'status'		=> [
						'id'		=> $PARAMS->status
					]
				]
			])
		);

		if (!empty($response)){
			$obj = json_decode($response);
			return $obj->data;
		}

		return FALSE;

	}

	# delete a feature in Productboard
	public function delete_feature( $ID ){

		return FALSE;

	}

}


/* ---------------------------------------*/
// End of file class.productboard.php
// Location: /gitlab-productboard/parts/php/class.productboard.php
