<?

/*

**/

# html -> markdown
require_once __DIR__.'/../../plugins/markdownify/Converter.php';
require_once __DIR__.'/../../plugins/markdownify/ConverterExtra.php';
require_once __DIR__.'/../../plugins/markdownify/Parser.php';


class gitlab {

	public string $generatedUrl, $projectId;


	public function __construct(
		$ownerEmail = ''
	){

		/*
		 Gitlab recommends personal access tokens, so this service will use the one matching the owner of an issue in Productboard. In the event there is not a match or no owner provided, the first item in the array should be used.
		*/
		$this->key = (isset(GITLAB['keys'][$ownerEmail]) && !empty(GITLAB['keys'][$ownerEmail])) ? GITLAB['keys'][$ownerEmail] : array_values(GITLAB['keys'])[0];
		$this->baseUrl = GITLAB['url'];

	}


	private function gl_request( $METHOD, $URL, $CONTENT='' ){

		# To access the Gitlab API, we need to provide a bearer token, authentication:$CFG->['gitlab']['key']
		$context = stream_context_create([
			  'http'=>[
				'method'=>$METHOD,
				'header'=>"Accept-language: en\r\n" .
						  "Authorization: Bearer ".$this->key."\r\n".
						  "Content-type: application/json\r\n",
				'content'=>$CONTENT
			]
		]);

		$response = file_get_contents($URL, false, $context);

//		Gitlab is returning 400 errors but still successfully performing operations, disable this for now
// 		if (str_contains($http_response_header[0], '200')){
// 			return $response;
// 		}
//
// 		return FALSE;

		return $response;

	}


	public function get_issue( $PARAMS ){

		# GET /projects/:id/issues/:issue_iid

		$response = $this->gl_request(
			"GET",
			$this->baseUrl.'/projects/'.$PARAMS->project.'/issues/'.$PARAMS->iid
		);

		if ($response){
			$data = json_decode($response);
			return $data;
		}

		return FALSE;

	}


	public function get_project_id( $PARAMS ){

		# translate a URL-encoded project name into its internal Gitlab ID
		# GET /project_aliases/:name

		$response = $this->gl_request(
			"GET",
			$this->baseUrl.'/projects/'.$PARAMS->path
		);

		if ($response){
			$data = json_decode($response);
			$this->projectId = $data->id;
			return $data;
		}

		return FALSE;

	}


	public function create_issue( $PARAMS ){

		# needs Gitlab admin access

		$MD = new Markdownify\Converter;

		$response = $this->gl_request(
			"POST",
			$this->baseUrl.'/projects/'.$PARAMS->project.'/issues/',
			json_encode([
				'title'			=> $PARAMS->title,
				'description'	=> "[[pb:".$PARAMS->productboardId."]]  \n\n[View in Productboard](".$PARAMS->link.")  \n\n".$MD->parseString($PARAMS->description),
				'labels'		=> $PARAMS->status
			])
		);

		if ($response){
			$data = json_decode($response);
			$this->generatedUrl = $data->web_url;
			return $data;
		}

		return FALSE;

	}


	public function update_issue( $PARAMS ){

		# To prevent circular updates, check for timestamp of last edit of Gitlab issue. If it was updated in the last UPDATE_WAIT seconds, don't update it.
		$issue = $this->get_issue((object)[
			'project'	=> $PARAMS->project,
			'iid'		=> $PARAMS->iid
		]);

		# PUT /projects/:id/issues/:issue_iid
		$MD = new Markdownify\Converter;

		$response = $this->gl_request(
			"PUT",
			$this->baseUrl.'/projects/'.$PARAMS->project.'/issues/'.$PARAMS->iid,
			json_encode([
				'title' 		=> $PARAMS->title,
				'description'	=> "[[pb:".$PARAMS->productboardId."]]  \n\n[View in Productboard](".$PARAMS->link.")  \n\n".$MD->parseString($PARAMS->description),
				'labels'		=> $PARAMS->status
			])
		);

		if ($response){
			$data = json_decode($response);
			$this->generatedUrl = $data->web_url;
			return $data;
		}

		return FALSE;

	}


	public function move_issue( $PARAMS ){

		# /projects/:id/issues/:issue_iid/move?to_project_id={project id}

		$response = $this->gl_request(
			"POST",
			$this->baseUrl.'/projects/'.$PARAMS->fromProject.'/issues/'.$PARAMS->iid.'/move?to_project_id='.$PARAMS->toProject
		);

		if ($response){
			$data = json_decode($response);
			$this->generatedUrl = $data->web_url;
			return $data;
		}

		return FALSE;

	}

	public function delete_issue( $PARAMS ){

		# needs Gitlab admin access
		# DELETE /projects/:id/issues/:issue_iid

		$projectId = $this->get_project_id((object)[
			'path' => $PARAMS->path
		]);

		$response = $this->gl_request(
			"DELETE",
			$this->baseUrl.'/projects/'.$this->projectId.'/issues/'.$PARAMS->iid
		);

		if ($response){
			$data = json_decode($response);
			return $data;
		}

		return FALSE;

	}

}


/* ---------------------------------------*/
// End of file class.gitlab.php
// Location: /gitlab-productboard/parts/php/class.gitlab.php
