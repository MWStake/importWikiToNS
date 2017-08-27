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
		. "to your MediaWiki installation.\n";
	exit( 1 );
}
require_once $maintPath;

use Maintenance;
use MWNamespace;

class Import extends Maintenance {

	protected $inName;
	protected $canReopen;

	protected $outName;

	protected $target;
	protected $targetID;

	protected $namespaces;

	protected $encode;
	protected $basePath;

	public function __construct() {
		parent::__construct();

		$this->addDescription( "Import a wiki's dump file into your main namespace." );
		$this->addOption( "namespaces", "A comma separted list of namespaces in the import "
						  . "to import other than the main NS.", false, true, "n" );
		$this->addOption( "target", "The target namespace to import the main ns in the "
						  . "import to. Links in the import to pages in the main "
						  . "namespace will be translated to this namespace.", true, true,
						  "t" );
		$this->addOption( "targetID", "The integer value of the target namespace.  If this "
						  . "is not given, the script will use your configuration to "
						  . "determine this and error out if none is found.", false, true,
						  "i" );
		$this->addOption( "wgServer", "The value of \$wgServer for the source wiki that "
						  . "the dump originates on. This will be used for file imports.",
						  false, true, "s" );
		$this->addOption( "limit-memory", "Attempt to run with default memory limits. "
						  . "This is likely to cause problems with the import, but you "
						  . "can try.", false, false, "M" );
		$this->addOption( "encode-files", "Embed a file's binary data into the output. "
						  . "This will make your file larger (maybe too large?) but you'll "
						  . "only have to deal with one file.\n\n        If you use this, "
						  . "the File namespace will automatically be included and you "
						  . "will need to pass the --uploads option to importDump.php."
						  . "\n\n        If you don't use this but still want to import "
						  . "one wiki's files into another, you will need to use "
						  . "--image-base-path with importDump.php. You will also want "
						  . "to specify at least 'File' for --namespaces.",
						  false, false, "e" );
		$this->addOption( "pages", "Update all links in the so that they point to the new "
						  . "namespace." );
		$this->addOption( "image-base-path", "Encode files from a specified path. "
						  . "Specifying this assumes --encode-files. Defaults to an "
						  . "images subdirectory in the current directory if not given.",
						  false, true, "b" );
		$this->addArg( "dumpfile", "The XML export to use. STDIN is used if this is not "
					   . "given.", false );
		$this->addArg( "outfile", "Where to save the XML output.  STDOUT is used if this "
					   . "is not given.", false );

		spl_autoload_register( function ( $className ) {
			if ( $className === "ImportWikiToNS\\MWStreamFilter" ) {
				require dirname( __DIR__ ) . '/src/MWStreamFilter.php';
			}
		} );
	}

	/**
	 * Where the work is done.
	 */
	public function execute() {
		if ( !$this->hasOption( "limit-memory" ) ) {
			ini_set( 'memory_limit', -1 );
		}

		$this->inName = "php://stdin";
		$this->canReopen = false;
		if ( $this->hasArg( 0 ) ) {
			$this->inName = $this->getArg( 0 );
			$this->canReopen = true;
		}

		$this->outName = "php://stdout";
		if ( $this->hasArg( 1 ) ) {
			$this->outName = $this->getArg( 1 );
		}

		$this->target = $this->getOption( "target" );
		$this->targetID = MWNamespace::getCanonicalIndex( $this->target );
		if ( $this->hasOption( "targetID" ) ) {
			if ( $this->targetID !== null ) {
				$this->error( sprintf(
					"Provided ID for target namespace (%d) does not match ID in this "
					. "installation (%d).  Using the provided ID anyway.",
					$this->getOption( "targetID" ), $this->targetID
				) );
			}
			$this->targetID = $this->getOption( "targetID" );
		}

		$this->namespaces = [];
		if ( $this->hasOption( "namespaces" ) ) {
			$this->namespaces = array_flip(
				explode( ",", $this->getOption( "namespaces" ) )
			);
		}

		if ( $this->hasOption( "image-base-path" ) ) {
			$this->basePath = $this->getOption( "image-base-path" );
			$this->encode = true;
		}

		if ( $this->hasOption( "encode-files" ) || $this->encode ) {
			$this->encode = true;

			$imgs = realpath( $this->basePath ? $this->basePath : "images" );
			if (
				!$imgs
				|| !file_exists( $imgs )
				|| !is_dir( $imgs )
				|| !is_readable( $imgs )
			) {
				$this->error( sprintf(
					"Image path (%s) does not exist, is not a directory, or "
					. "is not readable.", $imgs
				), true );
			}
			$this->output( sprintf( "Using images path: %s\n", $imgs ) );
			$this->basePath = $imgs;
			$this->namespaces['File'] = true;
		}

		$mwsf = new MWStreamFilter( $this );
		$mwsf->transform();
	}

	/**
	 * Return true if the --pages option is passed.
	 * @return bool
	 */
	public function getUpdatePageLinks() {
		return $this->hasOption( "pages" );
	}

	/**
	 * Where are the files?
	 * @return string
	 */
	public function getBasePath() {
		return $this->basePath;
	}

	/**
	 * Are we supposed to encode files?
	 * @return bool
	 */
	public function shouldEncodeFiles() {
		return $this->encode;
	}

	/**
	 * Expose a protected method.
	 * @param string $err output
	 * @param int $die or not
	 */
	public function error( $err, $die = 0 ) {
		parent::error( $err, $die );
	}

	/**
	 * Expose another protected method.
	 * @param string $out output
	 * @param string $channel output channel
	 */
	public function output( $out, $channel = null ) {
		parent::output( $out, $channel );
	}

	/**
	 * Returns true if the input can be opened again (e.g. not STDIN)
	 * @return bool
	 */
	public function canReopen() {
		return $this->canReopen;
	}

	/**
	 * Returns the name of the input file
	 * @return string;
	 */
	public function getInName() {
		return $this->inName;
	}

	/**
	 * Returns the name of the output file
	 * @return string
	 */
	public function getOutName() {
		return $this->outName;
	}

	/**
	 * Return the target NS
	 * @return string
	 */
	public function getTargetNS() {
		return $this->target;
	}

	/**
	 * Return the target NS's ID
	 * @return int
	 */
	public function getTargetNSID() {
		return $this->targetID;
	}

	/**
	 * Return all the namespaces to copy (other than the main one)
	 * @return array
	 */
	public function getNamespaces() {
		return $this->namespaces;
	}
}

$maintClass = "ImportWikiToNS\\Import";
require_once RUN_MAINTENANCE_IF_MAIN;
