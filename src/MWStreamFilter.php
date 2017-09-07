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

$maintPath = dirname( __DIR__ ) . "/vendor/autoload.php";
if ( file_exists( $maintPath ) ) {
	require_once $maintPath;
}

use DomDocument;
use DOMElement;
use DOMNodeList;
use DomXPath;
use FileRepo;
use MediaWiki\MediaWikiServices;
use Title;
use XMLReader;
use XMLWriter;
use XMLWritingIteration;

class MWStreamFilter extends XMLWritingIteration {
	protected $re;
	protected $ns;
	protected $nsID;
	protected $oldCase;
	protected $import;

	protected $nsList;

	protected $title;
	protected $content;

	protected $titles;
	protected $nsFromImport;
	protected $updatePagesAnyway;

	/**
	 * Construct!
	 * @param ImportWikiToNS\Import $import the importer object
	 */
	public function __construct( Import $import ) {
		$this->titles = [];
		$this->import = $import;
		if ( $this->import->canReopen() ) {
			$inTitle = new XMLReader;
			$inTitle->open( $this->import->getInName() );
			// Read input twice.  Got to cache the pages the first time through.
			$this->getPageTitles( $inTitle );
		} else {
			$this->import->error(
				"Cannot update the links if we cannot re-read the file. Use "
				. "--pages to force update anyway."
			);
		}

		$in = new XMLReader;
		$in->open( $this->import->getInName() );

		$out = new XMLWriter();
		$out->openURI( $this->import->getOutName() );

		$this->ns = $this->import->getTargetNS();
		$this->nsID = $this->import->getTargetNSID();

		$this->nsList = $this->import->getNamespaces();

		$this->updatePagesAnyway = $this->import->getUpdatePageLinks();
		$this->iwlookup
			= MediaWikiServices::getInstance()->getInterwikiLookup();

		parent::__construct( $out, $in );
	}

	/**
	 * Setter for RegExp
	 * @param string $re The regex
	 */
	public function setRegExp( $re ) {
		$this->re = $re;
	}

	/**
	 * Setter for the target NS
	 * @param string $ns The namespace
	 */
	public function setNewNS( $ns ) {
		$this->ns = $ns;
	}

	/**
	 * Setter for target NS ID
	 * @param int $nsID Namespace ID
	 */
	public function setNewNSID( $nsID ) {
		$this->nsID = $nsID;
	}

	/**
	 * Internal method to get the old NS
	 */
	protected function getOldCase() {
		$this->oldCase = $this->current()->readInnerXML();
		do {
			$this->write();
			$this->next();
		} while ( $this->current()->nodeType !== XMLReader::END_ELEMENT );
	}

	/**
	 * Internal method to get all the namespaces used.
	 */
	protected function findNamespaces() {
		$this->next();
		$current = $this->current();
		if ( $current === null ) {
			$this->import->output(
				"Could not find any XML. Quitting.\n"
			);
			exit;
		}
		while ( $current && $current->name !== "namespaces" ) {
			if ( $current->name === "case" ) {
				$this->getOldCase();
			}
			$this->write();
			$this->next();
			$current = $this->current();
		}
		$this->write();
		$this->next();
	}

	/**
	 * Internal method to add those namespaces being copied to the output file
	 */
	protected function addNewNamespace() {
		$this->import->output( "Inserting new namespace\n" );
		$this->writer->text( "\n      " );
		$this->writer->startElement( "namespace" );
		$this->writer->writeAttribute( "key", $this->nsID );
		$this->writer->writeAttribute( "case", $this->oldCase );
		$this->writer->text( $this->ns );
		$this->writer->endElement();
		$this->writer->text( "\n      " );
	}

	/**
	 * Internal method to skip ahead to the pages
	 */
	protected function skipToPages() {
		do {
			$this->write();
			$this->next();
		} while ( $this->current()->name !== "siteinfo" );
	}

	/**
	 * Read in the namespaces used in the input file
	 */
	protected function readOldNamespaces() {
		do {
			$this->next();
			$this->write();
		} while ( $this->current()->name !== "namespace" );
		do {
			if ( $this->current()->name === "namespace"
				 && $this->current()->nodeType === XMLReader::ELEMENT
			) {
				$ns = $this->reader->readString();
				if ( $ns !== '' ) {
					$this->nsFromImport[strtolower( $ns )] = true;
				}
			}
			$this->next();
			$this->write();
		} while ( $this->current()->name !== "namespaces" );

		# Add pseudo namespaces and sometimes bogus links
		$this->nsFromImport['image'] = true;
		$this->nsFromImport['http'] = true;
	}

