<?php
/**
 * Copyright (C) 2017  Mark A. Hershberger
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace ImportWikiToNS;

$maintPath = ( getenv( 'MW_INSTALL_PATH' ) !== false
			  ? getenv( 'MW_INSTALL_PATH' )
			  : __DIR__ . '/../../..' ) . '/maintenance/Maintenance.php';
if ( !file_exists( $maintPath ) ) {
	echo "Please set the environment variable MW_INSTALL_PATH "
		. "to your MediaWiki installation.";
	exit( 1 );
}
require_once $maintPath;

class Import extends Maintenance {
	public function __construct() {
		parent::__construct();

		$this->addDescription( "Import a wiki's dump file into your main namespace." );
		$this->addOption( "n", "A comma separted list of namespaces in the import "
						  . "to import other than the main NS.", false, true,
						  "namespaces" );
		$this->addOption( "t", "The target namespace to import the main ns in the "
						  . "import to. Links in the import to pages in the main "
						  . "namespace will be translated to this namespace.",
						  true, true, "target" );
		$this->addOption( "i", "The integer value of the target namespace.  If this "
						  . "is not given, the script will use your configuration "
						  . "to determine this and error out if none is found.",
						  false, true, "targetID" );
		$this->addOption( "s", "The value of \$wgServer for the source wiki that "
						  . "the dump originates on. This will be used for file "
						  . "imports.", false, true, "wgServer" );
		$this->addOption( "M", "Attempt to run with default memory limits.  This is "
						  . "likely to cause problems with the import, but you can try.",
						  false, false, "limit-memory" );
		$this->addArg( "dumpfile", "The XML export to use. STDIN is used if this "
					   . "isn't given.", false );
		$this->addArg( "outfile", "Where to save the XML output.  STDOUT is used if this "
					   . "isn't given.", false );
	}

	public function execute() {
	}
}

$maintClass = "ImportWikiToNS\\Import";
require_once RUN_MAINTENANCE_IF_MAIN;
