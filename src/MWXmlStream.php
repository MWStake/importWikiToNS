<?php
/**
 * Create a stream for XmlWriter to write to and MW to use as input
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

class MWXmlStream implements ImportSource {
	private $fp;

	/**
	 * @parent
	 * @return bool
	 */
	public function atEnd() {
		return $this->stream_eof();
	}

	/**
	 * @parent
	 * @return bool|string
	 */
	public function readChunk() {
	}

	/**
	 * See http://php.net/manual/en/streamwrapper.stream-open.php
	 * @param string $path Specifies the URL that was passed
	 * @param string $mode The mode used to open the file (see fopen())
	 * @return bool success or no
	 */
	public function stream_open( $path, $mode ) { // @codingStandardsIgnoreLine
		$url = parse_url( $path );
		$path = $url['host'];
		self::$files[$path] = fopen( "/tmp/weired-{$path}", 'w+' );
		$this->fp = &self::$files[$path];
		if ( !$this->fp ) {
			return false;
		}
		return true;
	}

	/**
	 * See http://php.net/manual/en/streamwrapper.stream-close.php
	 * Called in response to fclose().
	 */
	public function stream_fclose() { // @codingStandardsIgnoreLine
	}

	/**
	 * Write to stream. Called in response to fwrite().
	 * @param string $data store as much as possible
	 * @return int bytes stored
	 */
	public function stream_write( $data ) { // @codingStandardsIgnoreLine
		return fwrite( $this->fp, $data );
	}

	/**
	 * Read from stream. Called in response to fread() and fgets().
	 * stream_eof() is called directly after calling this to check if
	 * EOF has been reached. If not implemented, EOF is assumed.
	 * @param int $count how many bytes wanted
	 * @return string
	 */
	public function stream_read( $count ) { // @codingStandardsIgnoreLine
	}

	/**
	 * This method is called in response to feof()
	 * @return bool TRUE if no more data is available to be read, FALSE otherwise.
	 */
	public function stream_eof() { // @codingStandardsIgnoreLine
	}
}
