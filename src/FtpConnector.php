<?php

namespace App\Helpers;


class FtpConnector {
    protected 
        $connection = null,
        $dateFormat = 'D.m.Y H:i:s',
        $file = null,
        $files = null,
        $folder = null,
        $ftpConnections = [],
        $localDirectory = null,
        $localFile = null,
        $localRootDirectory = '',
        $login = null,
        $remoteDirectory = null,
        $remoteFile = null,
        $password = null,
        $port = null,
        $protocol = null,
        $server = null,
        $syncConfigs = null,
        $user = null;

    public function __construct() {
        return $this;
    }

    protected function connect() {
        switch ($this->protocol) {
            case 'FTP':
                $this->connection = ftp_connect($this->server, $this->port);
                break;
            
            case 'SFTP':
            default:
                $this->connection = ssh2_connect($this->server, $this->port);
                break;
        }

        if (!$this->connection)
            throw new \Exception('Could not connect to "'.$this->server.'" on port "'.$this->port.'".');

        return $this;
    }

    protected function disconnect() {
    }

    protected function delteFile($source) {
        unlink($this->getFilePath($source));

        return $this;
    }

    protected function deleteLocalFile() {
        return $this->delteFile('local');
    }

    protected function deleteRemoteFile() {
        return $this->delteFile('remote');
    }

    protected function downloadFile() {
        copy($this->remoteFile, $this->localFile);

        return $this;
    }

    protected function getFiles() {
        return array_keys(
            array_merge(
                array_flip($this->readLocalDirectory()),
                array_flip($this->readRemoteDirectory())
            )
        );  
    }

    protected function getFilePath($source) {
        switch ($source) {
            case 'local': return $this->localFile;
            case 'remote': return $this->remoteFile;
            default: throw new \Exception('invalid file source');
        }
    }

    protected function getFileHash($source) {
        return hash_file('md5', $this->getFilePath($source)) ;
    }

    protected function getFileTime($source, $format = null) {
        if (!file_exists($this->getFilePath($source)))
            return false;

        $filetime = filemtime($this->getFilePath($source));

        if ($format AND $filetime)
            return (new \DateTime())->setTimestamp($filetime)->format($format);

        return $filetime;
    }

    protected function getLocalFileHash(){
        return $this->getFileHash('local');
    }

    protected function getLocalFileTime($format = null) {
        return $this->getFileTime('local', $format);
    }

    protected function getRemoteFileHash(){
        return $this->getFileHash('remote');
    }

    protected function getRemoteFileTime($format = null) {
        return $this->getFileTime('remote', $format);
    }

    protected function fileExists($source) {
        return file_exists($this->getFilePath($source));
    }

    protected function fileExistsLocal() {
        return $this->fileExists('local');
    }

    protected function fileExistsRemote() {
        return $this->fileExists('remote');
    }

    protected function login() {
        if (!$this->connection)
            $this->connect();

        switch ($this->protocol) {
            case 'FTP':
                # code...
                break;
                        
            case 'SFTP':
            default:
                if (!ssh2_auth_password($this->connection, $this->user, $this->password) )  //  SSH key variant? 
                    throw new \Exception('Could not authenticate with username '.$username.' and password '.$password);

                $this->login = ssh2_sftp($this->connection);

                if (!$this->login)
                    throw new \Exception('Could not initialize SFTP subsystem.');

                break;
        }

        return $this;
    }

    protected function uploadFile() {
        copy($this->localFile, $this->remoteFile);

        return $this;
    }

    protected function readLocalDirectory() {
        return self::readDirectory($this->localDirectory);
    }

    protected function readFile($source) {
        return file_get_contents($this->getFilePath($source));
    }

    protected function readLocalFile() {
        return $this->readFile('local');
    }

    protected function readRemoteDirectory() {
        return self::readDirectory($this->remoteDirectory);
    }

    protected function readRemoteFile() {
        return $this->readFile('remote');
    }

    protected function setConnection() {
        $this->protocol     = $connection->protocol()
        $this->server       = $connection->server();
        $this->port         = $connection->port();
        $this->password     = $connection->password();
        $this->user         = $connection->user();

        return $this->connect();
    }

    protected function setLocalDirectory($dir) {
        $this->localDirectory = $this->localRootDirectory.$dir;

        return $this;
    }

    public function setLocalRootDirectory($dir) {
        $this->localRootDirectory = $dir;

        return $this;
    }

    protected function setRemoteDirectory($dir) {
        switch ($this->protocol) {
            case 'SFTP':
                $this->remoteDirectory = 'ssh2.sftp://'.$this->login.$dir;
                break;
            
            default:
                $this->remoteDirectory = $dir;
                break;
        }

        return $this;
    }

    protected static function readDirectory($dir) {
        $files = [];
        
        if (file_exists($dir) AND is_dir($dir)) {
            $currentDir = scandir($dir);

            foreach ($currentDir AS $entry) {
                if ($entry{0} == '.')
                    continue;

                if ($entry{0} == '~')
                    continue;

                if (is_dir($dir.$entry)) {
                    $entry .= '/';

                    foreach (self::readDirectory($dir.$entry) AS $subEntry)
                        $files[] = $entry.$subEntry;

                    continue;
                }

                $files[] = $entry;
            }
        }
        return $files;
    }
}