	/**
	 * Main entry point that does all the heavy lifting
	 */
	public function transform() {
		$this->oldCase = "first-letter";
		$this->findNamespaces();
		$this->addNewNamespace();
		$this->readOldNamespaces();
		$this->skipToPages();

		do {
			$xml = $this->filter();
			if ( $xml instanceof DomDocument ) {
				$this->append( $xml );
			}
		} while ( $this->reader->next() );

		// Proably not the right way to do this.
		$this->writer->writeRaw( "</mediawiki>" );
	}

	/**
	 * Internal method to provide parsed elements of a page's representation
	 * @return array
	 *    0 - DomElement for Title
	 *    1 - DomElement for Namespace
	 *    2 - DomElement for NamespaceID
	 *    3 - DomNodeList for text elements
	 *    3 - DomNodeList for sha1 elements
	 *    4 - DomDocument for this page
	 * @FIXME yes, this should be its own class
	 */
	protected function getPageElements() {
		// dummy doc so we can deal with DomDocument
		$dom = new DomDocument;

		// Add input to dummy doc
		$dom->appendChild( $this->reader->expand() );

		// do xpath query for this guy
		$xpath = new DOMXPath( $dom );
		$xpath->registerNamespace(
			"m", "http://www.mediawiki.org/xml/export-0.10/"
		);
		$title = $xpath->evaluate( "/m:page/m:title" );
		$ns = $xpath->evaluate( "/m:page/m:ns" );
		$id = $xpath->evaluate( "/m:page/m:id" );
		$text = $xpath->evaluate( "/m:page/m:revision/m:text" );
		$sha1 = $xpath->evaluate( "/m:page/m:revision/m:sha1" );

		return [ $title[0], $ns[0], $id[0], $text, $sha1, $dom ];
	}

	/**
	 * Determine if namespace is to be included.
	 * @fixme stubbed for now
	 * @param string $title to check
	 * @return bool
	 */
	protected function namespaceIsIncluded( $title ) {
		# Namespaces don't have : in them, right?
		if ( preg_match( "#^([^:]+):#", $title, $match ) ) {
			return isset( $this->nsList[$match[1]] );
		}
	}

	/**
	 * Method to handle the conversion of a page
	 * @return DomDocument of massaged page
	 */
	public function filter() {
		list( $titleEL, $nsEL, $idEL, $textEL, $sha1, $xml )
			= $this->getPageElements();
		$append = false;
		if ( $titleEL ) {
			$title = $titleEL->textContent;
			$pageEL = $titleEL->parentNode;
			$ns  = (int)$nsEL->textContent;
			$newNS = null;

			if ( $ns === 0 ) {
				$newNS = $this->ns;
				$nsEL->textContent = $this->nsID;
				$title = $newNS . ":$title";
			}
			if ( $ns === 1 ) {
				$newNS = $this->ns . " talk";
				$nsEL->textContent = $this->nsID + 1;
				$title = $newNS . ' talk:' . substr( $title, 5 );
			}

			if ( $ns > 1 && !$this->namespaceIsIncluded( $title ) ) {
				# Skip this page
				return false;
			}

			$titleEL->textContent = $title;

			$idEL->parentNode->removeChild( $idEL );
			$this->removeSha1( $sha1 );
			$this->fixRevisions( $textEL );

			if (
				$this->isFile( $title )
				&& $this->import->shouldEncodeFiles()
			) {
				$this->addEncodedFile( $title, $pageEL );
			}

			$append = $xml;
		}
		return $append;
	}

	/**
	 * Is this a title for a file?
	 * @param string $title to check
	 * @return bool
	 */
	protected function isFile( $title ) {
		return substr( $title, 0, 5 ) === "File:";
	}

	/**
	 * Append an encoded file
	 * @param string $title of file
	 * @param DOMDocument $xml to add encoded file to
	 */
	protected function addEncodedFile( $title, DOMElement $xml ) {
		$file = Title::newFromText( $title )->getDBkey();
		$path = $this->import->getBasePath() . "/" .
			  self::getHashPath( $file ) . "/$file";

		if ( !file_exists( $path ) ) {
			$this->import->error( "File doesn't exits, cannot encode: "
								  . $file );
			return;
		}

		$contents = file_get_contents( $path );
		if ( $contents === false ) {
			$this->import->error( "Trouble getting contents of file: "
								  . $file );
			return;
		}

		$upload = new DomElement( "upload" );
		$contents = new DomElement(
			"contents", chunk_split( base64_encode( $contents ) )
		);

		$xml->appendChild( $upload );
		$upload->appendChild( $contents );
		$contents->setAttribute( "encoding", "base64" );
	}

