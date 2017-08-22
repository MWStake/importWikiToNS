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

    function __construct( $inFile, $outFile ) {
        $inTitle = new XMLReader;
        $inTitle->open($inFile);

        $in = new XMLReader;
        $in->open($inFile);
        $this->inName = $inFile;
        // Read input twice.  Got to cache the pages the first time through.
        $this->getPageTitles( $inTitle );

        $out = new XMLWriter();
        $out->openURI($outFile);
        $this->outName = $inFile;


        parent::__construct( $out, $in );
    }

    function setRegExp( $re ) {
        $this->re = $re;
    }

    function setNewNS( $ns ) {
        $this->ns = $ns;
    }

    function setNewNSID( $nsID ) {
        $this->nsID = $nsID;
    }

    protected function getOldCase() {
        $this->oldCase = $this->current()->readInnerXML();
        do {
            $this->write();
            $this->next();
        } while ( $this->current()->nodeType !== XMLReader::END_ELEMENT );
    }

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
        } while(1);
        $this->next();
   }

    protected function skipToPages() {
        do {
            $this->write();
            $this->next();
        } while ( $this->current()->name !== "page" );
    }

    function transform() {
        $this->oldCase = "first-letter";
        $this->findNamespaces();
        $this->addNewNamespace();
        $this->skipToPages();

        do {
            $xml = $this->filter();
            if ( $xml instanceOf DOMNode ) {
                $this->append( $xml );
            }
        } while ( $this->reader-> next() );
    }

    protected function getSXE() {
        // dummy doc so we can deal with DomDocument
        $dom = new DomDocument;

        // Add input to dummy doc
        $dom->appendChild($this->reader->expand());

        // do xpath query for this guy
        $xpath = new DOMXPath( $dom );
        $xpath->registerNamespace( "m", "http://www.mediawiki.org/xml/export-0.10/" );
        $title = $xpath->evaluate("/m:page/m:title");
        $ns = $xpath->evaluate("/m:page/m:ns");
        $id = $xpath->evaluate("/m:page/m:id");
        $text = $xpath->evaluate("/m:page/m:revision/m:text");

        return [ $title[0], $ns[0], $id[0], $text, $dom ];
    }

    function filter() {
        list($titleEL, $nsEL, $idEL, $textEL, $xml) = $this->getSXE();
        $append = false;
        if ( $titleEL ) {
            $title = $titleEL->textContent;
            if ( !preg_match( "/{$this->re}/", $title ) ) {
                if ( substr( $title, 0, 5 ) === "Talk:" ) {
                    $title = $this->ns . ' talk:' . substr( $title, 5 );
                } elseif ( substr( $title, 0, 9 ) !== "Category:" ) {
                    $title = $this->ns . ":$title";
                }
                $titleEL->textContent = $title;

                $nsEL->textContent = $this->nsID + $nsEL->textContent;
                $idEL->textContent = '';
                $this->fixLinks( $textEL );

                $append = $xml;
            }

        }

        return $append;
    }

    function fixLinks( $textEL ) {
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

    protected function shouldUpdate( $link ) {
        if ( isset( $this->titles[ lcFirst( $link ) ] )
             && $this->titles[ lcFirst( $link ) ] ) {
            return $this->ns . ':' . $link;
        }
    }

    protected function xmlWithoutPI( $xml ) {
        $str = $xml->saveXML();
        $pattern = '~
<\?
    (?: [A-Za-z_:] | [^\x00-\x7F] ) (?: [A-Za-z_:.-] | [^\x00-\x7F] )*
    (?: \?> | \s (?: [^?]* \?+ ) (?: [^>?] [^?]* \?+ )* >)
~x';

        return preg_replace($pattern, '', $str);
    }

    function append( $xml ) {
        if ( !method_exists( $xml, "saveXML" ) ) {
            $dom = new DomDocument;
            $dom->appendChild($xml);
            $xml = $dom;
        }

        $str = $this->xmlWithoutPI( $xml );
        $this->writer->writeRaw( $str );
        $this->writer->flush();
    }

    protected function readUntil( $in, $elName ) {
        $continue = true;
        do {
            $continue = $in->read();
        } while( $continue && $in->name !== $elName
                 || ( $in->nodeType !== XMLReader::ELEMENT
                      && $in->name === $elName ) );
        return $continue;
    }

    function getPageTitles( $in ) {
        $this->readUntil( $in, "siteinfo" );
        // Get down on the same level as pages
        while ( $page = $this->readUntil( $in, "page" ) ) {
            $this->readUntil( $in, "title" );
            $title = $in->readInnerXML();

            $this->readUntil( $in, "ns" );
            $ns = $in->readInnerXML();

            // Only copy the main namespace
            if ( $ns < 2 ) {
                $this->titles[lcFirst( $title )] = true;
            }
        }
    }
}
