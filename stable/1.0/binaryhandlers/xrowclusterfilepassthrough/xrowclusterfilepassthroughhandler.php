<?php

class xrowclusterfilepassthroughhandler extends eZBinaryFileHandler
{
	const HANDLER_ID = 'xrowclusterfilepassthrough';
	
    function xrowclusterfilepassthroughhandler()
    {
        $this->eZBinaryFileHandler( self::HANDLER_ID, "direct download", eZBinaryFileHandler::HANDLE_DOWNLOAD );
    }

    function handleFileDownload( $contentObject, $contentObjectAttribute, $type, $fileInfo )
    {
        $fileName = $fileInfo['filepath'];
        $this->file = eZClusterFileHandler::instance( $fileName );

        if ( $fileName != "" and $this->file->exists() )
        {
            $mimeType =  $fileInfo['mime_type'];
            $originalFileName = $fileInfo['original_filename'];
            $contentLength = $fileSize;
            $fileOffset = false;
            $fileLength = false;

            ob_clean();

            $ini = eZINI::instance( 'file.ini' );
            $fileList = $ini->variable( 'DownloadSettings', 'List' );
            $keys = array_keys( $fileList );
            $mime = eZMimeType::findByURL( $originalFileName );
            if( in_array( $mime['suffix'], $keys ) )
                $disposition = $fileList[$mime['suffix']];
            else 
                $disposition = $ini->variable( 'DownloadSettings', 'Default' );
                    
            switch ( $disposition )
            {
                    case 'inline':
                        header( "Content-disposition: inline; filename=\"$originalFileName\"" );
                        break;
                    case 'attached':
                        header( "Content-disposition: attached; filename=\"$originalFileName\"" );
                        break;
                    case 'none':
                    case '':
                    default:
                    	header( "Content-disposition: inline; filename=\"$originalFileName\"" );
                        break;
            }

            $this->passthrough();

            eZExecution::cleanExit();
        }
        return eZBinaryFileHandler::RESULT_UNAVAILABLE;
    }
    function passthrough()
    {
        $path = $this->file->filePath;
        eZDebugSetting::writeDebug( 'kernel-clustering', "db::passthrough( '$path' )" );
        if ( $this->file->metaData === false )
            $this->file->loadMetaData();
        $contentLength = $this->file->metaData['size'];
        $mtime = $this->file->metaData['mtime'];
        $mime = eZMimeType::findByURL( $this->file->metaData['name'] );
        $mimeType = $mime['name'];
        $mdate = gmdate( 'D, d M Y H:i:s', $mtime );
                if ( isset( $_SERVER['HTTP_RANGE'] ) )
            {
                $httpRange = trim( $_SERVER['HTTP_RANGE'] );
                if ( preg_match( "/^bytes=([0-9]+)-$/", $httpRange, $matches ) )
                {
                    $fileOffset = $matches[1];
                    header( "Content-Range: bytes $fileOffset-" . ( $fileSize - 1 ) . "/$fileSize" );
                    header( "HTTP/1.1 206 Partial content" );
                    $contentLength -= $fileOffset;
                }
            }

        ob_clean();
        header( "Pragma: " );
        header( "Cache-Control: " );
        header( "Content-Length: $contentLength" );
        header( "Content-Type: $mimeType" );
        header( "Last-Modified: $mdate GMT" );
        header( "Expires: ". gmdate('D, d M Y H:i:s', time() + 6000) . ' GMT');
        header( "Connection: close" );
        header( "X-Powered-By: eZ Publish" );
        header( "Accept-Ranges: bytes" );
        header( "Content-Transfer-Encoding: binary" );
                //var_dump($contentLength);exit(0);
        $this->_passThrough( $path );
    }
    function _passThrough( $filePath, $fname = false )
    {
        if ( $fname )
            $fname .= "::_passThrough($filePath)";
        else
            $fname = "_passThrough($filePath)";

        $metaData = $this->file->backend->_fetchMetadata( $filePath, $fname );
        if ( !$metaData )
            return false;

        $sql = "SELECT filedata FROM ezdbfile_data WHERE name_hash='" . md5( $filePath ) . "' ORDER BY offset";
        if ( !$res = $this->file->backend->_query( $sql, $fname ) )
        {
        	eZError::writeError( "FAILED: $filePath $sql", 'ClusterPassthrough' );
            return false;
        }

        while ( $row = mysql_fetch_row( $res ) )
            echo $row[0];

        return true;
    }
}

?>
