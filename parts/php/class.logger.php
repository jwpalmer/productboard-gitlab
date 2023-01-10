<?php

/*
This class is a utility to allow writing errors to log.txt,
which is automatically placed in the project root.
*/

class logger{

	public function __construct($PATH){

		return $this->set_file($PATH . '/log.txt');

	}


	public function __sleep(){

		$this->write("\n\n\n\n");
		fclose($this->handle);

	}


	public function __wakeup(){

		$this->set_file($this->path);

	}


	public function set_file( $PATH ){

		if (DEBUG_ON){

			$this->path = $PATH;

			if ($this->handle = fopen($PATH,"a")){

				fwrite($this->handle,"\n- - - - - - - - - - - - - - -\n");
				return $this->handle;

			}

		}

		return FALSE;

	}


	public function write( $STR, $LINE=FALSE, $FILE=FALSE, $FUNCTION=FALSE, $ISVERBOSE=FALSE ){

		global $CFG;

		if (DEBUG_ON){

			$now = date('Y-m-d g:i:sa T');

			$end = '';
			$append = array();
			if ($LINE){ $append[]='Line '.$LINE; }
			if ($FILE){ $append[]=$FILE; }
			if ($FUNCTION){ $append[]=$FUNCTION; }
			if (count($append) > 0){ $end = '('.implode(' * ', $append).")\n"; }

			# Is the line to be written considered verbose? Do we have verbose logging turned on?
			if ($ISVERBOSE && LOG_VERBOSE){

				if (fwrite($this->handle, "[V!] ".$now." :: ".$STR."\n".$end."\n") === FALSE) { return FALSE; }

				return TRUE;

			}else{

				if (fwrite($this->handle, $now." :: ".$STR."\n".$end."\n") === FALSE) { return FALSE; }

				return TRUE;

			}

		}


		return FALSE;

	}


	public function get_file( $PATH='' ){

		if(!empty($PATH)){ $this->path = $PATH; }

		if (!$handle = fopen($this->path, 'a')) {

			 echo "Cannot open logfile.";

			 return FALSE;

		}

		return TRUE;

	}

}


/* ---------------------------------------*/
// End of file class.logger.php
// Location: /gitlab-productboard/parts/php/class.logger.php
