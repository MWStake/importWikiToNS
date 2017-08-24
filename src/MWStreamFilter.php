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

class MWStreamFilter extends XMLWritingIteration {
	private $re;
	private $ns;
	private $nsID;
	private $oldCase;

	private $inName;
	private $outName;

	private $title;
	private $content;

	private $titles;

	/**
	 * Construct!
	 * @param string $inFile filename to read
	 * @param string $outFile filename to write
	 */
	public function __construct( $inFile, $outFile ) {
		$inTitle = new XMLReader;
		$inTitle->open( $inFile );
		// Read input twice.  Got to cache the pages the first time through.
		$this->getPageTitles( $inTitle );

		$in = new XMLReader;
		$in->open( $inFile );
		$this->inName = $inFile;

		$out = new XMLWriter();
		$out->openURI( $outFile );
		$this->outName = $inFile;

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
		$current = $this->current();
		if ( $current === null ) {
			$this->next();
			$current = $this->current();
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
		echo "Inserting new namespace\n";
		$this->writer->text( "\n      " );
		$this->writer->startElement( "namespace" );
		$this->writer->writeAttribute( "key", $this->nsID );
		$this->writer->writeAttribute( "case", $this->oldCase );
		$this->writer->text( $this->ns );
		$this->writer->endElement();
		$this->writer->text( "\n      " );
		do {
			$this->next();
			$this->write();
			if ( $this->current()->name === "namespaces"
				 && $this->current()->nodeType === XMLReader::END_ELEMENT ) {
				break;
			}
		} while ( 1 );
		$this->next();
	}

	/**
	 * Internal method to skip ahead to the pages
	 */
	protected function skipToPages() {
		do {
			$this->write();
			$this->next();
		} while ( $this->current()->name !== "page" );
	}

	/**
	 * Main entry point that does all the heavy lifting
	 * @param Maintenance $maint Object that we'll use for user notifications
	 */
	public function transform( Maintenance $maint ) {
		$this->oldCase = "first-letter";
		$this->findNamespaces();
		$this->addNewNamespace();
		$this->skipToPages();

		do {
			$xml = $this->filter( $maint );
			if ( $xml instanceof DomDocument ) {
				$this->append( $xml );
			}
		} while ( $this->reader-> next() );
	}

	/**
	 * Internal method to provide parsed elements of a page's representation
	 * @return array
	 *    0 - DomElement for Title
	 *    1 - DomElement for Namespace
	 *    2 - DomElement for NamespaceID
	 *    3 - DomElement for text
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
		$xpath->registerNamespace( "m", "http://www.mediawiki.org/xml/export-0.10/" );
		$title = $xpath->evaluate( "/m:page/m:title" );
		$ns = $xpath->evaluate( "/m:page/m:ns" );
		$id = $xpath->evaluate( "/m:page/m:id" );
		$text = $xpath->evaluate( "/m:page/m:revision/m:text" );

		return [ $title[0], $ns[0], $id[0], $text, $dom ];
	}

	/**
	 * Method to handle the conversion of a page
	 * @param Maintenance $maint object for user feedback.
	 * @return DomDocument of massaged page
	 */
	public function filter( Maintenance $maint ) {
		list( $titleEL, $nsEL, $idEL, $textEL, $xml ) = $this->getSXE();
		$append = false;
		if ( $titleEL ) {
			$title = $titleEL->textContent;
			$id = int( $idEL->textContent );
			$newNS = $this->ns;

			if ( $id == 1 ) {
				$newNS = "Talk";
			}

			if ( int( $id ) > 1 ) {
				$match = [];

				# Namespaces don't have : in them, right?
				if ( !preg_match( "([^:])+:", $title, $match ) ) {
					$maint->error( wfMessage( "importw2ns-ns-match", $title )->plain() );
					return false;
				}
				$newNS = $match[1];
			}

			if ( substr( $title, 0, 5 ) === "Talk:" ) {
				$title = $this->ns . ' talk:' . substr( $title, 5 );
			} elseif ( substr( $title, 0, 9 ) !== "Category:" ) {
				$title = $this->ns . ":$title";
			}
			$titleEL->textContent = $title;

			$nsEL->textContent = $this->nsID + $nsEL->textContent;
			$idEL->textContent = '';
			$this->fixRevisions( $textEL );

			$append = $xml;
		}
		return $append;
	}

	/**
	 * Fix up page all revisions of page content
	 * @param DomElement $textEL the revisions
	 */
	protected function fixRevisions( $textEL ) {
		$preTitleRE = '#\[\[';
		$postTitleRE = '#';
		foreach ( $textEL as $revision ) {
			$revText = $revision->textContent;
			preg_match_all( $preTitleRE . '([^|\]]+)' . $postTitleRE, $revText, $match );
			$links = array_unique( $match[1] );
			foreach ( $links as $link ) {
				$update = $this->shouldUpdate( $link );
				if ( $update ) {
					$revText = preg_replace(
						$preTitleRE . $link . $postTitleRE, '[[' . $update, $revText
					);
				}
			}

			preg_replace( "#<sha1>[^<]+</sha1>#", '', $revText );
			$revision->textContent = $revText;
		}
	}

	/**
	 * Determine if this title should be updated
	 * @param string $link link text to update
	 * @return null|string if fixed
	 */
	protected function shouldUpdate( $link ) {
		if ( isset( $this->titles[ lcFirst( $link ) ] )
			 && $this->titles[ lcFirst( $link ) ] ) {
			return $this->ns . ':' . $link;
		}
	}

	/**
	 * Strip the starting (and any other) PI from an XML string
	 * @param DomDocument $xml to clean
	 * @return string
	 */
	protected function xmlWithoutPI( DomDoument $xml ) {
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
