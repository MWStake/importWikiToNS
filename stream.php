<?php



require "vendor/autoload.php";
require "src/MWStreamFilter.php";

ini_set('memory_limit', '150000000000');

$inName = "/home/mah/bkup.xml";
$outName = "out.xml";

$re = '^(Help|MediaWiki|File|Property|File|Template|User)( talk)?';
$ns = 'EAI';
$nsID = 556;

$iter = new MWStreamFilter( $inName, $outName );

$iter->setNewNS( $ns );
$iter->setNewNSID( $nsID );
$iter->setRegExp( $re );
$iter->transform();