	/**
	 * Stolen from FileRepo::getHashPathForLevel with 2 hardcoded.
	 * @param string $name of file
	 * @return string
	 */
	protected static function getHashPath( $name ) {
		$hash = md5( $name );
		$path = '';
		for ( $i = 1; $i <= 2; $i++ ) {
			$path .= substr( $hash, 0, $i ) . '/';
		}

		return $path;
	}

	/**
	 * Remove sha1 just because they're wrong.
	 * @param DomElement $sha1 the sha1 elements
	 */
	protected function removeSha1( DOMNodeList $sha1 ) {
		foreach ( $sha1 as $el ) {
			$el->parentNode->removeChild( $el );
		}
	}

	/**
	 * Fix up page all revisions of page content
	 * @param DomElement $textEL the revisions
	 */
	protected function fixRevisions( DOMNodeList $textEL ) {
		$preTitleRE = '#\[\[';
		$postTitleRE = '#';
		foreach ( $textEL as $revision ) {
			$revText = $revision->textContent;
			preg_match_all( $preTitleRE . '([^|\]]+)' . $postTitleRE,
							$revText, $match );
			$links = array_unique( $match[1] );
			foreach ( $links as $link ) {
				$update = $this->shouldUpdate( $link );
				if ( $update ) {
					$revText = preg_replace(
						$preTitleRE . preg_quote( $link, '#' ) . $postTitleRE,
						'[[' . $update, $revText
					);
				}
			}
			$revision->textContent = $revText;
		}
	}

	/**
	 * Determine if this title should be updated
	 * @param string $link link text to update
	 * @return null|string if fixed
	 */
	protected function shouldUpdate( $link ) {
		if (
			( isset( $this->titles[ lcFirst( $link ) ] )
			  && $this->titles[ lcFirst( $link ) ] )
			|| ( $this->updatePagesAnyway && $this->isInMainNS( $link ) )
		) {
			return $this->ns . ':' . $link;
		}
	}

	/**
	 * Determine if this title looks like it is in the main namespace.
	 * @param string $link link text to check
	 * @return bool
	 */
	protected function isInMainNS( $link ) {
		if ( substr( $link, 0, 1 ) === ":" ) {
			$link = substr( $link, 1 );
		}

		# why put links to shared drives here?
		if ( substr( $link, 1, 2 ) === ":\\" ) {
			return false;
		}

		# URLs?
		if ( strstr( $link, "://" ) !== false ) {
			return false;
		}

		$prefix = strstr( $link, ":", true );

		if ( $this->iwlookup->isValidInterwiki( $prefix ) ) {
			return false;
		}
		if (
			$prefix === false
			|| !isset( $this->nsFromImport[strtolower( $prefix )] )
		) {
			return true;
		}
		return false;
	}

	/**
	 * Strip the starting (and any other) PI from an XML string
	 * @param DomDocument $xml to clean
	 * @return string
	 */
	protected function xmlWithoutPI( DomDocument $xml ) {
		$str = $xml->saveXML();
		$pattern = '~
<\?
	(?: [A-Za-z_:] | [^\x00-\x7F] ) (?: [A-Za-z_:.-] | [^\x00-\x7F] )*
	(?: \?> | \s (?: [^?]* \?+ ) (?: [^>?] [^?]* \?+ )* >)
~x';

		return preg_replace( $pattern, '', $str );
	}

	/**
	 * Append the xml fragment to the output
	 * @param DomDocument $xml document
	 */
	protected function append( DomDocument $xml ) {
		$str = $this->xmlWithoutPI( $xml );
		$this->writer->writeRaw( $str );
		$this->writer->flush();
	}

	/**
	 * Skip over the bits we don't want till we get to the XML node we want
	 * @param XmlReader $in the input
	 * @param string $elName what we're looking for
	 * @return bool whether to continue or not
	 */
	protected function readUntil( XmlReader $in, $elName ) {
		$continue = true;
		do {
			$continue = $in->read();
		} while ( $continue && $in->name !== $elName
				 || ( $in->nodeType !== XMLReader::ELEMENT
					  && $in->name === $elName ) );
		return $continue;
	}

	/**
	 * Get the page titles from this the input
	 * @param XmlReader $in the input
	 */
	protected function getPageTitles( XmlReader $in ) {
		$this->readUntil( $in, "siteinfo" );
		// Get down on the same level as pages
		$continue = $this->readUntil( $in, "page" );
		while ( $continue ) {
			$this->readUntil( $in, "title" );
			$title = $in->readInnerXML();

			$this->readUntil( $in, "ns" );
			$ns = $in->readInnerXML();

			// Only copy the main namespace
			if ( $ns < 2 ) {
				$this->titles[lcFirst( $title )] = true;
			}
			$continue = $this->readUntil( $in, "page" );
		}
	}
}
