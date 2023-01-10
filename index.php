<?php

/**
* [/projects.johnwpalmer.com/gitlab-productboard/index.php]
*
* See readme.md for details on this project.
*
==========================================================
*/

/*
 Load environment variables
*/
require __DIR__.'/config.php'; # ensure this is outside/inaccessible to the web root

/*
 Load utility classes
*/
require __DIR__.'/parts/php/class.productboard.php';
require __DIR__.'/parts/php/class.gitlab.php';

require __DIR__.'/parts/php/class.logger.php';
$LOGGER = new logger(LOG_PATH); # load up for logging, if enabled and configured in config.php


//-- -- -- -- -- -- -- -- -- -- -- -- -- -- -- -- -- -- //

$VW = @$_REQUEST['vw']; # .htaccess sets the URL path after the web root to this variable

$LOGGER->write('View requested is '.$VW, __LINE__, __FILE__);

if (in_array($VW, ['productboard','gitlab'])){

    $LOGGER->write('Loading view '.$VW, __LINE__, __FILE__, FALSE, TRUE);

    require __DIR__.'/views/'.$VW.'/index.php';

    $LOGGER->write('Execution completed when calling view '.$VW, __LINE__, __FILE__, FALSE, TRUE);

}

/*
==========================================================
*/
?>
